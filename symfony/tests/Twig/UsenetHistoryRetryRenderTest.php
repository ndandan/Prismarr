<?php

namespace App\Tests\Twig;

use App\Service\Media\Usenet\UsenetDownload;
use App\Service\Media\Usenet\UsenetStatus;
use App\Tests\AbstractWebTestCase;

/**
 * Failed Usenet history rows carry a Retry button (shared by the history
 * page and the downloads page's recent-history preview); other rows don't.
 */
class UsenetHistoryRetryRenderTest extends AbstractWebTestCase
{
    private function row(string $id, string $status): UsenetDownload
    {
        return new UsenetDownload(
            id: $id, name: 'Some.Release.' . $id, status: $status, rawStatus: $status,
            sizeBytes: 1000, remainingBytes: 0, percentage: 0.0, category: 'movies',
            etaSeconds: null, speedBytes: 0,
            failMessage: $status === UsenetStatus::FAILED ? 'Aborted, cannot be completed' : null,
            isHistory: true,
        );
    }

    private function render(array $items): string
    {
        return static::getContainer()->get('twig')->render('usenet/_history_rows.html.twig', [
            'items' => $items, 'client' => 'sabnzbd',
        ]);
    }

    public function testOnlyFailedRowsGetARetryButton(): void
    {
        $html = $this->render([
            $this->row('SABnzbd_nzo_failed', UsenetStatus::FAILED),
            $this->row('SABnzbd_nzo_done', UsenetStatus::COMPLETED),
        ]);

        self::assertSame(1, substr_count($html, 'data-usenet-retry'));
        self::assertStringContainsString('data-usenet-retry="SABnzbd_nzo_failed"', $html);
        self::assertStringContainsString('>Retry<', $html);
        self::assertStringContainsString('data-retry-url="/usenet/sabnzbd/history/SABnzbd_nzo_failed/retry"', $html);
    }

    public function testNoRetryButtonWithoutAFailedRow(): void
    {
        $html = $this->render([$this->row('SABnzbd_nzo_done', UsenetStatus::COMPLETED)]);

        self::assertStringNotContainsString('data-usenet-retry', $html);
    }
}
