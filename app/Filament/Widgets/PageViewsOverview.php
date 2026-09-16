<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDateFilters;
use App\Services\Stats\PageViewService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vue d'ensemble des VUES de page (demande client, 15/09/2026 — voir
 * App\Services\Stats\PageViewService et TECHNICAL_DOCUMENTATION.md §44).
 * Distinct de ClicksOverview : une vue est enregistrée à chaque affichage
 * serveur d'une page publique, qu'elle vienne d'un clic interne ou non
 * (résultat de recherche, lien partagé, favori...) — voir docblock
 * d'App\Models\PageView pour la différence avec App\Models\ClickEvent.
 * Même structure que ClicksOverview (3 fenêtres fixes + la plage filtrée).
 */
class PageViewsOverview extends StatsOverviewWidget
{
    use ResolvesDateFilters;

    // Voir ClicksOverview::$isLazy pour l'explication.
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        $views = app(PageViewService::class);
        $now = now();
        [$from, $to] = [$this->filterFromDate(), $this->filterToDate()];
        [$entityType, $entityId] = [$this->filterEntityType(), $this->filterEntityId()];

        return [
            Stat::make('Vues de page aujourd\'hui', $views->totalCount($now->copy()->startOfDay(), $now, $entityType, $entityId))
                ->description('Toutes pages confondues — inclut les visites directes (recherche, favoris...)'),
            Stat::make('Vues — 7 derniers jours', $views->totalCount($now->copy()->subDays(6)->startOfDay(), $now, $entityType, $entityId)),
            Stat::make('Vues — 30 derniers jours', $views->totalCount($now->copy()->subDays(29)->startOfDay(), $now, $entityType, $entityId)),
            Stat::make('Vues — période filtrée', $views->totalCount($from, $to, $entityType, $entityId))
                ->description($from->format('d/m/Y').' → '.$to->format('d/m/Y')),
        ];
    }
}
