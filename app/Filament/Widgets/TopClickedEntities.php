<?php

namespace App\Filament\Widgets;

use App\Services\Stats\ClickTrackingService;
use App\Services\Stats\EntityLabelResolver;
use Filament\Widgets\Widget;

/**
 * Top 10 des entités les plus cliquées, tous types confondus, sur 30 jours
 * (brief : statistiques étendues à chaque clic du site). Widget custom
 * plutôt que TableWidget : la requête agrégée (GROUP BY entity_type,
 * entity_id) ne correspond à aucun modèle Eloquent unique exploitable tel
 * quel par le composant Table de Filament.
 */
class TopClickedEntities extends Widget
{
    // Voir ClicksOverview::$isLazy pour l'explication.
    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.top-clicked-entities';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function getRows(): array
    {
        $resolver = app(EntityLabelResolver::class);

        return collect(app(ClickTrackingService::class)->topEntities(10, now()->subDays(29)->startOfDay(), now()))
            ->map(fn (array $row) => [
                'label' => $resolver->resolve($row['entity_type'], $row['entity_id']),
                'type_label' => $resolver->typeLabel($row['entity_type']),
                'total' => $row['total'],
            ])
            ->all();
    }
}
