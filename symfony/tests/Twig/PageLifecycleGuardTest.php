<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit n'exécute aucun JavaScript : ce test ne prouve pas que le
 * teardown fonctionne, seulement que ces quatre pages sont bien passées
 * par le helper partagé au lieu de re-câbler un poller à la main. La
 * preuve réelle se fait au navigateur (cycle :beta).
 */
class PageLifecycleGuardTest extends TestCase
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../templates/';

    public function testHelperIsDefinedInBase(): void
    {
        self::assertStringContainsString(
            'window.registerPageLifecycle',
            file_get_contents(self::TEMPLATE_ROOT . 'base.html.twig'),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function convertedPages(): iterable
    {
        yield 'base'     => ['base.html.twig'];
        yield 'films'    => ['media/films.html.twig'];
        yield 'series'   => ['media/series.html.twig'];
        yield 'discover' => ['decouverte/index.html.twig'];
    }

    #[DataProvider('convertedPages')]
    public function testPageUsesTheSharedLifecycleHelper(string $template): void
    {
        self::assertStringContainsString(
            'registerPageLifecycle(',
            file_get_contents(self::TEMPLATE_ROOT . $template),
            sprintf('%s doit passer par le helper partagé', $template),
        );
    }

    /**
     * Verrou de non-régression : le poller de santé de base.html.twig ne doit
     * plus jamais revenir au pattern qui fuyait (un listener turbo:load
     * jamais retiré, empilé à chaque navigation).
     */
    public function testBaseNoLongerLeaksTheOldTurboLoadHealthPoller(): void
    {
        self::assertStringNotContainsString(
            "'turbo:load', fetchHealth",
            file_get_contents(self::TEMPLATE_ROOT . 'base.html.twig'),
        );
    }
}
