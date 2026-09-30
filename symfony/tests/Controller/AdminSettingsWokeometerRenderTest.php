<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;

/**
 * Wokeometer admin settings card. Wokeometer is deliberately NOT one of the
 * probed services (no FIELDS / SERVICE_LABELS / groupings entry), so the card
 * is a standalone block: this asserts it actually renders, translated, with
 * its inputs, stats and both action buttons, and that the stored API key
 * only ever reaches the page inside the password input's value attribute.
 */
final class AdminSettingsWokeometerRenderTest extends AbstractWebTestCase
{
    private const KEY = 'wok_s3cretRenderKey123';

    private function seedKey(): void
    {
        $em = $this->em();
        $em->persist(new Setting('wokeometer_api_key', self::KEY));
        $em->flush();
    }

    public function testSettingsPageRendersTheWokeometerCard(): void
    {
        $this->seedKey();
        $crawler = $this->client->request('GET', '/admin/settings');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSelectorExists('#section-services [data-wokeometer-card]');
        $this->assertSelectorExists('form [data-wokeometer-card] input[type="password"][name="wokeometer_api_key"]');
        $this->assertSelectorExists('[data-wokeometer-card] button.field-clear[data-clear-for="wokeometer_api_key"]');
        $this->assertSelectorExists('[data-wokeometer-card] input[type="hidden"][name="_clear_wokeometer_api_key"]');
        $this->assertSelectorExists('[name="wokeometer_enabled"]');
        $this->assertSelectorExists('[name="wokeometer_auto_sync"]');
        $this->assertSelectorExists('button[type="button"][data-wokeometer-sync]');
        $this->assertSelectorExists('button[type="button"][data-wokeometer-sync-full]');

        foreach (['last_sync', 'last_status', 'last_run_requests', 'last_run_records', 'total_requests',
            'credits_remaining', 'cached_records', 'records_with_tmdb', 'matched_titles', 'next_due'] as $stat) {
            $this->assertSelectorExists('[data-wokeometer-stat="' . $stat . '"]', $stat);
        }

        $sync = $crawler->filter('[data-wokeometer-sync]');
        $this->assertSame('/admin/settings/wokeometer/sync', $sync->attr('data-url'));
        $this->assertSame('/admin/settings/wokeometer/state', $sync->attr('data-state-url'));
        $this->assertNotSame('', (string) $sync->attr('data-csrf'));
        $this->assertNull($sync->attr('disabled'), 'a configured + enabled card can sync');
    }

    public function testBothSwitchesDefaultToOnAndTheSyncButtonsNeedAKey(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNotNull($crawler->filter('input[name="wokeometer_enabled"]')->attr('checked'));
        $this->assertNotNull($crawler->filter('input[name="wokeometer_auto_sync"]')->attr('checked'));
        $this->assertNotNull($crawler->filter('[data-wokeometer-sync]')->attr('disabled'));
        $this->assertNotNull($crawler->filter('[data-wokeometer-sync-full]')->attr('disabled'));
    }

    public function testStoredOffSwitchRendersUnchecked(): void
    {
        $this->seedKey();
        $em = $this->em();
        $em->persist(new Setting('wokeometer_enabled', '0'));
        $em->persist(new Setting('wokeometer_auto_sync', '0'));
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/settings');

        $this->assertNull($crawler->filter('input[name="wokeometer_enabled"]')->attr('checked'));
        $this->assertNull($crawler->filter('input[name="wokeometer_auto_sync"]')->attr('checked'));
        $this->assertNotNull($crawler->filter('[data-wokeometer-sync]')->attr('disabled'), 'switched off = no manual sync');
    }

