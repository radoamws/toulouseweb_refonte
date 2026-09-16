<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDateFilters;
use App\Services\Stats\ClickTrackingService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vue d'ensemble des clics (brief : "statistiques dans l'admin... chaque
 * clic peu importe où doit être ajouté dans cette statistique"). Compte
 * TOUTES les entités suivies (bannière, catégorie, encadré/fiche, film,
 * événement, annonce...) — voir App\Services\Stats\ClickTrackingService.
 *
 * Les 3 premiers repères (aujourd'hui/7j/30j) restent des fenêtres FIXES,
 * indépendantes du filtre de dates du dashboard — ce sont des points de
 * repère habituels, pas ce que le filtre est censé faire varier. Le 4e
 * stat, lui, reflète la plage choisie dans le filtre (demande client,
 * 12/09/2026 — voir App\Filament\Pages\Dashboard et ResolvesDateFilters).
 *
 * Le filtre par type/élément précis (demande client, 16/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §45), lui, s'applique aux 4 cartes — dimension
 * différente du filtre de dates (quel contenu, pas quelle période).
 */
class ClicksOverview extends StatsOverviewWidget
{
    use ResolvesDateFilters;

    // Filament rend les widgets en lazy-load par défaut (contenu vide tant
    // qu'un observateur d'intersection JS ne déclenche pas un aller-retour
    // Livewire) — désactivé ici pour un affichage immédiat (et testable
    // côté serveur sans exécuter de JS, voir AdminDashboardStatsTest).
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $tracking = app(ClickTrackingService::class);
        $now = now();
        [$from, $to] = [$this->filterFromDate(), $this->filterToDate()];
        [$entityType, $entityId] = [$this->filterEntityType(), $this->filterEntityId()];

        return [
            Stat::make('Clics aujourd\'hui', $tracking->totalCount($now->copy()->startOfDay(), $now, $entityType, $entityId))
                ->description('Toutes entités confondues'),
            Stat::make('Clics — 7 derniers jours', $tracking->totalCount($now->copy()->subDays(6)->startOfDay(), $now, $entityType, $entityId)),
            Stat::make('Clics — 30 derniers jours', $tracking->totalCount($now->copy()->subDays(29)->startOfDay(), $now, $entityType, $entityId)),
            Stat::make('Clics — période filtrée', $tracking->totalCount($from, $to, $entityType, $entityId))
                ->description($from->format('d/m/Y').' → '.$to->format('d/m/Y')),
        ];
    }
}
