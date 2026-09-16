<?php

namespace App\Filament\Pages;

use App\Services\Stats\EntityLabelResolver;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
 *
 * Filtres par type/élément précis (demande client, 16/09/2026, "ajoute
 * toutes les filtrages possible... filtre pour les stats par salle de
 * cinéma, filtre par news actifs, filtre par annonce" — voir
 * TECHNICAL_DOCUMENTATION.md §45) : `entity_type` restreint TOUS les
 * widgets (clics et vues) à un seul type de contenu (ex. "Actualités"),
 * `entity_id` (n'apparaît qu'une fois un type choisi) restreint en plus à
 * UN élément précis de ce type (ex. une seule salle de cinéma nommée).
 * Lus par `App\Filament\Widgets\Concerns\ResolvesDateFilters::filterEntityType()`/
 * `filterEntityId()`, partagé par les 6 widgets clics/vues.
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
            Select::make('entity_type')
                ->label('Filtrer par type de contenu')
                ->placeholder('Tous les types')
                ->options(app(EntityLabelResolver::class)->typeOptions())
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('entity_id', null)),
            Select::make('entity_id')
                ->label('Filtrer par élément précis')
                ->placeholder('Tous les éléments de ce type')
                ->options(fn (Get $get) => filled($get('entity_type'))
                    ? app(EntityLabelResolver::class)->entityOptions($get('entity_type'))
                    : [])
                ->searchable()
                ->visible(fn (Get $get) => filled($get('entity_type'))),
        ]);
    }
}
