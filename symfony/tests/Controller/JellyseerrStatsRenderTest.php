<?php

namespace App\Tests\Controller;

use App\Controller\SetupController;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\Media\JellyseerrClient;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seerr requests page stats row (batch 3): the "Completed" card from Seerr
 * v3's /request/count `completed`, shown only when Seerr reports it.
 * Own setup for the same reasons as JellyseerrIssuesRenderTest (the route
 * guard pings Seerr before the controller runs, so the stub must be in place
 * before the container builds the real client).
 */
#[AllowMockObjectsWithoutExpectations]
class JellyseerrStatsRenderTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @param callable(JellyseerrClient&MockObject): void $configure */
    private function boot(callable $configure): void
    {
        $this->client = static::createClient();
        static::getContainer()->get('cache.app')->clear();

        $em = static::getContainer()->get('doctrine')->getManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $seerr = $this->createMock(JellyseerrClient::class);
        $seerr->method('ping')->willReturn(true);
        $configure($seerr);
        static::getContainer()->set(JellyseerrClient::class, $seerr);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new User();
        $admin->setEmail('admin@test.local');
        $admin->setDisplayName('Admin Test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'admin-password'));
        $em->persist($admin);
        $em->persist(new Setting(SetupController::SETUP_DONE_KEY, '1'));
        $em->persist(new Setting('jellyseerr_url', 'http://seerr.test:5055'));
        $em->persist(new Setting('jellyseerr_api_key', 'k'));
        $em->flush();

        $this->client->loginUser($admin);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        if (static::$booted) {
            static::getContainer()->get('cache.app')->clear();
        }
        parent::tearDown();
    }

    /** @param array<string, int> $counts */
    private function seerrWith(array $counts): callable
    {
        return function (JellyseerrClient&MockObject $seerr) use ($counts): void {
            $seerr->method('getAbout')->willReturn(['version' => '3.5.2']);
            $seerr->method('getRequests')->willReturn(['pageInfo' => ['pages' => 1, 'results' => 0, 'page' => 1], 'results' => []]);
            $seerr->method('getRequestCount')->willReturn($counts);
        };
    }

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'english' => ['en'];
        yield 'french'  => ['fr'];
    }

    #[DataProvider('locales')]
    public function testTheCompletedCardShowsSeerrsCount(string $locale): void
    {
        $this->boot($this->seerrWith(['total' => 12, 'movie' => 8, 'tv' => 4, 'pending' => 1, 'approved' => 2, 'declined' => 0, 'processing' => 2, 'available' => 0, 'completed' => 9]));

        $this->client->request('GET', '/jellyseerr?_locale=' . $locale);
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        if (preg_match('/\bjellyseerr\.index\.[a-z_]+/', $html, $m)) {
            $this->fail(sprintf('Locale "%s": unresolved translation key "%s".', $locale, $m[0]));
        }
        $this->assertSelectorTextSame('[data-seerr-stat="completed"] .js-stat', '9');
    }

    public function testNoCompletedCardWhenSeerrDoesNotReportIt(): void
    {
        // Overseerr / Jellyseerr before the merge have no `completed` count.
        $this->boot($this->seerrWith(['total' => 3, 'movie' => 3, 'tv' => 0, 'pending' => 0, 'approved' => 3, 'declined' => 0, 'processing' => 1, 'available' => 2]));

        $this->client->request('GET', '/jellyseerr');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-seerr-stat="completed"]');
    }
}
