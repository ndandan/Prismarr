<?php

namespace App\Tests\Controller;

use App\Service\Media\TracearrClient;
use App\Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tracearr open-violations panel and per-user violation counts (batch 3).
 * The fragments render the real templates with a stubbed client.
 */
#[AllowMockObjectsWithoutExpectations]
class TracearrViolationsRenderTest extends AbstractWebTestCase
{
    /** @param list<array<string, mixed>>|null $violations @param list<array<string, mixed>> $users */
    private function stub(?array $violations, array $users = []): void
    {
        $tracearr = $this->createMock(TracearrClient::class);
        $tracearr->method('getViolations')->willReturn($violations);
        $tracearr->method('getUsers')->willReturn($users);
        static::getContainer()->set(TracearrClient::class, $tracearr);
    }

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'english' => ['en'];
        yield 'french'  => ['fr'];
    }

    #[DataProvider('locales')]
    public function testListsOpenViolationsWithTheirSeverity(string $locale): void
    {
        $this->stub([
            ['severity' => 'high', 'rule' => 'Concurrent streams', 'user' => 'Carol', 'server' => 'Plex', 'at' => time() - 3600],
            ['severity' => 'low', 'rule' => null, 'user' => null, 'server' => null, 'at' => 0],
        ]);

        $this->client->request('GET', '/tracearr/api/violations?_locale=' . $locale);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        if (preg_match('/\btracearr\.[a-z_.]+/', $html, $m)) {
            self::fail(sprintf('Locale "%s": unresolved translation key "%s".', $locale, $m[0]));
        }
        self::assertSelectorCount(2, '[data-tracearr-violation]');
        self::assertSelectorExists('[data-tracearr-violation="high"] .badge.bg-danger-lt');
        self::assertSelectorExists('[data-tracearr-violation="low"] .badge.bg-secondary-lt');
        self::assertStringContainsString('Concurrent streams', $html);
        self::assertStringContainsString('Carol', $html);
    }

    public function testViolationTextIsEscaped(): void
    {
        $this->stub([['severity' => 'warning', 'rule' => '<script>x</script>', 'user' => '<b>u</b>', 'server' => 'P', 'at' => 0]]);

        $this->client->request('GET', '/tracearr/api/violations');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringNotContainsString('<b>u</b>', $html);
    }

    public function testNoOpenViolationsShowsTheEmptyState(): void
    {
        $this->stub([]);

        $this->client->request('GET', '/tracearr/api/violations');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-tracearr-violations-empty]');
    }

    public function testATracearrWithoutViolationsSupportRendersNothing(): void
    {
        $this->stub(null);

        $this->client->request('GET', '/tracearr/api/violations');

        self::assertResponseIsSuccessful();
        self::assertSame('', trim((string) $this->client->getResponse()->getContent()));
    }

    public function testUsersShowTheirViolationCount(): void
    {
        $this->stub(null, [
            ['displayName' => 'Carol', 'serverName' => 'Plex', 'lastSeen' => 0, 'plays' => 4, 'trustScore' => 70, 'violations' => 3],
            ['displayName' => 'Dan', 'serverName' => 'Plex', 'lastSeen' => 0, 'plays' => 1, 'trustScore' => 100, 'violations' => 0],
        ]);

        $this->client->request('GET', '/tracearr/api/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '[data-tracearr-user-violations]');
        self::assertSelectorTextContains('[data-tracearr-user-violations]', '3');
        // The app ships inline SVG icons, not the Tabler icon font.
        self::assertSelectorExists('[data-tracearr-user-violations] svg');
    }
}
