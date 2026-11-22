<?php

namespace App\Tests\Controller;

use App\Controller\SetupController;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\Media\ProwlarrClient;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Review 2026-11-21 L1: the Prowlarr page puts its indexers into an inline
 * script with json_encode, which (unlike JSON_HEX_TAG) leaves `<` alone. An
 * indexer or category named `<!--<script` then switches the HTML parser into
 * the "script data escaped" state and breaks the page's script.
 */
#[AllowMockObjectsWithoutExpectations]
class ProwlarrSearchIndexersEscapingTest extends WebTestCase
{
    public function testIndexerNamesCannotBreakTheInlineScript(): void
    {
        $client = static::createClient();
        static::getContainer()->get('cache.app')->clear();

        $em = static::getContainer()->get('doctrine')->getManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->method('ping')->willReturn(true);
        $prowlarr->method('getSystemStatus')->willReturn(['version' => '1.30.0']);
        $prowlarr->method('getIndexers')->willReturn([[
            'id' => 1, 'name' => 'Evil <!--<script>', 'enabled' => true, 'protocol' => 'torrent', 'privacy' => 'public',
            'definitionName' => 'evil', 'priority' => 25, 'added' => '2026-01-01T00:00:00Z', 'supportsRss' => true, 'supportsSearch' => true,
            'capabilities' => ['categories' => [['id' => 2000, 'name' => 'Movies <!--<script>', 'subCategories' => []]]],
        ]]);
        static::getContainer()->set(ProwlarrClient::class, $prowlarr);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new User();
        $admin->setEmail('admin@test.local');
        $admin->setDisplayName('Admin Test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'admin-password'));
        $em->persist($admin);
        $em->persist(new Setting(SetupController::SETUP_DONE_KEY, '1'));
        $em->persist(new Setting('prowlarr_url', 'http://prowlarr.test:9696'));
        $em->persist(new Setting('prowlarr_api_key', 'k'));
        $em->flush();
        $client->loginUser($admin);
        $client->disableReboot();

        $client->request('GET', '/prowlarr');
        $html = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('var PW_SEARCH_INDEXERS', $html);
        $this->assertStringNotContainsString('<!--<script', $html, 'no raw "<!--<script" anywhere, inline script included');
        // JSON_HEX_TAG writes "<" as backslash-u003C.
        $escaped = str_replace('LT', chr(92) . 'u003C', 'LT!--LTscript');
        $this->assertStringContainsString($escaped, $html, 'the name still reaches the script, hex-escaped');
    }
}
