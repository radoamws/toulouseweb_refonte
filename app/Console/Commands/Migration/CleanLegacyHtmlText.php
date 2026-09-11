<?php

namespace App\Console\Commands\Migration;

use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nettoie le HTML/entités legacy resté dans des colonnes censées être du
 * texte brut (demande client, 11/09/2026 — capture d'écran d'une fiche
 * annuaire affichant littéralement `<p>- D&eacute;pannage...<br />...</p>`).
 *
 * Cause : ces colonnes viennent d'un éditeur WYSIWYG legacy qui stockait du
 * HTML + entités HTML directement, mais sont rendues ici soit via `{{ }}`
 * (échappement Blade — un vrai `<p>` devient le texte littéral "<p>"), soit
 * via `nl2br(e($valeur))` (`e()` échappe les caractères spéciaux mais ne
 * décode PAS les entités — `&eacute;` reste "&eacute;" à l'écran).
 *
 * Corrigé à la SOURCE (en base) plutôt que dans chaque vue qui l'affiche :
 * un seul passage bénéficie à la fois à l'affichage public ET aux
 * meta-descriptions SEO (App\Services\Seo\SeoResolverService::generateDescription()
 * utilise déjà `short_description`/`excerpt`/`description` telles quelles)
 * ET à l'admin (qui affichait, lui aussi, du HTML brut dans un simple champ
 * texte). Contrairement à `Listing::cleanPhone()` (données réellement
 * ambiguës, gardées en base pour relecture admin), il n'y a ici aucune
 * ambiguïté : personne ne veut de balises/entités littérales dans un champ
 * texte, une réécriture directe est donc appropriée.
 *
 * `News.body` et `Classified.description` ne sont PAS concernés :
 * `Classified.description` n'a jamais eu ce problème (vérifié, 0 ligne
 * affectée) ; `News.body` est déjà rendu en HTML brut non échappé
 * (`{!! $news->body !!}`, actualites/show.blade.php) — les entités qu'il
 * contient s'y décodent déjà correctement nativement par le navigateur.
 *
 * Écrit directement via le query builder (PAS Eloquent `->save()`) pour ne
 * JAMAIS déclencher les observers de ces modèles (CloudflarePurgeObserver,
 * GoogleIndexingObserver, RegeneratesSitemapObserver) — sur ~10 800 lignes
 * affectées au total, un `save()` en boucle aurait déclenché des milliers
 * d'appels HTTP sortants (purge Cloudflare, quota d'indexation Google épuisé
 * instantanément, régénérations de sitemap en rafale) pour une simple
 * correction de texte, sans rapport avec le contenu réellement public.
 */
class CleanLegacyHtmlText extends Command
{
    protected $signature = 'content:clean-legacy-html {--dry-run : Affiche ce qui serait changé sans écrire en base}';

    protected $description = "Décode les entités HTML et retire les balises HTML des colonnes censées être du texte brut (annuaire, agenda, actualités)";

    /** @var array<int, array{table: string, column: string}> */
    protected const TARGETS = [
        ['table' => 'listings', 'column' => 'description'],
        ['table' => 'listings', 'column' => 'short_description'],
        ['table' => 'events', 'column' => 'description'],
        ['table' => 'news', 'column' => 'excerpt'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('clean-legacy-html-text');

        foreach (self::TARGETS as $target) {
            $this->cleanColumn($target['table'], $target['column'], $dryRun, $log);
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }

    protected function cleanColumn(string $table, string $column, bool $dryRun, MigrationLog $log): void
    {
        $changed = 0;
        $unchanged = 0;

        DB::table($table)
            ->select('id', $column)
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column, $dryRun, $log, &$changed, &$unchanged) {
                foreach ($rows as $row) {
                    $original = $row->{$column};
                    $cleaned = $this->cleanText($original);

                    if ($cleaned === $original) {
                        $unchanged++;

                        continue;
                    }

                    $changed++;

                    if (! $dryRun) {
                        DB::table($table)->where('id', $row->id)->update([$column => $cleaned]);
                    }
                }
            });

        $log->updated("{$table}.{$column} : {$changed} ligne(s) nettoyée(s), {$unchanged} déjà propre(s)".($dryRun ? ' [dry-run, rien écrit]' : ''));
        $this->line("{$table}.{$column} : {$changed} nettoyée(s) / {$unchanged} déjà propre(s)");
    }

    /**
     * Convertit les sauts structurels HTML en vrais retours à la ligne
     * AVANT de retirer les balises (sinon "- A -<br>- B -" recollerait en
     * "- A - - B -", perdant la mise en forme en liste que ces champs ont
     * presque toujours en legacy) — décode ensuite les entités HTML, puis
     * normalise les espaces/retours à la ligne laissés par le nettoyage.
     */
    protected function cleanText(string $value): string
    {
        $value = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
        $value = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n\n", $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[ \t]+\n/', "\n", $value) ?? $value;
        $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? $value;

        return trim($value);
    }
}
