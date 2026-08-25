<?php

namespace App\Services\Scraping\Agenda\Concerns;

use App\Models\EventCategory;

/**
 * Résout une App\Models\EventCategory à partir d'un identifiant de catégorie
 * LEGACY (`t_agenda_categories.id`, ex. 4=Théâtre, 7=Spectacles, 18=Divers —
 * voir le code réel dans old/backEnd/.../AgendaController.php) via la colonne
 * `event_categories.legacy_id` — PAS un id codé en dur sur la table migrée,
 * qui ne coïncide pas forcément avec le legacy (confirmé néanmoins identique
 * pour la table catégories, voir TECHNICAL_DOCUMENTATION.md §13, mais on
 * reste défensif : le mapping peut diverger côté zones/`areas`).
 */
trait ResolvesCategory
{
    protected function categoryByLegacyId(?int $legacyId, string $fallbackSlug = 'divers'): ?EventCategory
    {
        if ($legacyId) {
            $category = EventCategory::where('legacy_id', $legacyId)->first();
            if ($category) {
                return $category;
            }
        }

        return EventCategory::where('slug', $fallbackSlug)->first();
    }

    /**
     * Reproduit `getCategIdByLikeLib()`/`getReverseCategIdByLikeLib()` du
     * legacy : cherche une catégorie dont le slug apparaît dans (ou contient)
     * le libellé fourni, sans dépendre d'ids legacy codés en dur.
     *
     * @return \Illuminate\Support\Collection<int, EventCategory>
     */
    protected function categoriesMatchingLabel(string $label, string $fallbackSlug = 'divers'): \Illuminate\Support\Collection
    {
        $normalized = \Illuminate\Support\Str::slug($label, ' ');

        $matches = EventCategory::all()->filter(function (EventCategory $category) use ($normalized) {
            $slugWords = str_replace('-', ' ', $category->slug);

            return $normalized !== '' && (
                str_contains($normalized, $slugWords) || str_contains($slugWords, $normalized)
            );
        });

        if ($matches->isEmpty()) {
            $fallback = EventCategory::where('slug', $fallbackSlug)->first();

            return $fallback ? collect([$fallback]) : collect();
        }

        return $matches->values();
    }
}
