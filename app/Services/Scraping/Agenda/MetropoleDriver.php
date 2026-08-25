<?php

namespace App\Services\Scraping\Agenda;

use App\Services\Scraping\Agenda\Concerns\AbstractOpenAgendaDriver;

/**
 * Scraper agenda "Toulouse Métropole" (agenda mutualisé grand public,
 * agrégeant de nombreux lieux non couverts individuellement) — reconstruit
 * depuis le VRAI code legacy `updateAgendaforMetropole` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~4387).
 *
 * Source : même API publique OpenAgenda que ZenithDriver, agenda
 * `toulouse-metropole` — `https://openagenda.com/api/agendas/slug/toulouse-metropole/events`.
 *
 * area_slug par défaut résolu via `areas.legacy_id = 3806` : "Toulouse
 * Métropole" (slug `toulouse-metropole`).
 *
 * Catégorisation : le legacy (`checkCategoryMetropole()`) croise les facettes
 * `types-devenements` de la réponse OpenAgenda avec une table de mots-clés
 * français statique (spectacle, musique, sportif...) mappés vers des ids
 * `t_agenda_categories` codés en dur. Reproduit ici de façon équivalente mais
 * résolu par `legacy_id` (pas par id codé en dur) pour rester correct même si
 * la table catégories migrée change.
 */
class MetropoleDriver extends AbstractOpenAgendaDriver
{
    /**
     * Reprend fidèlement la table de correspondance mot-clé → id legacy de
     * `checkCategoryMetropole()`.
     */
    private const KEYWORD_TO_LEGACY_ID = [
        'spectacle' => 7,
        'musique' => 20,
        'stage' => 11,
        'atelier' => 21,
        'fete' => 18,
        'festival' => 8,
        'exposition' => 5,
        'tous' => 18,
        'visite' => 16,
        'evenement' => 18,
        'sportif' => 6,
        'conference' => 10,
        'reunion' => 10,
        'publique' => 18,
        'foire' => 14,
        'marches' => 14,
        'cinema' => 18,
        'projection' => 18,
        'congres' => 18,
        'salon' => 5,
    ];

    protected function defaultOpenAgendaSlug(): string
    {
        return 'toulouse-metropole';
    }

    protected function defaultAreaSlug(): string
    {
        return 'toulouse-metropole';
    }

    protected function resolveCategoryIds(array $event, array $payload): array
    {
        $typeIds = $event['types-devenements'] ?? [];
        $aggregations = $payload['aggregations']['types-devenements'] ?? [];

        if (! is_array($typeIds) || ! $typeIds || ! is_array($aggregations)) {
            $fallback = $this->categoryByLegacyId(18);

            return $fallback ? [$fallback->id] : [];
        }

        $labels = collect($aggregations)
            ->filter(fn (array $item) => isset($item['id']) && in_array($item['id'], $typeIds, true))
            ->flatMap(fn (array $item) => explode('-', $item['value'] ?? ''))
            ->map(fn (string $word) => \Illuminate\Support\Str::slug($word))
            ->filter();

        $legacyIds = $labels
            ->map(fn (string $word) => self::KEYWORD_TO_LEGACY_ID[$word] ?? null)
            ->filter()
            ->unique();

        if ($legacyIds->isEmpty()) {
            $fallback = $this->categoryByLegacyId(18);

            return $fallback ? [$fallback->id] : [];
        }

        return $legacyIds
            ->map(fn (int $legacyId) => $this->categoryByLegacyId($legacyId)?->id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
