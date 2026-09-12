<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDateFilters;
use App\Services\Stats\ClickTrackingService;
use App\Services\Stats\EntityLabelResolver;
use Filament\Widgets\BarChartWidget;

/**
 * Répartition des clics par type d'entité (brief : stats étendues par type
 * de clic). Plage de dates pilotée par le filtre du dashboard (demande
 * client, 12/09/2026 — voir App\Filament\Pages\Dashboard et
 * ResolvesDateFilters), 30 derniers jours par défaut si non renseigné.
 */
class ClicksByTypeChart extends BarChartWidget
{
    use ResolvesDateFilters;

    // Voir ClicksOverview::$isLazy pour l'explication.
    protected static bool $isLazy = false;

    protected static ?string $heading = 'Clics par type';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $totals = app(ClickTrackingService::class)->totalsByType($this->filterFromDate(), $this->filterToDate());
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
