<?php

namespace App\Filament\Widgets;

use App\Services\Stats\ClickTrackingService;
use App\Services\Stats\EntityLabelResolver;
use Filament\Widgets\BarChartWidget;

/** Répartition des clics par type d'entité sur les 30 derniers jours (brief : stats étendues par type de clic). */
class ClicksByTypeChart extends BarChartWidget
{
    // Voir ClicksOverview::$isLazy pour l'explication.
    protected static bool $isLazy = false;

    protected static ?string $heading = 'Clics par type — 30 derniers jours';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $totals = app(ClickTrackingService::class)->totalsByType(now()->subDays(29)->startOfDay(), now());
        $resolver = app(EntityLabelResolver::class);

        return [
            'datasets' => [[
                'label' => 'Clics',
                'data' => array_values($totals),
                'backgroundColor' => '#d97706',
            ]],
            'labels' => array_map(fn (string $type) => $resolver->typeLabel($type), array_keys($totals)),
        ];
    }
}
