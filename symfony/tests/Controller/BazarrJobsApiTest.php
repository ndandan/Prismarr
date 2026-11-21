<?php

namespace App\Tests\Controller;

use App\Controller\BazarrController;
use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrPosterResolver;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;

/**
 * Bazarr jobs chip (batch 2): GET /bazarr/api/jobs summarises Bazarr 1.6's
 * job queue for the chip next to the Bazarr nav — how many jobs run and
 * wait, and the running job's progress. `supported:false` (chip hidden) on
 * a Bazarr without the queue, or when it can't be read.
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrJobsApiTest extends TestCase
{
    /** @param list<array<string, mixed>>|null $jobs */
    private function summary(?array $jobs): array
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getJobs')->willReturn($jobs);

        $controller = new BazarrController(
            $client,
            $this->createMock(ConfigService::class),
            new NullLogger(),
            $this->createMock(BazarrSubtitleIndex::class),
            $this->createMock(ServiceInstanceProvider::class),
            $this->createMock(BazarrPosterResolver::class),
        );
        $controller->setContainer(new Container());

        return json_decode((string) $controller->apiJobs()->getContent(), true);
    }

    public function testSummarisesRunningAndPendingJobsWithProgress(): void
    {
        $data = $this->summary([
            ['job_id' => 1, 'job_name' => 'Syncing subtitles', 'status' => 'running', 'is_progress' => true, 'progress_value' => 2, 'progress_max' => 5, 'progress_message' => 'The Matrix'],
            ['job_id' => 2, 'job_name' => 'Search', 'status' => 'running', 'is_progress' => false, 'progress_value' => 0, 'progress_max' => 0, 'progress_message' => null],
            ['job_id' => 3, 'job_name' => 'Translate', 'status' => 'pending'],
            ['job_id' => 4, 'job_name' => 'Old', 'status' => 'completed'],
            ['job_id' => 5, 'job_name' => 'Broken', 'status' => 'failed'],
        ]);

        self::assertSame([
            'ok' => true, 'supported' => true, 'running' => 2, 'pending' => 1,
            'message' => 'Syncing subtitles: The Matrix', 'progress' => 40,
        ], $data);
    }

    public function testAnIdleQueueReportsZero(): void
    {
        $data = $this->summary([['job_id' => 4, 'job_name' => 'Old', 'status' => 'completed']]);

        self::assertSame(['ok' => true, 'supported' => true, 'running' => 0, 'pending' => 0, 'message' => null, 'progress' => null], $data);
    }

    public function testABazarrWithoutTheQueueIsUnsupported(): void
    {
        self::assertSame(['ok' => true, 'supported' => false, 'running' => 0, 'pending' => 0, 'message' => null, 'progress' => null], $this->summary(null));
    }
}