    public function testTheKeyOnlyAppearsInsideThePasswordInputValue(): void
    {
        $this->seedKey();
        $crawler = $this->client->request('GET', '/admin/settings');
        $html    = (string) $this->client->getResponse()->getContent();

        $this->assertSame(self::KEY, $crawler->filter('input[name="wokeometer_api_key"]')->attr('value'));

        // Remove every <input …> tag (value + placeholder live there): nothing
        // else on the page may carry the key or even the `wok_` prefix.
        $withoutInputs = (string) preg_replace('/<input\b[^>]*>/i', '', $html);
        $this->assertStringNotContainsString(self::KEY, $withoutInputs);
        $this->assertStringNotContainsString('wok_', $withoutInputs);
    }

    public function testCardIsTranslatedInEnglishAndFrench(): void
    {
        $this->seedKey();

        $this->client->request('GET', '/admin/settings');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('admin.wokeometer.', $html);
        $this->assertStringNotContainsString('admin.services.group.enrichment', $html);
        $this->assertStringContainsString('Metadata enrichment', $html);

        $this->client->request('GET', '/admin/settings?_locale=fr');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('admin.wokeometer.', $html);
        $this->assertStringNotContainsString('admin.services.group.enrichment', $html);
        $this->assertStringNotContainsString('Metadata enrichment', $html);
    }

    public function testCardLinksToTheDeveloperPageSafely(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        $link    = $crawler->filter('[data-wokeometer-card] a[href="https://wokeometer.app/account/developer"]');

        $this->assertCount(1, $link);
        $this->assertSame('_blank', $link->attr('target'));
        $this->assertStringContainsString('noopener', (string) $link->attr('rel'));
    }

    public function testWokeometerIsNotAProbedService(): void
    {
        $this->seedKey();
        $this->client->request('GET', '/admin/settings');

        // No Test button / generic service card for Wokeometer (probing would cost credits).
        $this->assertSelectorNotExists('.admin-settings-test[data-service="wokeometer"]');
        $this->assertSelectorNotExists('[data-service-card="wokeometer"]');
        $this->assertSelectorNotExists('[name="sidebar_visible_wokeometer"]');
    }

    public function testInvalidStatusHasALabelAndTheProviderMessageIsEscaped(): void
    {
        $this->seedKey();
        $db = $this->em()->getConnection();
        $db->executeStatement('INSERT OR IGNORE INTO wokeometer_sync_state (id) VALUES (1)');
        $db->executeStatement("UPDATE wokeometer_sync_state SET last_status = 'invalid', last_error_message = '<b>boom</b>', last_success_at = 1700000000, total_requests = 42");

        $crawler = $this->client->request('GET', '/admin/settings');
        $html    = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Unreadable API response', $crawler->filter('[data-wokeometer-stat="last_status"]')->text());
        $this->assertSame('42', trim($crawler->filter('[data-wokeometer-stat="total_requests"]')->text()));
        $this->assertStringContainsString('&lt;b&gt;boom&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>boom</b>', $html);
        $this->assertStringNotContainsString('admin.wokeometer.status.', $html);
    }

    public function testRenderDoesNotComputeMatchedTitlesAndTheScriptFetchesThemOnce(): void
    {
        $this->seedKey();
        $crawler = $this->client->request('GET', '/admin/settings');
        $html    = (string) $this->client->getResponse()->getContent();

        $this->assertSame('…', trim($crawler->filter('[data-wokeometer-stat="matched_titles"]')->text()));
        $this->assertSame('1', $crawler->filter('[data-wokeometer-card]')->attr('data-has-state'));
        $this->assertStringContainsString("fetch(btnSync.dataset.stateUrl + '?stats=1', GET_OPTS)", $html, 'one stats fetch on load');
        $this->assertStringNotContainsString('toLocaleString', $html, 'dates come pre-formatted from the server');
    }

