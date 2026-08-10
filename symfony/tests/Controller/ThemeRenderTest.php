<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;

final class ThemeRenderTest extends AbstractWebTestCase
{
    public function testClassicIsTheDefaultAndKeepsTheToggle(): void
    {
        $this->client->request('GET', '/tableau-de-bord');
        $html = $this->client->getResponse()->getContent();

        self::assertStringContainsString('data-bs-theme="dark"', $html);
        self::assertStringContainsString('id="theme-toggle-top"', $html);
        // #f4f6fb is classic light's hand-authored body background — unique to
        // classic mode, never produced by any preset's HSL-resolved output.
        self::assertStringContainsString('#f4f6fb', $html);
    }

    public function testExplicitPresetInjectsVariablesAndDropsToggle(): void
    {
        $em = $this->em();
        $em->persist(new Setting('display_theme', 'midnight'));
        $em->flush();

        $this->client->request('GET', '/tableau-de-bord');
        $html = $this->client->getResponse()->getContent();

        self::assertStringContainsString('--tblr-body-bg: hsl(0, 0%, 6.5%)', $html);
        self::assertStringContainsString('data-bs-theme="dark"', $html);
        self::assertStringNotContainsString('id="theme-toggle-top"', $html);
    }
}
