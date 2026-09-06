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
 * ⚠️ Horaire (06/09/2026, corrigé suite à l'audit §18 de
 * TECHNICAL_DOCUMENTATION.md) : le legacy affiche `"à partir de {heure de
 * fin}:{minute de fin}"` (ligne ~2902, `dateF[3]:dateF[4]` — utilise bien la
 * date de FIN, pas de début, une bizarrerie du code source reproduite telle
 * quelle) — silencieusement jamais reporté avant ce correctif.
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

    protected function computeSchedule(array $spectacle): ?array
    {
        $dateF = $spectacle['dateF'] ?? null;
        if (! $dateF || ! isset($dateF[3], $dateF[4])) {
            return null;
        }

        return ["à partir de {$dateF[3]}:{$dateF[4]}"];
    }
}