    public function testHaltedAndRequestCapLabelsSayAutomaticSyncIsPausedAndSyncNowConfirms(): void
    {
        $this->seedKey();
        $db = $this->em()->getConnection();
        $db->executeStatement('INSERT OR IGNORE INTO wokeometer_sync_state (id) VALUES (1)');

        foreach (['halted' => 'Stopped by a safety guard', 'request_cap' => 'Request cap reached'] as $status => $text) {
            $db->executeStatement('UPDATE wokeometer_sync_state SET last_status = ?, last_run_requests = 600', [$status]);
            $crawler = $this->client->request('GET', '/admin/settings');
            $html    = (string) $this->client->getResponse()->getContent();

            $label = $crawler->filter('[data-wokeometer-stat="last_status"]')->text();
            $this->assertStringContainsString($text, $label, $status);
            $this->assertStringContainsString('automatic sync paused until you run Sync now', $label, $status);
            $this->assertSame($status, $crawler->filter('[data-wokeometer-card]')->attr('data-last-status'));
            $this->assertSame('600', $crawler->filter('[data-wokeometer-card]')->attr('data-last-run-requests'));
            // The confirm text is shipped to the script with a %n% slot for the request count.
            $this->assertStringContainsString('stopped as a safety measure after %n% requests', $html);
            $this->assertStringContainsString('confirmAfterHalt', $html);
        }
    }

    public function testARunningRowWithoutALiveLockReadsInterrupted(): void
    {
        $this->seedKey();
        $db = $this->em()->getConnection();
        $db->executeStatement('INSERT OR IGNORE INTO wokeometer_sync_state (id) VALUES (1)');
        $db->executeStatement("UPDATE wokeometer_sync_state SET last_status = 'running', lock_run_id = 'r', lock_heartbeat_at = ?", [time() - 7200]);

        $crawler = $this->client->request('GET', '/admin/settings');

        $this->assertSame('0', $crawler->filter('[data-wokeometer-card]')->attr('data-running'));
        $this->assertSame('Interrupted — will resume', trim($crawler->filter('[data-wokeometer-stat="last_status"]')->text()));
    }

    public function testInitialSyncHintShowsUntilTheFirstFullSyncCompletes(): void
    {
        $this->seedKey();
        $crawler = $this->client->request('GET', '/admin/settings');
        $hint    = $crawler->filter('[data-wokeometer-initial-hint]');
        $this->assertStringContainsString('Press Sync now to run the initial catalog sync', $hint->text());
        $this->assertStringNotContainsString('d-none', (string) $hint->attr('class'));

        $db = $this->em()->getConnection();
        $db->executeStatement('INSERT OR IGNORE INTO wokeometer_sync_state (id) VALUES (1)');
        $db->executeStatement('UPDATE wokeometer_sync_state SET full_sync_completed_at = 1700000000, last_success_at = 1700000000');
        $crawler = $this->client->request('GET', '/admin/settings');
        $this->assertStringContainsString('d-none', (string) $crawler->filter('[data-wokeometer-initial-hint]')->attr('class'));
    }

    public function testCostNoteSaysTheInitialSyncIsManualAndAutomaticSyncIsIncremental(): void
    {
        $this->client->request('GET', '/admin/settings');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('starts only when you press Sync now', $html);
        $this->assertStringContainsString('usually 2–60 requests a month', $html);
        $this->assertStringContainsString('Browsing never calls the API', $html);
    }

    public function testRunningStateDisablesBothButtonsAndFlagsThePoller(): void
    {
        $this->seedKey();
        $db = $this->em()->getConnection();
        $db->executeStatement('INSERT OR IGNORE INTO wokeometer_sync_state (id) VALUES (1)');
        $db->executeStatement('UPDATE wokeometer_sync_state SET lock_run_id = ?, lock_heartbeat_at = ?, run_mode = ?', ['run-1', time(), 'full']);

        $crawler = $this->client->request('GET', '/admin/settings');

        $this->assertSame('1', $crawler->filter('[data-wokeometer-card]')->attr('data-running'));
        $this->assertNotNull($crawler->filter('[data-wokeometer-sync]')->attr('disabled'));
        $this->assertNotNull($crawler->filter('[data-wokeometer-sync-full]')->attr('disabled'));
        $this->assertStringContainsString('Running', $crawler->filter('[data-wokeometer-stat="last_status"]')->text());
    }
}
