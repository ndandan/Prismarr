<?php

namespace App\Tests\Service\Wokeometer;

use App\Scheduler\WokeometerScheduleProvider;
use App\Service\HealthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Attribute\AsSchedule;

/**
 * Guards what Wokeometer registers — and, as importantly, what it must NOT
 * register. It is not a probed service: a health chip / toggle / ping would
 * spend paid credits on every poll (spec D11). The s6 run-file guard lives in
 * Docker\MessengerWorkerConsumesSchedulerTest and the zero-API-call source
 * guard in Controller\WokeometerControllerTest — not duplicated here.
 */
class WokeometerRegistrationGuardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testSyncMessageIsRoutedToTheAsyncTransport(): void
    {
        $yaml = $this->read('config/packages/messenger.yaml');
        self::assertMatchesRegularExpression(
            '/' . preg_quote('App\Message\SyncWokeometerCatalog', '/') . ':\s*async\b/',
            $yaml,
        );
    }

    public function testTickMessageIsNotRouted(): void
    {
        // The tick arrives on the scheduler transport; routing it would make
        // the web tier queue it and defeat the DB due-ness check.
        $yaml = $this->read('config/packages/messenger.yaml');
        self::assertDoesNotMatchRegularExpression('/WokeometerSyncTick\s*:/', $yaml);
    }

    public function testWokeometerIsNotAHealthProbedService(): void
    {
        self::assertNotContains('wokeometer', HealthService::TOGGLEABLE_SERVICES);

        $colors = (new \ReflectionClassConstant(HealthService::class, 'SERVICE_COLORS'))->getValue();
        self::assertIsArray($colors);
        self::assertArrayNotHasKey('wokeometer', $colors);

        $source = $this->read('src/Service/HealthService.php');
        $start  = strpos($source, 'public function chips(');
        self::assertNotFalse($start);
        $body = substr($source, $start, 2500);
        self::assertStringNotContainsStringIgnoringCase('wokeometer', $body, 'a health chip would ping the paid API');
    }

    public function testApiKeyIsNotARegularSettingsField(): void
    {
        // The key is handled by its own branch (empty = unchanged, excluded
        // from export); adding it to FIELDS would also break the PHPStan
        // baseline entry that enumerates the key union.
        $source = $this->read('src/Controller/AdminSettingsController.php');
        $start  = strpos($source, 'private const FIELDS = [');
        self::assertNotFalse($start);
        $end = strpos($source, "\n    ];", $start);
        self::assertNotFalse($end);
        self::assertStringNotContainsStringIgnoringCase('wokeometer', substr($source, $start, $end - $start));
    }

    public function testSettingsTemplateCarriesTheCardAndItsButtons(): void
    {
        $tpl = $this->read('templates/admin/settings.html.twig');
        foreach (['data-wokeometer-card', 'data-wokeometer-sync', 'data-wokeometer-sync-full'] as $hook) {
            self::assertStringContainsString($hook, $tpl, $hook);
        }
    }

    public function testIconExistsAndIsAttributed(): void
    {
        self::assertFileExists(self::ROOT . '/public/img/services/wokeometer.svg');
        self::assertStringContainsString('wokeometer.svg', $this->read('public/img/services/ATTRIBUTION.md'));
    }

    public function testScheduleProviderIsRegisteredAsWokeometer(): void
    {
        $attrs = (new \ReflectionClass(WokeometerScheduleProvider::class))->getAttributes(AsSchedule::class);
        self::assertCount(1, $attrs);
        self::assertSame('wokeometer', $attrs[0]->newInstance()->name);
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents(self::ROOT . '/' . $relative);
        self::assertNotFalse($contents, $relative);

        return $contents;
    }
}
