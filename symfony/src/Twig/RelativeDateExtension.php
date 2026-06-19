<?php

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `relative_date(epochSeconds)` → a translated "today / yesterday / 5 days ago"
 * label. The same buckets as DashboardController used; extracted so the shared
 * Plex history-row partial can format watch timestamps directly.
 */
final class RelativeDateExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function getFilters(): array
    {
        return [new TwigFilter('relative_date', [$this, 'format'])];
    }

    public function format(int|string|null $epoch): ?string
    {
        $epoch = (int) $epoch;
        if ($epoch <= 0) {
            return null;
        }
        $at  = (new \DateTimeImmutable())->setTimestamp($epoch);
        $now = new \DateTimeImmutable();
        [$key, $params] = self::bucket((int) $now->diff($at)->days);
        return $this->translator->trans($key, $params);
    }

    /**
     * Pure: day-difference → [translation key, params]. Mirrors the buckets in
     * DashboardController::relativeDate.
     *
     * @return array{0:string,1:array<string,int>}
     */
    public static function bucket(int $days): array
    {
        if ($days <= 0)  { return ['dashboard.relative.today', []]; }
        if ($days === 1) { return ['dashboard.relative.yesterday', []]; }
        if ($days < 7)   { return ['dashboard.relative.days_ago',   ['count' => $days]]; }
        if ($days < 30)  { return ['dashboard.relative.weeks_ago',  ['count' => (int) round($days / 7)]]; }
        if ($days < 365) { return ['dashboard.relative.months_ago', ['count' => (int) round($days / 30)]]; }
        return ['dashboard.relative.years_ago', ['count' => (int) round($days / 365)]];
    }
}
