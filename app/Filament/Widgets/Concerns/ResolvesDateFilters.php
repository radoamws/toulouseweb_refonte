<?php

namespace App\Filament\Widgets\Concerns;

use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Résout les filtres du dashboard (App\Filament\Pages\Dashboard, demande
 * client 12/09/2026 puis 16/09/2026) — partagé par les widgets de clics
 * (ClicksOverview, ClicksByTypeChart, TopClickedEntities) ET de vues de
 * page (PageViewsOverview, PageViewsByTypeChart, TopViewedEntities).
 *
 * Dates : repli sur les 30 derniers jours quand aucune n'est choisie, pour
 * ne rien changer au comportement par défaut pré-existant.
 *
 * Type/élément précis (demande client, 16/09/2026 : "ajoute toutes les
 * filtrages possible... filtre pour les stats par salle de cinéma, filtre
 * par news actifs, filtre par annonce" — voir TECHNICAL_DOCUMENTATION.md
 * §45) : `null` = aucune restriction (comportement identique à avant ce
 * filtre), une valeur choisie restreint TOUTES les requêtes des widgets
 * qui l'utilisent.
 */
trait ResolvesDateFilters
{
    use InteractsWithPageFilters;

    protected function filterFromDate(): Carbon
    {
        $from = $this->filters['from'] ?? null;

        return $from ? Carbon::parse($from)->startOfDay() : now()->subDays(29)->startOfDay();
    }

    protected function filterToDate(): Carbon
    {
        $to = $this->filters['to'] ?? null;

        return $to ? Carbon::parse($to)->endOfDay() : now();
    }

    protected function filterEntityType(): ?string
    {
        return $this->filters['entity_type'] ?? null;
    }

    protected function filterEntityId(): ?int
    {
        $id = $this->filters['entity_id'] ?? null;

        return $id ? (int) $id : null;
    }
}
