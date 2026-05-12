<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[AllowMockObjectsWithoutExpectations]
class QBittorrentClientTest extends TestCase
{
    /**
     * Issue #28 — qBittorrent 5.2.0 answers `204 No Content` on some Web API
     * endpoints; the runtime client used to demand exactly `200`, so the
     * Downloads page and the health badge said "unreachable" while the
     * connection test was green. Any 2xx must now count as success.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield '200 OK'             => [200, true];
        yield '201 Created'        => [201, true];
        yield '204 No Content'     => [204, true];  // the qBit 5.2.0 case
        yield '299 (top of 2xx)'   => [299, true];
        yield '0 (no connection)'  => [0, false];
        yield '301 Moved'          => [301, false];
        yield '401 Unauthorized'   => [401, false];
        yield '403 Forbidden'      => [403, false];
        yield '500 Server Error'   => [500, false];
    }

    #[DataProvider('statuses')]
    public function testIsOkStatusAcceptsTheWhole2xxRange(int $code, bool $expected): void
    {
        $config = $this->createMock(ConfigService::class);
        $client = new QBittorrentClient($config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter()));

        $m = (new \ReflectionClass($client))->getMethod('isOkStatus');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(null, $code));
    }
}
