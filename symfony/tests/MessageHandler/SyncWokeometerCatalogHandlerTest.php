<?php

namespace App\Tests\MessageHandler;

use App\Message\SyncWokeometerCatalog;
use App\MessageHandler\SyncWokeometerCatalogHandler;
use App\Service\Wokeometer\WokeometerChunkResult;
use App\Service\Wokeometer\WokeometerSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class SyncWokeometerCatalogHandlerTest extends TestCase
{
    /** @var list<Envelope> */
    private array $sent = [];

    private function bus(?\Throwable $throw = null): MessageBusInterface
    {
        return new class($this->sent, $throw) implements MessageBusInterface {
            /** @param list<Envelope> $sent */
            public function __construct(private array &$sent, private ?\Throwable $throw) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                $envelope = Envelope::wrap($message, $stamps);
                $this->sent[] = $envelope;
                return $envelope;
            }
        };
    }

    /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
    private function recordingLogger(array &$records): LoggerInterface
    {
        return new class($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    public function testStartMessageStartsARunAndDispatchesADelayedContinuation(): void
    {
        $sync = $this->createMock(WokeometerSyncService::class);
        $sync->expects($this->once())->method('start')->with('manual', true)->willReturn('run-1');
        $sync->expects($this->once())->method('runChunk')->with('run-1')
            ->willReturn(new WokeometerChunkResult(WokeometerChunkResult::CONTINUE, 17));

        (new SyncWokeometerCatalogHandler($sync, $this->bus(), new NullLogger()))(new SyncWokeometerCatalog(null, 'manual', true));

        $this->assertCount(1, $this->sent);
        $msg = $this->sent[0]->getMessage();
        $this->assertInstanceOf(SyncWokeometerCatalog::class, $msg);
        $this->assertSame('run-1', $msg->runId);
        $this->assertSame('manual', $msg->trigger);
        $this->assertTrue($msg->forceFull);
        $stamp = $this->sent[0]->last(DelayStamp::class);
        $this->assertInstanceOf(DelayStamp::class, $stamp);
        $this->assertSame(17_000, $stamp->getDelay());
    }

    public function testContinuationMessageRunsTheNextChunkWithoutStarting(): void
    {
        $sync = $this->createMock(WokeometerSyncService::class);
        $sync->expects($this->never())->method('start');
        $sync->expects($this->once())->method('runChunk')->with('run-9')
            ->willReturn(new WokeometerChunkResult(WokeometerChunkResult::DONE, 0));

        (new SyncWokeometerCatalogHandler($sync, $this->bus(), new NullLogger()))(new SyncWokeometerCatalog('run-9'));

        $this->assertSame([], $this->sent, 'done → no continuation');
    }

    public function testRefusedStartDoesNothing(): void
    {
        $sync = $this->createMock(WokeometerSyncService::class);
        $sync->expects($this->once())->method('start')->willReturn(null);
        $sync->expects($this->never())->method('runChunk');
        $records = [];

        (new SyncWokeometerCatalogHandler($sync, $this->bus(), $this->recordingLogger($records)))(new SyncWokeometerCatalog());

        $this->assertSame([], $this->sent);
        $this->assertSame([], $records, 'a locked/backoff/disabled start is silent');
    }

    public function testStoppedChunkDispatchesNothing(): void
    {
        $sync = $this->createMock(WokeometerSyncService::class);
        $sync->expects($this->once())->method('runChunk')
            ->willReturn(new WokeometerChunkResult(WokeometerChunkResult::STOPPED, 0, 'out_of_credits'));

        (new SyncWokeometerCatalogHandler($sync, $this->bus(), new NullLogger()))(new SyncWokeometerCatalog('run-1'));

        $this->assertSame([], $this->sent);
    }

    public function testContinuationDelayIsClampedToOneSecondThroughOneHour(): void
    {
        foreach ([[0, 1_000], [-5, 1_000], [90_000, 3_600_000], [2, 2_000]] as [$seconds, $expectedMs]) {
            $this->sent = [];
            $sync = $this->createStub(WokeometerSyncService::class);
            $sync->method('runChunk')->willReturn(new WokeometerChunkResult(WokeometerChunkResult::CONTINUE, $seconds));

            (new SyncWokeometerCatalogHandler($sync, $this->bus(), new NullLogger()))(new SyncWokeometerCatalog('run-1'));

            $stamp = $this->sent[0]->last(DelayStamp::class);
            $this->assertInstanceOf(DelayStamp::class, $stamp);
            $this->assertSame($expectedMs, $stamp->getDelay(), "delay $seconds s");
        }
    }

    public function testThrowablesAreSwallowedAndLoggedAsWarning(): void
    {
        $sync = $this->createStub(WokeometerSyncService::class);
        $sync->method('runChunk')->willThrowException(new \RuntimeException('secret wok_abc'));
        $records = [];

        // Must not throw: a throw would cost three async retries = re-billed pages.
        (new SyncWokeometerCatalogHandler($sync, $this->bus(), $this->recordingLogger($records)))(new SyncWokeometerCatalog('run-1'));

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertSame(['path', 'code', 'message'], array_keys($records[0]['context']));
        $this->assertSame('RuntimeException', $records[0]['context']['message']);
        $this->assertStringNotContainsString('wok_abc', (string) json_encode($records));
    }

    public function testDispatchFailureIsSwallowed(): void
    {
        $sync = $this->createStub(WokeometerSyncService::class);
        $sync->method('runChunk')->willReturn(new WokeometerChunkResult(WokeometerChunkResult::CONTINUE, 2));
        $records = [];

        (new SyncWokeometerCatalogHandler($sync, $this->bus(new \LogicException('transport down')), $this->recordingLogger($records)))(new SyncWokeometerCatalog('run-1'));

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
    }
}
