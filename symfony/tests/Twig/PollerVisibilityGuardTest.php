<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Source sentinels (PHPUnit runs no JavaScript): the three background
 * pollers that used to keep hitting Radarr / Sonarr / the Wokeometer state
 * endpoint from a hidden tab must pause on document.hidden, resume on
 * visibilitychange, and remove that listener in their page-lifecycle
 * teardown so Turbo navigations never stack listeners.
 */
class PollerVisibilityGuardTest extends TestCase
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../templates/';

    private function source(string $template): string
    {
        $src = file_get_contents(self::TEMPLATE_ROOT . $template);
        $this->assertNotFalse($src);

        return str_replace("\r\n", "\n", $src);
    }

    /** The brace-balanced block that opens at the first "{" at/after $from. */
    private function blockFrom(string $src, int $from): string
    {
        $open = strpos($src, '{', $from);
        $this->assertNotFalse($open, 'block opener not found');
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; ++$i) {
            if ('{' === $src[$i]) {
                ++$depth;
            } elseif ('}' === $src[$i] && 0 === --$depth) {
                return substr($src, $open, $i - $open + 1);
            }
        }
        $this->fail('unbalanced block');
    }

    /** The registerPageLifecycle(function () { ... }) body that contains $needle. */
    private function lifecycleBlockContaining(string $src, string $needle): string
    {
        $pos = strpos($src, $needle);
        $this->assertNotFalse($pos, 'poller call not found: ' . $needle);
        $start = strrpos(substr($src, 0, $pos), 'window.registerPageLifecycle(function () {');
        $this->assertNotFalse($start, 'poller is not inside registerPageLifecycle');

        return $this->blockFrom($src, $start);
    }

    private function assertPausesOnHidden(string $block, string $label): void
    {
        $this->assertStringContainsString('document.hidden', $block, $label . ': must check document.hidden');
        $this->assertStringContainsString("addEventListener('visibilitychange'", $block, $label . ': must resume on visibilitychange');
        $this->assertStringContainsString("removeEventListener('visibilitychange'", $block, $label . ': teardown must remove the listener');
    }

    public function testFilmsQueueRefreshPausesWhileTheTabIsHidden(): void
    {
        $block = $this->lifecycleBlockContaining($this->source('media/films.html.twig'), 'setInterval(refreshQueue, 2000)');
        $this->assertPausesOnHidden($block, 'films queue poll');
        $this->assertStringContainsString('clearInterval(queueRefreshTimer)', $block);
        // Becoming visible refreshes immediately instead of waiting a tick.
        $this->assertMatchesRegularExpression('/document\.hidden[\s\S]*else[\s\S]*refreshQueue\(\);/', $block);
    }

    public function testSeriesQueueAndMonitoredRefreshPauseWhileTheTabIsHidden(): void
    {
        $block = $this->lifecycleBlockContaining($this->source('media/series.html.twig'), 'setInterval(refreshQueue, 2000)');
        $this->assertPausesOnHidden($block, 'series queue poll');
        $this->assertStringContainsString('/series/queue/refresh', $block, 'the RefreshMonitoredDownloads POST must sit in the same pausable block');
        $this->assertStringContainsString('clearInterval(queueTimer)', $block);
        $this->assertStringContainsString('clearInterval(monitoredTimer)', $block);
        $this->assertMatchesRegularExpression('/document\.hidden[\s\S]*else[\s\S]*refreshQueue\(\);/', $block);
    }

    public function testSeriesWarningsRefreshPausesWhileTheTabIsHidden(): void
    {
        $block = $this->lifecycleBlockContaining($this->source('media/series.html.twig'), 'setInterval(refreshSeriesWarnings, 10000)');
        $this->assertPausesOnHidden($block, 'series warnings poll');
        $this->assertStringContainsString('clearInterval(warningsTimer)', $block);
        $this->assertMatchesRegularExpression('/document\.hidden[\s\S]*else[\s\S]*refreshSeriesWarnings\(\);/', $block);
    }

    public function testWokeometerPollDoesNotBurnItsCapWhileHidden(): void
    {
        $src = $this->source('admin/settings.html.twig');
        $start = strpos($src, 'function initWokeometerCard() {');
        $this->assertNotFalse($start);
        $block = $this->blockFrom($src, $start);
        $this->assertPausesOnHidden($block, 'wokeometer poll');

        // poll() must bail out before it bumps the counter when hidden.
        $poll = $this->blockFrom($block, (int) strpos($block, 'function poll('));
        $hidden = strpos($poll, 'document.hidden');
        $count = strpos($poll, 'polls++');
        $this->assertNotFalse($hidden, 'poll() must check document.hidden');
        $this->assertNotFalse($count);
        $this->assertLessThan($count, $hidden, 'hidden check must precede polls++');

        // The returned teardown (not just the helper) must drop the listener.
        $teardown = (int) strrpos($block, 'return function ()');
        $this->assertStringContainsString("removeEventListener('visibilitychange'", substr($block, $teardown));
    }
}
