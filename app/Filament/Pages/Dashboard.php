<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/**
 * Dashboard admin avec filtrage par date (demande client, 12/09/2026) —
 * remplace `Filament\Pages\Dashboard` (générique, sans filtre) dans
 * `AdminPanelProvider`. Les widgets de clics (`ClicksByTypeChart`,
 * `TopClickedEntities`, et le 4e stat de `ClicksOverview`) lisent
 * `$this->filters['from']`/`['to']` via `InteractsWithPageFilters` — voir
 * TECHNICAL_DOCUMENTATION.md §33. Repli sur les 30 derniers jours quand
 * aucune date n'est choisie (comportement identique à avant ce correctif).
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')
                ->label('Du')
                ->native(false)
                ->displayFormat('d/m/Y'),
            DatePicker::make('to')
                ->label('Au')
                ->native(false)
                ->displayFormat('d/m/Y'),
        ]);
    }
}
