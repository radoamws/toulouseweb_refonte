<?php

namespace App\Console\Commands\Migration;

use App\Models\News;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Demande client, 02/10/2026 : "Verifie pour toutes les encodage HTML de
 * toutes les entités... en front, c'est pas propre de voir des balises HTML"
 * (exemple donné : une adresse annuaire — voir Listing::cleanAddress()/
 * Area::cleanAddress() pour ce cas, traité par accesseur d'affichage).
 *
 * `news.title` est un cas à part : contrairement à `address`/`phone`
 * (quelques points d'affichage), le TITRE apparaît dans des dizaines
 * d'endroits (h1, balise `<title>`, JSON-LD, fil d'Ariane, cartes
 * apparentées, attribut `title=` de la vidéo embarquée...) — menacer un
 * accesseur `clean_title` à travers TOUS ces points serait fragile (un seul
 * oubli suffit à laisser filtrer la version brute). Vu le volume minuscule
 * (5/plusieurs milliers de lignes `news`, constaté en direct le 02/10/2026),
 * correction DIRECTE en base, une seule fois, plutôt qu'un accesseur
 * permanent.
 *
 * ⚠️ Mise à jour en SQL brut (pas `News::update()`) : `News::getSlugOptions()`
 * ne désactive pas la régénération de slug sur update (comportement par
 * défaut de spatie/laravel-sluggable) — un simple `$news->update(['title'
 * => ...])` aurait changé le `slug`, donc l'URL publique déjà indexée de
 * ces articles, un effet de bord sans rapport avec cette correction.
 */
class StripHtmlFromNewsTitles extends Command
{
    protected $signature = 'content:strip-html-from-news-titles {--dry-run : Affiche ce qui serait corrigé sans rien modifier}';

    protected $description = 'Retire les balises HTML résiduelles des titres d\'actualités (legacy) — ne touche jamais au slug';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('strip-html-from-news-titles');

        $updated = 0;

        News::query()->select(['id', 'title'])->orderBy('id')->chunkById(200, function ($batch) use ($dryRun, $log, &$updated) {
            foreach ($batch as $news) {
                // Filtre sur une VRAIE balise avant toute chose : comparer
                // juste "stripHtml($title) !== $title" capte AUSSI de
                // simples espaces multiples (291 lignes constatées en base,
                // contre 5 avec une vraie balise) — hors de la portée de
                // cette commande, voir LegacyCleaner::containsHtmlTag().
                if (! LegacyCleaner::containsHtmlTag($news->title)) {
                    continue;
                }

                $cleaned = LegacyCleaner::stripHtml($news->title);

                if ($cleaned === null || $cleaned === $news->title) {
                    continue;
                }

                if ($dryRun) {
                    $log->skipped("[dry-run] #{$news->id} \"{$news->title}\" -> \"{$cleaned}\"");

                    continue;
                }

                DB::table('news')->where('id', $news->id)->update(['title' => $cleaned]);
                $updated++;
                $log->updated("#{$news->id} \"{$news->title}\" -> \"{$cleaned}\"");
            }
        });

        $this->info($dryRun ? 'Dry-run terminé — voir storage/logs/migration/strip-html-from-news-titles.log.' : "{$updated} titre(s) d'actualité corrigé(s).");
        $this->info($log->summary());

        return self::SUCCESS;
    }
}
