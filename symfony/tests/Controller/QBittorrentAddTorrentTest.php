<?php

namespace App\Tests\Controller;

use App\Controller\QBittorrentController;
use App\Service\HostResolver;
use App\Service\Media\QBittorrentClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review 2026-11-21 L7 / L8: the add-by-URL route must answer a malformed
 * body with a translated 400, never a TypeError (500) or a hard-coded
 * French string. Like Deluge and Transmission, non-string fields are
 * treated as absent.
 */
#[AllowMockObjectsWithoutExpectations]
class QBittorrentAddTorrentTest extends TestCase
{
    /** @var list<array{string, ?string, ?string, bool}> */
    private array $added = [];

    protected function setUp(): void
    {
        $this->added = [];
        HostResolver::reset();
        HostResolver::setResolver(static fn(string $host): array => ['203.0.113.10']);
    }

    protected function tearDown(): void
    {
        HostResolver::reset();
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): array
    {
        $qbt = $this->createMock(QBittorrentClient::class);
        $qbt->method('addTorrentFromUrl')->willReturnCallback(function (string $urls, ?string $category, ?string $savepath, bool $paused): bool {
            $this->added[] = [$urls, $category, $savepath, $paused];
            return true;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = (new \ReflectionClass(QBittorrentController::class))->newInstanceWithoutConstructor();
        (function (QBittorrentClient $q, TranslatorInterface $t): void {
            $this->qbt = $q;
            $this->translator = $t;
        })->call($controller, $qbt, $translator);
        $controller->setContainer(new Container());

        $response = $controller->addTorrent(new Request(content: (string) json_encode($body)));

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    public function testAMissingUrlIsATranslated400(): void
    {
        [$code, $data] = $this->post([]);
        $this->assertSame(400, $code);
        $this->assertSame('qbittorrent.upload.missing_url', $data['error']);
    }

    public function testAnArrayOfUrlsIsA400NotATypeError(): void
    {
        [$code, $data] = $this->post(['urls' => ['https://tracker.example/a.torrent']]);
        $this->assertSame(400, $code);
        $this->assertSame('qbittorrent.upload.missing_url', $data['error']);
        $this->assertSame([], $this->added);
    }

    public function testNonStringCategoryAndSavePathAreIgnored(): void
    {
        [$code] = $this->post(['urls' => 'https://tracker.example/a.torrent', 'category' => ['x'], 'savepath' => 5]);
        $this->assertSame(200, $code);
        $this->assertSame([['https://tracker.example/a.torrent', null, null, false]], $this->added);
    }
}
