<?php

namespace App\Console\Commands\Migration;

use App\Models\Redirect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 9 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : amorce des
 * redirections 301.
 *
 * IMPORTANT — portée volontairement limitée : ce que cette commande peut
 * garantir avec certitude, c'est la continuité de slug DÉJÀ enregistrée dans
 * la base (`slug_old` -> `slug`, quand un article/une news/un film/une
 * catégorie a changé de slug au sein même de l'ancien système). Elle NE
 * reconstruit PAS l'intégralité du mapping ancienne arborescence Nuxt/PHP3 ->
 * nouvelle arborescence : l'ancien `server/301.json` ne contenait que 5
 * règles et n'est pas exploitable comme référence exhaustive (voir audit
 * frontend, TECHNICAL_DOCUMENTATION.md §5). Un audit complet des anciennes
 * URLs (Google Search Console, logs serveur) sera nécessaire en Phase 11,
 * une fois les vraies routes publiques construites (`redirects:audit`,
 * déjà prévu dans le plan de cron §11).
 *
 * Les chemins NEW ci-dessous anticipent le schéma d'URL cible déjà utilisé
 * dans les vues (home.blade.php) — à ajuster si ce schéma change avant la
 * Phase 6+.
 */
class MigrateRedirects extends Command
{
    protected $signature = 'migrate:redirects';

    protected $description = 'Amorce les redirections 301 à partir de la continuité de slug déjà connue en base (slug_old -> slug)';

    protected const DOMAINS = [
        ['table' => 't_article', 'new_prefix' => '/annuaire/fiche'],
        ['table' => 't_news', 'new_prefix' => '/actualites'],
        ['table' => 't_cine_film', 'new_prefix' => '/cinema/films'],
        ['table' => 't_category', 'new_prefix' => '/annuaire'],
    ];

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;

        foreach (self::DOMAINS as $domain) {
            foreach (
                DB::connection('legacy')->table($domain['table'])
                    ->whereNotNull('slug_old')->whereNotNull('slug')
                    ->whereColumn('slug_old', '!=', 'slug')
                    ->where('slug_old', '!=', '')
                    ->get(['slug_old', 'slug']) as $row
            ) {
                $from = "{$domain['new_prefix']}/{$row->slug_old}";
                $to = "{$domain['new_prefix']}/{$row->slug}";
                if ($from === $to) {
                    $skipped++;

                    continue;
                }

                Redirect::updateOrCreate(
                    ['from_path' => $from],
                    ['to_path' => $to, 'status_code' => 301, 'is_active' => true]
                );
                $created++;
            }
        }

        // Patterns ponctuels connus, repris de l'ancien server/301.json /
        // redirects.js (voir audit frontend) — ceux qui restent pertinents
        // pour une refonte complète plutôt que spécifiques à la migration
        // Nuxt->PHP3 déjà obsolète.
        foreach ([
            '/accueil' => '/',
            '/index.php' => '/',
            '/index.html' => '/',
        ] as $from => $to) {
            Redirect::updateOrCreate(['from_path' => $from], ['to_path' => $to, 'status_code' => 301, 'is_active' => true]);
            $created++;
        }

        $this->info("Redirections créées/mises à jour : {$created} (slugs identiques ignorés : {$skipped})");
        $this->warn('Rappel : audit complet des anciennes URLs (Search Console/logs) à faire en Phase 11 une fois les routes publiques réelles en place.');

        return self::SUCCESS;
    }
}
