<?php

namespace App\Services\Scraping\Agenda;

use App\Services\Scraping\Agenda\Concerns\AbstractArdeiSoftDriver;

/**
 * Scraper agenda pour L'Aria (Cornebarrieu) — reconstruit depuis le VRAI code
 * legacy `updateAgendaforArdei` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~4735), voir le
 * docblock de AbstractArdeiSoftDriver pour le contexte complet (plateforme
 * Ardei-Soft/VEL).
 *
 * Distinct de EscaleDriver (autre ville/salle sur la même plateforme
 * "SenousritPGI", d'où l'abstraction commune) : le nom "Ardei" dans le cron
 * client (`updateAgendaforArdei`) et dans les URLs (`ardei-soft.com`) désigne
 * ici l'éditeur de la plateforme de billetterie, pas un nom de salle — la
 * vraie salle migrée est "Aria" (Cornebarrieu).
 *
 * Catégorisation : le legacy résout un thème par spectacle
 * (`spectacle->t[0]` recherché dans `datas->themes[].t`, libellé récupéré
 * puis passé à `getCategIdByLib('Agenda ' . $theme, 18)`). Reproduit ici par
 * correspondance de libellé (voir `ResolvesCategory::categoriesMatchingLabel`)
 * plutôt que par recherche exacte d'un libellé "Agenda X" qui n'existe pas
 * dans la table catégories migrée.
 *
 * ⚠️ Horaire (06/09/2026, corrigé suite à l'audit §18 de
 * TECHNICAL_DOCUMENTATION.md) : le legacy affiche `"HH:MM"` zéro-complété
 * (`addZeroNumberToString`) depuis `dateD[3]:dateD[4]` — la date de DÉBUT,
 * contrairement à EscaleDriver qui utilise la date de fin (bizarrerie du
 * code source legacy reproduite telle quelle, chaque salle étant fidèle à
 * SA propre méthode d'origine) — silencieusement jamais reporté avant ce
 * correctif.
 *
 * area_slug par défaut résolu via `areas.legacy_id = 3708` : "Aria"
 * (slug `aria`).
 */
class ArdeiDriver extends AbstractArdeiSoftDriver
{
    protected function defaultTownSlug(): string
    {
        return 'cornebarrieu';
    }

    protected function defaultAreaSlug(): string
    {
        return 'aria';
    }

    protected function defaultTarifsGroup(): int
    {
        return 1;
    }

    protected function resolveCategoryIds(array $spectacle, array $payload): array
    {
        $themeId = $spectacle['t'][0] ?? null;
        $themes = $payload['themes'] ?? [];

        if ($themeId === null || ! is_array($themes)) {
            $fallback = $this->categoryByLegacyId(18);

            return $fallback ? [$fallback->id] : [];
        }

        $themeLabel = collect($themes)
            ->first(fn (array $theme) => in_array($themeId, $theme['t'] ?? [], true))['lbl'] ?? null;

        if (! $themeLabel) {
            $fallback = $this->categoryByLegacyId(18);

            return $fallback ? [$fallback->id] : [];
        }

        return $this->categoriesMatchingLabel($themeLabel)->pluck('id')->all();
    }

    protected function computeSchedule(array $spectacle): ?array
    {
        $dateD = $spectacle['dateD'] ?? null;
        if (! $dateD || ! isset($dateD[3], $dateD[4])) {
            return null;
        }

        return [sprintf('%02d:%02d', $dateD[3], $dateD[4])];
    }
}
