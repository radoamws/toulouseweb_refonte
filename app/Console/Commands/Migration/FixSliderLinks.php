<?php

namespace App\Console\Commands\Migration;

use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige les sliders déjà migrés avant le correctif de `MigrateSliders`
 * (demande client, 11/09/2026 — capture d'écran d'un slide homepage
 * affichant littéralement l'URL cible comme titre, barrée en rouge par le
 * client, et non cliquable). Voir docblock de `MigrateSliders::handle()`
 * pour la cause : les opérateurs du back-office legacy saisissaient l'URL
 * directement dans le champ "titre" (`nom`) plutôt que dans le champ lien
 * dédié (`urlBillboard`), laissé vide dans la quasi-totalité des lignes.
 *
 * Pour chaque slider où `title` ressemble à une URL ET `link_url` est
 * vide : `link_url` devient cette URL (le slide devient enfin réellement
 * cliquable), `title` devient `client_name` (le vrai nom lisible, déjà
 * présent en base — ex. "Escale") ou, à défaut, le nom d'hôte de l'URL
 * (ex. "lescale-tournefeuille.fr"), ou en tout dernier recours "Slider #id".
 *
 * Les valeurs qui commencent par "http(s)://" mais ne ressemblent pas à une
 * vraie URL exploitable (ex. "https://SLB CONSULTING", "https://" seul —
 * quelques cas isolés, tous inactifs en pratique) sont volontairement
 * laissées telles quelles plutôt que de produire un lien cassé.
 *
 * Écrit via le query builder (PAS Eloquent `->save()`), même raison que
 * `CleanLegacyHtmlText` (§29) : `Slider` implémente `HasCloudflarePurgeUrls`
 * — un `save()` en boucle aurait déclenché une purge Cloudflare par ligne
 * pour une simple correction de données, pas un vrai changement de contenu.
 */
class FixSliderLinks extends Command
{
    protected $signature = 'content:fix-slider-links {--dry-run : Affiche ce qui serait changé sans écrire en base}';

    protected $description = "Corrige les sliders dont le titre contient en réalité l'URL cible (link_url vide) — bascule l'URL vers link_url et restaure un titre lisible";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('fix-slider-links');

        $fixed = 0;
        $skipped = 0;

        DB::table('sliders')
            ->select('id', 'title', 'link_url', 'client_name')
            ->whereNull('link_url')
            ->whereNotNull('title')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($dryRun, $log, &$fixed, &$skipped) {
                foreach ($rows as $row) {
                    if (! preg_match('/^https?:\/\/\S+\.\S+/i', (string) $row->title)) {
                        $skipped++;

                        continue; // titre normal, pas une URL — rien à corriger
                    }

                    $newLinkUrl = $row->title;
                    $newTitle = $row->client_name
                        ?: (parse_url($row->title, PHP_URL_HOST) ?: null)
                        ?: "Slider #{$row->id}";

                    $fixed++;
                    $log->updated("#{$row->id} : title \"{$row->title}\" -> link_url, nouveau titre \"{$newTitle}\"");

                    if (! $dryRun) {
                        DB::table('sliders')->where('id', $row->id)->update([
                            'link_url' => $newLinkUrl,
                            'title' => $newTitle,
                        ]);
                    }
                }
            });

        $this->line("{$fixed} slider(s) corrigé(s), {$skipped} déjà correct(s) ou hors périmètre".($dryRun ? ' [dry-run, rien écrit]' : ''));
        $this->info($log->summary());

        return self::SUCCESS;
    }
}
