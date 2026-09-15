<?php

namespace App\Services\Stats;

use App\Models\Category;
use App\Models\Cinema;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\PartnerSite;
use App\Models\ScreeningTime;
use App\Models\Slider;

/**
 * Résout un ("entity_type", "entity_id") de `click_events` vers un libellé
 * humain pour l'admin (widget "Top clics", brief : statistiques étendues à
 * chaque clic du site). Le type de clic est une chaîne libre côté frontend
 * (`data-track="type:id:contexte"`, voir track-click.js) — cette table de
 * correspondance doit être tenue à jour à chaque nouveau type de clic
 * suivi (voir TECHNICAL_DOCUMENTATION.md §13, section clics/dashboard).
 */
class EntityLabelResolver
{
    /** @var array<string, array{0: class-string, 1: string}> entity_type => [Modèle, colonne d'affichage] */
    private const MAP = [
        'listing' => [Listing::class, 'title'],
        'event' => [Event::class, 'title'],
        'movie' => [Movie::class, 'title'],
        'news' => [News::class, 'title'],
        'classified' => [Classified::class, 'title'],
        'category' => [Category::class, 'name'],
        'partner_site' => [PartnerSite::class, 'name'],
        'slider' => [Slider::class, 'title'],
        // Salles de cinéma (demande client, 15/09/2026 — voir
        // resources/views/cinema/index.blade.php et cinema/salle.blade.php).
        'cinema' => [Cinema::class, 'name'],
        // Pages "menu" de catégorie, alimentées par les vues de page
        // (demande client, 15/09/2026, voir App\Services\Stats\PageViewService
        // et TECHNICAL_DOCUMENTATION.md §44) — distinctes de `category`
        // (annuaire) : chaque module a sa propre table de catégories.
        'event_category' => [EventCategory::class, 'name'],
        'news_category' => [NewsCategory::class, 'name'],
        'classified_category' => [ClassifiedCategory::class, 'name'],
    ];

    public function resolve(string $entityType, int $entityId): string
    {
        // `screening_time` (clic "réserver" sur un horaire, voir
        // cinema/movie.blade.php et cinema/salle.blade.php) n'a pas de colonne
        // "titre" propre — cas à part, résolu via ses relations (film + salle).
        if ($entityType === 'screening_time') {
            $time = ScreeningTime::with('screening.movie', 'screening.cinema')->find($entityId);
            $movie = $time?->screening?->movie;
            $cinema = $time?->screening?->cinema;
            if ($movie) {
                return $movie->title.($cinema ? " — {$cinema->name}" : '');
            }

            return "Horaire #{$entityId}";
        }

        [$modelClass, $column] = self::MAP[$entityType] ?? [null, null];

        if ($modelClass) {
            // find() simple (pas withTrashed()) : tous les modèles listés ci-dessus
            // n'utilisent pas tous SoftDeletes (Movie/Category/PartnerSite/Slider
            // non, Listing/Event/Classified oui) — un repli sur le libellé
            // générique est acceptable si l'entité a été supprimée.
            $label = $modelClass::find($entityId)?->{$column} ?? null;
            if ($label) {
                return $label;
            }
        }

        return ucfirst($entityType)." #{$entityId}";
    }

    /** Libellé lisible du type lui-même (pour le graphique par type). */
    public function typeLabel(string $entityType): string
    {
        return match ($entityType) {
            'listing' => 'Fiches annuaire',
            'event' => 'Événements',
            'movie' => 'Films',
            'news' => 'Actualités',
            'classified' => 'Annonces',
            'category' => 'Catégories',
            'partner_site' => 'Sites partenaires',
            'slider' => 'Sliders / bannières',
            'cinema' => 'Salles de cinéma',
            'screening_time' => 'Horaires de séance',
            'event_category' => 'Catégories agenda',
            'news_category' => 'Catégories actualités',
            'classified_category' => 'Catégories annonces',
            'page' => 'Pages sans fiche associée (accueil...)',
            default => ucfirst($entityType),
        };
    }
}
