<?php

namespace App\Services\Scraping\Agenda;

use App\Services\Scraping\Agenda\Concerns\AbstractArdeiSoftDriver;

/**
 * Scraper agenda pour L'Escale (Tournefeuille) — reconstruit depuis le VRAI
 * code legacy `updateAgendaforEscale` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~2885), voir le
 * docblock de AbstractArdeiSoftDriver pour le contexte complet (plateforme
 * Ardei-Soft/VEL, à tort jugée "trop obfusquée" lors d'une investigation live
 * précédente — le vrai code prouve le contraire).
 *
 * Catégorie legacy hardcodée à 7 (Spectacles) — pas de logique de mapping
 * dans le code source pour cette salle (contrairement à ArdeiDriver/
 * Cornebarrieu qui, lui, résout par thème).
 *
 * area_slug par défaut résolu via `areas.legacy_id = 3563` : "L'Escale"
 * (slug `lescale-2`).
 */
class EscaleDriver extends AbstractArdeiSoftDriver
{
    protected function defaultTownSlug(): string
    {
        return 'tournefeuille';
    }

    protected function defaultAreaSlug(): string
    {
        return 'lescale-2';
    }

    protected function defaultTarifsGroup(): int
    {
        return 3;
    }

    protected function resolveCategoryIds(array $spectacle, array $payload): array
    {
        $category = $this->categoryByLegacyId(7);

        return $category ? [$category->id] : [];
    }
}
