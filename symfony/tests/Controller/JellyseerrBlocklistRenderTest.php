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
 * Seerr blocklist page (batch 2): renders the real template with a stubbed
 * client, wires search/filter/page through to Seerr, and removes entries.
 * Own setup for the same reasons as JellyseerrIssuesRenderTest (the route
 * guard pings Seerr before the controller runs, so the stub must be in place
 * before the container builds the real client).
 */
#[AllowMockObjectsWithoutExpectations]
class JellyseerrBlocklistRenderTest extends WebTestCase
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

    /** @return array<string, mixed> */
    private static function entry(int $tmdbId, array $overrides = []): array
    {
        return array_replace([
            'id'              => $tmdbId,
            'mediaType'       => 'movie',
            'title'           => 'Movie ' . $tmdbId,
            'tmdbId'          => $tmdbId,
            'user'            => ['id' => 1, 'displayName' => 'Alice'],
            'createdAt'       => '2026-11-01T10:00:00.000Z',
            'blocklistedTags' => null,
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $results */
    private function seerrWith(array $results): callable
    {
        return function (JellyseerrClient&MockObject $seerr) use ($results): void {
            $seerr->method('getAbout')->willReturn(['version' => '3.5.2']);
            $seerr->method('getBlocklist')->willReturn([
                'pageInfo' => ['pages' => 1, 'pageSize' => 25, 'results' => count($results), 'page' => 1],
                'results'  => $results,
            ]);
        };
    }

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'english' => ['en'];
        yield 'french'  => ['fr'];
    }

    /** @return array<string, string> */
    private static function ajax(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    }

    #[DataProvider('locales')]
    public function testRendersEntriesWithARemoveButtonAndNoLeakedKeys(string $locale): void
    {
        $this->boot($this->seerrWith([
            self::entry(603, ['title' => 'The Matrix']),
            self::entry(1399, ['mediaType' => 'tv', 'title' => 'Game of Thrones', 'blocklistedTags' => '12,48', 'user' => ['username' => 'bob']]),
        ]));

        $this->client->request('GET', '/jellyseerr/blocklist?_locale=' . $locale);
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        if (preg_match('/\bjellyseerr\.(blocklist|nav)\.[a-z_]+/', $html, $m)) {
            $this->fail(sprintf('Locale "%s": unresolved translation key "%s".', $locale, $m[0]));
        }
        $this->assertStringContainsString('The Matrix', $html);
        $this->assertStringContainsString('Game of Thrones', $html);
        $this->assertStringContainsString('bob', $html);
        $this->assertSelectorExists('[data-blocklist-remove="603"][data-media-type="movie"]');
        $this->assertSelectorExists('[data-blocklist-remove="1399"][data-media-type="tv"]');
        $this->assertSelectorExists('a.btn[href="/jellyseerr/blocklist"]', 'the Seerr nav links the page');
    }

    public function testTitlesAreEscaped(): void
    {
        $this->boot($this->seerrWith([self::entry(1, ['title' => '<script>alert(1)</script>'])]));

        $this->client->request('GET', '/jellyseerr/blocklist');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testEmptyBlocklistShowsTheEmptyState(): void
    {
        $this->boot($this->seerrWith([]));

        $this->client->request('GET', '/jellyseerr/blocklist');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-blocklist-empty]');
        $this->assertSelectorNotExists('[data-blocklist-remove]');
    }

    public function testSearchFilterAndPageReachSeerr(): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr): void {
            $seerr->method('getAbout')->willReturn(['version' => '3.5.2']);
            $seerr->expects($this->once())->method('getBlocklist')
                ->with(25, 25, 'alien', 'blocklistedTags')
                ->willReturn(['pageInfo' => ['pages' => 3], 'results' => [self::entry(1)]]);
        });

        $this->client->request('GET', '/jellyseerr/blocklist?q=alien&filter=tags&page=2');

        $this->assertResponseIsSuccessful();
    }

    public function testUnknownFilterFallsBackToAll(): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr): void {
            $seerr->method('getAbout')->willReturn(['version' => '3.5.2']);
            $seerr->expects($this->once())->method('getBlocklist')
                ->with(25, 0, '', 'all')
                ->willReturn(['pageInfo' => ['pages' => 1], 'results' => []]);
        });

        $this->client->request('GET', '/jellyseerr/blocklist?filter=bogus');

        $this->assertResponseIsSuccessful();
    }

    public function testRemoveCallsSeerrWithTheMediaType(): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr): void {
            $seerr->expects($this->once())->method('removeFromBlocklist')->with(1399, 'tv')->willReturn(true);
        });

        $this->client->request('POST', '/jellyseerr/blocklist/1399/remove', server: self::ajax(), content: (string) json_encode(['mediaType' => 'tv']));

        $this->assertResponseIsSuccessful();
        $this->assertSame(['ok' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testRemoveRejectsAnUnknownMediaTypeWithoutCallingSeerr(): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr): void {
            $seerr->expects($this->never())->method('removeFromBlocklist');
        });

        $this->client->request('POST', '/jellyseerr/blocklist/5/remove', server: self::ajax(), content: (string) json_encode(['mediaType' => 'person']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUnreachableSeerrShowsTheServiceBanner(): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr): void {
            $seerr->method('getAbout')->willReturn(null);
            $seerr->expects($this->never())->method('getBlocklist');
        });

        $this->client->request('GET', '/jellyseerr/blocklist');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-blocklist-remove]');
    }
}
