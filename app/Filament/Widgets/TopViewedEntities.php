<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDateFilters;
use App\Services\Stats\EntityLabelResolver;
use App\Services\Stats\PageViewService;
use Filament\Widgets\Widget;

/**
 * Top 10 des pages les plus VUES, tous types confondus (demande client,
 * 15/09/2026 — voir TopClickedEntities, la même chose pour les clics).
 */
class TopViewedEntities extends Widget
{
    use ResolvesDateFilters;

    // Voir ClicksOverview::$isLazy pour l'explication.
    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.top-viewed-entities';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public function getRows(): array
    {
        $resolver = app(EntityLabelResolver::class);

        return collect(app(PageViewService::class)->topEntities(
            10, $this->filterFromDate(), $this->filterToDate(), $this->filterEntityType(), $this->filterEntityId(),
        ))
            ->map(fn (array $row) => [
                'label' => $resolver->resolve($row['entity_type'], $row['entity_id']),
                'type_label' => $resolver->typeLabel($row['entity_type']),
                'total' => $row['total'],
            ])
            ->all();
    }
}
