<?php

namespace App\Tests\Controller;

use App\Repository\SettingRepository;
use App\Service\ConfigService;
use App\Tests\AbstractWebTestCase;

/**
 * The two optional UniFi "plan speed" fields scale the topology map's
 * internet link against what the owner actually pays for. They are free
 * text (the map's reader parses them and treats blank / non-numeric / <= 0
 * as "no plan"), so the settings page only has to render, persist and clear
 * them verbatim.
 */
final class AdminSettingsUnifiPlanSpeedTest extends AbstractWebTestCase
{
    private const DOWN = 'unifi_plan_down_mbps';
    private const UP   = 'unifi_plan_up_mbps';

    /** Raw stored value, bypassing every cache layer. */
    private function stored(string $name): ?string
    {
        $this->em()->clear();
        return static::getContainer()->get(SettingRepository::class)->get($name);
    }

    /** What the rest of the app (the map reader) sees. */
    private function configured(string $name): ?string
    {
        $config = static::getContainer()->get(ConfigService::class);
        $config->invalidate();
        return $config->get($name);
    }

    public function testUnifiPlanSpeedFieldsRenderAndSave(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();

        $down = $crawler->filter('input[name="' . self::DOWN . '"]');
        $up   = $crawler->filter('input[name="' . self::UP . '"]');
        self::assertCount(1, $down, 'plan download input is rendered');
        self::assertCount(1, $up, 'plan upload input is rendered');
        self::assertSame('text', $down->attr('type'), 'free text, not a number input');
        self::assertSame('e.g. 1000', $down->attr('placeholder'));
        self::assertSame('e.g. 40', $up->attr('placeholder'));
        // Optional → rendered with the explicit Clear button like the other clearable fields.
        self::assertCount(1, $crawler->filter('input[name="_clear_' . self::DOWN . '"]'));
        self::assertCount(1, $crawler->filter('input[name="_clear_' . self::UP . '"]'));

        // Nothing stored yet: untouched fields read as unset.
        self::assertNull($this->stored(self::DOWN));

        $form = $crawler->filter('form[action$="/admin/settings"]')->form();
        $form[self::DOWN] = '1000';
        $form[self::UP]   = '40';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/settings');

        self::assertSame('1000', $this->stored(self::DOWN));
        self::assertSame('40', $this->stored(self::UP));
        self::assertSame('1000', $this->configured(self::DOWN));
        self::assertSame('40', $this->configured(self::UP));

        // The saved values come back pre-filled on the next visit.
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertSame('1000', $crawler->filter('input[name="' . self::DOWN . '"]')->attr('value'));
        self::assertSame('40', $crawler->filter('input[name="' . self::UP . '"]')->attr('value'));

        // Blanking both and saving clears them: the row is kept with a NULL
        // value (saveSubmitted() maps '' → null), which ConfigService reads
        // as unset.
        $form = $crawler->filter('form[action$="/admin/settings"]')->form();
        $form[self::DOWN] = '';
        $form[self::UP]   = '';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/settings');

        self::assertNull($this->stored(self::DOWN));
        self::assertNull($this->stored(self::UP));
        self::assertNull($this->configured(self::DOWN));
        self::assertNull($this->configured(self::UP));
    }

    public function testLabelsAreTranslatedWithApostrophesIntact(): void
    {
        // The strings live in ICU files, where a stray apostrophe can swallow
        // text; pin the rendered copy in both shipped languages.
        $translator = static::getContainer()->get('translator');

        self::assertSame(
            "Internet plan download (Mbps, optional — scales the map's internet link)",
            $translator->trans('admin.field.unifi.plan_down', [], null, 'en'),
        );
        self::assertSame('Internet plan upload (Mbps, optional)', $translator->trans('admin.field.unifi.plan_up', [], null, 'en'));
        self::assertSame(
            "Débit descendant de l'abonnement (Mbit/s, facultatif — échelle du lien internet de la carte)",
            $translator->trans('admin.field.unifi.plan_down', [], null, 'fr'),
        );
        self::assertSame("Débit montant de l'abonnement (Mbit/s, facultatif)", $translator->trans('admin.field.unifi.plan_up', [], null, 'fr'));
    }

    public function testPlanSpeedFieldsDoNotAddATestButtonOverride(): void
    {
        // They never affect connectivity, so they must stay out of the
        // Test-connection whitelist and its client-side field map.
        $this->client->request('GET', '/admin/settings');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(1, preg_match('/var TEST_FIELDS = \{(.*?)\};/s', $html, $m), 'TEST_FIELDS map not found');
        self::assertStringNotContainsString(self::DOWN, $m[1]);
        self::assertStringNotContainsString(self::UP, $m[1]);
    }
}
