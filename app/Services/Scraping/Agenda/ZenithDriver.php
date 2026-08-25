<?php

namespace App\Services\Scraping\Agenda;

use App\Services\Scraping\Agenda\Concerns\AbstractOpenAgendaDriver;

/**
 * Scraper agenda pour le Zénith Toulouse Métropole — reconstruit à partir du
 * VRAI code legacy fourni par le client (`updateAgendaforZenith`, voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~988, daté
 * "NEW WEBSITE 2024/03/13" — une ancienne version HTML zenith-toulousemetropole.com
 * existe aussi dans le fichier, commentée "OLD WEBSITE", donc déjà abandonnée
 * côté legacy avant même cette refonte).
 *
 * Source : API publique OpenAgenda (aucun scraping HTML nécessaire) —
 * `https://openagenda.com/api/agendas/slug/zenith-toulouse-metropole/events`.
 * Le legacy attache systématiquement la catégorie 18 (Divers) — un ancien
 * mapping par "style" existait dans le code HTML mort ci-dessus mais n'est
 * plus utilisé par la version active.
 *
 * area_slug par défaut résolu via `areas.legacy_id = 37` (id_area legacy) :
 * "Le Zénith" (slug `le-zenith`).
 */
class ZenithDriver extends AbstractOpenAgendaDriver
{
    protected function defaultOpenAgendaSlug(): string
    {
        return 'zenith-toulouse-metropole';
    }

    protected function defaultAreaSlug(): string
    {
        return 'le-zenith';
    }
}
