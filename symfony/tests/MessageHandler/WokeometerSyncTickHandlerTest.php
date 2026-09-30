<?php

namespace App\Tests\MessageHandler;

use App\Message\SyncWokeometerCatalog;
use App\Message\WokeometerSyncTick;
use App\MessageHandler\WokeometerSyncTickHandler;
use App\Service\Wokeometer\WokeometerSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class WokeometerSyncTickHandlerTest extends TestCase
{
    /** @var list<Envelope> */
    private array $sent = [];

    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $records = [];

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

    private function logger(): LoggerInterface
    {
        return new class($this->records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function sync(bool $due): WokeometerSyncService
    {
        $sync = $this->createMock(WokeometerSyncService::class);
        $sync->expects($this->once())->method('isDue')
            ->with($this->callback(fn(int $now) => abs($now - time()) < 5))
            ->willReturn($due);
        $sync->expects($this->never())->method('start');
        $sync->expects($this->never())->method('runChunk');
        return $sync;
    }

    public function testDueTickDispatchesAScheduledStart(): void
    {
        (new WokeometerSyncTickHandler($this->sync(true), $this->bus(), $this->logger()))(new WokeometerSyncTick());

        $this->assertCount(1, $this->sent);
        $msg = $this->sent[0]->getMessage();
        $this->assertInstanceOf(SyncWokeometerCatalog::class, $msg);
        $this->assertNull($msg->runId);
        $this->assertSame('schedule', $msg->trigger);
        $this->assertFalse($msg->forceFull);
        $this->assertNull($this->sent[0]->last(DelayStamp::class));
        $this->assertSame([], $this->records);
    }

    public function testNotDueTickIsASilentNoOp(): void
    {
        (new WokeometerSyncTickHandler($this->sync(false), $this->bus(), $this->logger()))(new WokeometerSyncTick());

        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->records);
    }

    public function testDispatchFailureIsLoggedNotThrown(): void
    {
        (new WokeometerSyncTickHandler($this->sync(true), $this->bus(new \RuntimeException('down')), $this->logger()))(new WokeometerSyncTick());

        $this->assertCount(1, $this->records);
        $this->assertSame('warning', $this->records[0]['level']);
        $this->assertSame(['path', 'code', 'message'], array_keys($this->records[0]['context']));
    }

    public function testIsDueFailureIsLoggedNotThrown(): void
    {
        $sync = $this->createStub(WokeometerSyncService::class);
        $sync->method('isDue')->willThrowException(new \RuntimeException('db locked'));

        (new WokeometerSyncTickHandler($sync, $this->bus(), $this->logger()))(new WokeometerSyncTick());

        $this->assertSame([], $this->sent);
        $this->assertCount(1, $this->records);
        $this->assertSame('warning', $this->records[0]['level']);
    }
}
