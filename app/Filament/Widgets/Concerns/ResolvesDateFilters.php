<?php

namespace App\Filament\Widgets\Concerns;

use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Résout la plage de dates du filtre du dashboard (App\Filament\Pages\Dashboard,
 * demande client 12/09/2026) — repli sur les 30 derniers jours quand aucune
 * date n'est choisie, pour ne rien changer au comportement par défaut
 * pré-existant. Partagé par les widgets de clics (ClicksOverview,
 * ClicksByTypeChart, TopClickedEntities) plutôt que dupliqué trois fois.
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
}
