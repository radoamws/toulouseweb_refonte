<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Régénère `public/sitemap.xml` (demande client, TECHNICAL_DOCUMENTATION.md
 * §24) à chaque ajout/modif/suppression d'un contenu public depuis l'admin
 * — voir App\Observers\RegeneratesSitemapObserver.
 *
 * En file d'attente plutôt qu'exécuté en ligne dans la requête admin :
 * `sitemap:generate` parcourt potentiellement des dizaines de milliers de
 * lignes (films, événements...) — le faire en synchrone bloquerait chaque
 * sauvegarde admin le temps de la régénération complète. Traité par
 * `queue:work --stop-when-empty` (déjà planifié chaque minute, voir §11 —
 * décision d'architecture "pas de worker de queue permanent" sur
 * hébergement mutualisé, §14).
 *
 * `ShouldBeUnique` : si plusieurs sauvegardes admin se succèdent avant le
 * prochain passage de `queue:work`, une seule régénération réelle est
 * traitée (pas une par sauvegarde) — le job le plus récent "gagne", ce qui
 * est le comportement recherché puisque `sitemap:generate` reconstruit
 * TOUJOURS le fichier entier depuis l'état actuel de la base, jamais de
 * façon incrémentale.
 */
class RegenerateSitemap implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function uniqueId(): string
    {
        return 'regenerate-sitemap';
    }

    /** Fenêtre de déduplication (secondes) — largement au-delà de l'intervalle du cron `queue:work` (chaque minute). */
    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Throwable $e) {
            // Une régénération manquée n'est jamais bloquante : le prochain
            // ajout/modif/suppression (ou le cron `sitemap:generate` déjà
            // planifié en secours, voir §11) redéclenchera une tentative.
            Log::channel('single')->warning("RegenerateSitemap — échec : {$e->getMessage()}");
        }
    }
}
