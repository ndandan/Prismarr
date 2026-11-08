<?php

namespace App\Tests\Controller;

use App\Controller\QBittorrentController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * DC-10 (review 2026-11-06): qBittorrent fetches whichever URL the add box
 * hands it. The literal metadata-host list missed the address's other
 * spellings; the validator now also runs the shared guard the Usenet add box
 * uses, which resolves the host. LAN stays allowed (private trackers).
 */
class QBittorrentUrlGuardTest extends TestCase
{

    private function validate(string $raw): ?string
    {
        $controller = (new \ReflectionClass(QBittorrentController::class))->newInstanceWithoutConstructor();
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        // The readonly property can only be initialised from the class's own scope.
        (function (TranslatorInterface $t): void { $this->translator = $t; })->call($controller, $translator);

        $m = new \ReflectionMethod(QBittorrentController::class, 'validateTorrentUrls');

        return $m->invoke($controller, $raw);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function cases(): iterable
    {
        yield 'magnet'             => ['magnet:?xt=urn:btih:' . str_repeat('a', 40), null];
        yield 'public tracker'     => ['https://tracker.example/a.torrent', null];
        yield 'lan'                => ['http://192.168.1.10:8080/a.torrent', null];
        yield 'file scheme'        => ['file:///etc/passwd', 'qbittorrent.upload.invalid_url'];
        yield 'literal metadata'   => ['http://169.254.169.254/latest/meta-data/', 'qbittorrent.upload.forbidden_host'];
        yield 'decimal ipv4'       => ['http://2852039166/', 'qbittorrent.upload.forbidden_host'];
        yield 'hex ipv4'           => ['http://0xa9.0xfe.0xa9.0xfe/', 'qbittorrent.upload.forbidden_host'];
        yield 'ipv4-mapped ipv6'   => ['http://[::ffff:a9fe:a9fe]/', 'qbittorrent.upload.forbidden_host'];
        yield 'trailing dot'       => ['http://169.254.169.254./', 'qbittorrent.upload.forbidden_host'];
        yield 'second line caught' => ["https://tracker.example/a.torrent\nhttp://2852039166/", 'qbittorrent.upload.forbidden_host'];
    }

    #[DataProvider('cases')]
    public function testValidateTorrentUrls(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->validate($raw));
    }
}
