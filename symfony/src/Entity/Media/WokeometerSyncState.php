<?php

namespace App\Entity\Media;

use App\Repository\Media\WokeometerSyncStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-row (id = 1) durable state of the Wokeometer catalog sync:
 * watermark, compare-and-set run lock, resumable cursor + idempotency key,
 * and the stats shown on the settings card.
 *
 * Deliberately NOT stored in `setting` (a settings export/import would carry
 * a foreign watermark or a stale lock onto another install) nor in
 * `cache.app` (wiped on every settings save). Read and written only through
 * raw DBAL in WokeometerSyncStateRepository; this mapping (and its
 * accessors) declares the schema for SchemaTool and must stay identical to
 * Version20260929000000 by hand.
 * All timestamps are INTEGER UTC epoch seconds.
 */
#[ORM\Entity(repositoryClass: WokeometerSyncStateRepository::class)]
#[ORM\Table(name: 'wokeometer_sync_state')]
class WokeometerSyncState
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column(nullable: true)]
    private ?int $watermark = null;

    #[ORM\Column(nullable: true)]
    private ?int $fullSyncCompletedAt = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $lockRunId = null;

    #[ORM\Column(nullable: true)]
    private ?int $lockHeartbeatAt = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $runMode = null; // 'full' | 'incremental'

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $runTrigger = null; // 'schedule' | 'manual'

    #[ORM\Column(nullable: true)]
    private ?int $runStartedAt = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $runPhase = null; // 'movie' | 'tv' | 'done'

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $runCursor = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $runIdempotencyKey = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $runRequests = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $runRecords = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $runTransientFailures = 0;

    #[ORM\Column(nullable: true)]
    private ?int $lastRunStartedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastRunFinishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastSuccessAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastErrorCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastRunRequests = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastRunRecords = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalRequests = 0;

    #[ORM\Column(nullable: true)]
    private ?int $creditsRemaining = null;

    #[ORM\Column(nullable: true)]
    private ?int $creditsRemainingAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $nextAttemptAfter = null;

    public function getId(): int { return $this->id; }

    public function getWatermark(): ?int { return $this->watermark; }
    public function setWatermark(?int $watermark): static { $this->watermark = $watermark; return $this; }

    public function getFullSyncCompletedAt(): ?int { return $this->fullSyncCompletedAt; }
    public function setFullSyncCompletedAt(?int $fullSyncCompletedAt): static { $this->fullSyncCompletedAt = $fullSyncCompletedAt; return $this; }

    public function getLockRunId(): ?string { return $this->lockRunId; }
    public function setLockRunId(?string $lockRunId): static { $this->lockRunId = $lockRunId; return $this; }

    public function getLockHeartbeatAt(): ?int { return $this->lockHeartbeatAt; }
    public function setLockHeartbeatAt(?int $lockHeartbeatAt): static { $this->lockHeartbeatAt = $lockHeartbeatAt; return $this; }

    public function getRunMode(): ?string { return $this->runMode; }
    public function setRunMode(?string $runMode): static { $this->runMode = $runMode; return $this; }

    public function getRunTrigger(): ?string { return $this->runTrigger; }
    public function setRunTrigger(?string $runTrigger): static { $this->runTrigger = $runTrigger; return $this; }

    public function getRunStartedAt(): ?int { return $this->runStartedAt; }
    public function setRunStartedAt(?int $runStartedAt): static { $this->runStartedAt = $runStartedAt; return $this; }

    public function getRunPhase(): ?string { return $this->runPhase; }
    public function setRunPhase(?string $runPhase): static { $this->runPhase = $runPhase; return $this; }

    public function getRunCursor(): ?string { return $this->runCursor; }
    public function setRunCursor(?string $runCursor): static { $this->runCursor = $runCursor; return $this; }

    public function getRunIdempotencyKey(): ?string { return $this->runIdempotencyKey; }
    public function setRunIdempotencyKey(?string $runIdempotencyKey): static { $this->runIdempotencyKey = $runIdempotencyKey; return $this; }

    public function getRunRequests(): int { return $this->runRequests; }
    public function setRunRequests(int $runRequests): static { $this->runRequests = $runRequests; return $this; }

    public function getRunRecords(): int { return $this->runRecords; }
    public function setRunRecords(int $runRecords): static { $this->runRecords = $runRecords; return $this; }

    public function getRunTransientFailures(): int { return $this->runTransientFailures; }
    public function setRunTransientFailures(int $runTransientFailures): static { $this->runTransientFailures = $runTransientFailures; return $this; }

    public function getLastRunStartedAt(): ?int { return $this->lastRunStartedAt; }
    public function setLastRunStartedAt(?int $lastRunStartedAt): static { $this->lastRunStartedAt = $lastRunStartedAt; return $this; }

    public function getLastRunFinishedAt(): ?int { return $this->lastRunFinishedAt; }
    public function setLastRunFinishedAt(?int $lastRunFinishedAt): static { $this->lastRunFinishedAt = $lastRunFinishedAt; return $this; }

    public function getLastSuccessAt(): ?int { return $this->lastSuccessAt; }
    public function setLastSuccessAt(?int $lastSuccessAt): static { $this->lastSuccessAt = $lastSuccessAt; return $this; }

    public function getLastStatus(): ?string { return $this->lastStatus; }
    public function setLastStatus(?string $lastStatus): static { $this->lastStatus = $lastStatus; return $this; }

    public function getLastErrorCode(): ?int { return $this->lastErrorCode; }
    public function setLastErrorCode(?int $lastErrorCode): static { $this->lastErrorCode = $lastErrorCode; return $this; }

    public function getLastErrorMessage(): ?string { return $this->lastErrorMessage; }
    public function setLastErrorMessage(?string $lastErrorMessage): static { $this->lastErrorMessage = $lastErrorMessage; return $this; }

    public function getLastRunRequests(): ?int { return $this->lastRunRequests; }
    public function setLastRunRequests(?int $lastRunRequests): static { $this->lastRunRequests = $lastRunRequests; return $this; }

    public function getLastRunRecords(): ?int { return $this->lastRunRecords; }
    public function setLastRunRecords(?int $lastRunRecords): static { $this->lastRunRecords = $lastRunRecords; return $this; }

    public function getTotalRequests(): int { return $this->totalRequests; }
    public function setTotalRequests(int $totalRequests): static { $this->totalRequests = $totalRequests; return $this; }

    public function getCreditsRemaining(): ?int { return $this->creditsRemaining; }
    public function setCreditsRemaining(?int $creditsRemaining): static { $this->creditsRemaining = $creditsRemaining; return $this; }

    public function getCreditsRemainingAt(): ?int { return $this->creditsRemainingAt; }
    public function setCreditsRemainingAt(?int $creditsRemainingAt): static { $this->creditsRemainingAt = $creditsRemainingAt; return $this; }

    public function getNextAttemptAfter(): ?int { return $this->nextAttemptAfter; }
    public function setNextAttemptAfter(?int $nextAttemptAfter): static { $this->nextAttemptAfter = $nextAttemptAfter; return $this; }
}
