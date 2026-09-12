<?php

namespace App\Services\Newsletter;

use App\Models\Newsletter;

/**
 * Génère UN brouillon de newsletter résumant les changements d'une
 * exécution de scraping (agenda/théâtres ou cinéma — demande client,
 * 12/09/2026, voir TECHNICAL_DOCUMENTATION.md §36), plutôt qu'une ligne par
 * fiche créée/mise à jour : un scraping peut toucher des centaines de
 * lignes en une seule exécution (`scrape:cinema`/`scrape:events`, voir
 * App\Providers\AppServiceProvider::CLOUDFLARE_PURGE_MODELS pour un
 * problème de volume similaire déjà rencontré sur ces mêmes commandes) — un
 * email par fiche spammerait les abonnés et n'a jamais été demandé
 * explicitement par le client (contrairement à "chaque publication" pour
 * annuaire/actualités/annonces, qui reste un événement unitaire rare).
 *
 * Comme App\Observers\NewsletterDraftObserver : ne crée qu'un BROUILLON,
 * jamais d'envoi automatique.
 */
class ScrapingDigestBuilder
{
    /** @param array{created: int, updated: int} $totals */
    public static function build(string $entityLabel, string $sourceType, array $totals): ?Newsletter
    {
        if ($totals['created'] <= 0 && $totals['updated'] <= 0) {
            return null;
        }

        $parts = array_filter([
            $totals['created'] > 0 ? "{$totals['created']} nouveauté(s)" : null,
            $totals['updated'] > 0 ? "{$totals['updated']} mise(s) à jour" : null,
        ]);

        return Newsletter::create([
            'subject' => "ToulouseWeb — {$entityLabel} : ".implode(', ', $parts),
            'preview_text' => "Le point sur {$entityLabel} cette semaine sur ToulouseWeb.",
            'body_html' => view('emails.partials.scraping-digest', [
                'entityLabel' => $entityLabel,
                'totals' => $totals,
                'url' => url('/'),
            ])->render(),
            'status' => 'draft',
            'trigger_type' => 'scraping_digest',
        ]);
    }
}
