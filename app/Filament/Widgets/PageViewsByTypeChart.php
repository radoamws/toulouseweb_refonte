<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDateFilters;
use App\Services\Stats\EntityLabelResolver;
use App\Services\Stats\PageViewService;
use Filament\Widgets\BarChartWidget;

/**
 * Répartition des VUES de page par type d'entité (demande client,
 * 15/09/2026 — voir ClicksByTypeChart, la même chose pour les clics).
 */
class PageViewsByTypeChart extends BarChartWidget
{
    use ResolvesDateFilters;

    // Voir ClicksOverview::$isLazy pour l'explication.
    protected static bool $isLazy = false;

    protected static ?string $heading = 'Vues de page par type';

    protected static ?int $sort = 5;

    protected function getData(): array
    {
        $totals = app(PageViewService::class)->totalsByType(
            $this->filterFromDate(), $this->filterToDate(), $this->filterEntityType(), $this->filterEntityId(),
        );
        $resolver = app(EntityLabelResolver::class);

        return [
            'datasets' => [[
                'label' => 'Vues',
                'data' => array_values($totals),
                'backgroundColor' => '#0ea5e9',
            ]],
            'labels' => array_map(fn (string $type) => $resolver->typeLabel($type), array_keys($totals)),
        ];
    }
}
