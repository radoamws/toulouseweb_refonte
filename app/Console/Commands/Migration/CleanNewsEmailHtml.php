<?php

namespace App\Console\Commands\Migration;

use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nettoie les articles d'actualité dont le contenu (`news.body`) est un
 * copier-coller brut d'email (demande client, audit UI/UX du 11/09/2026) —
 * vérifié en direct sur `/actualites/festival-de-comminges` : balises
 * `<table>`, styles inline (`font-family: Lato`, `max-width: 600px`),
 * classes générées par client mail (`x_ydp...`), attributs `data-ogsc`/
 * `data-olk-copy-source`. Une table figée à 600px dans un conteneur fluide
 * (`max-w-3xl`) provoque un débordement horizontal réel sur mobile.
 *
 * Distinct du nettoyage du §29 (`content:clean-legacy-html`, qui porte sur
 * `description`/`short_description`/`excerpt`, explicitement PAS `body` —
 * `body` est rendu en HTML brut non échappé, `{!! $news->body !!}`, donc un
 * simple décodage d'entités n'aurait rien réglé ici : le problème n'est pas
 * des entités mal affichées, mais de vraies balises structurelles d'email
 * (table/style) à retirer tout en gardant le texte).
 *
 * Portée mesurée : 16 articles publiés sur 233 (6,9 %) contiennent une
 * balise `<table>`. Reconstruit le contenu en texte simple, ré-enveloppé en
 * paragraphes `<p>` (le body reste un champ HTML pour les 217 autres
 * articles bien formés, non touchés ici).
 *
 * Écrit via le query builder (PAS Eloquent `->save()`) — `News` implémente
 * `HasCloudflarePurgeUrls`/`HasGoogleIndexingUrl`, même raison qu'au §29.
 */
class CleanNewsEmailHtml extends Command
{
    protected $signature = 'content:clean-news-email-html {--dry-run : Affiche ce qui serait changé sans écrire en base}';

    protected $description = "Nettoie les articles d'actualité dont le corps est un copier-coller brut d'email (balises <table>, styles inline)";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('clean-news-email-html');

        $rows = DB::table('news')->select('id', 'title', 'body')
            ->where('body', 'like', '%<table%')
            ->get();

        foreach ($rows as $row) {
            $cleaned = $this->cleanEmailHtml($row->body);

            $log->updated("#{$row->id} ({$row->title})");

            if (! $dryRun) {
                DB::table('news')->where('id', $row->id)->update(['body' => $cleaned]);
            }
        }

        $this->info("{$rows->count()} article(s) nettoyé(s)".($dryRun ? ' [dry-run, rien écrit]' : ''));
        $this->info($log->summary());

        return self::SUCCESS;
    }

    protected function cleanEmailHtml(string $html): string
    {
        // Convertit les séparateurs structurels HTML en vrais retours à la
        // ligne AVANT de retirer les balises (même principe qu'au §29) —
        // sinon tout le texte d'une table recollerait sur une seule ligne.
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|div|tr|table|li)>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/<!--\[if.*?<!\[endif\]-->/is', '', $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Une ligne par paragraphe non vide, ré-enveloppée en <p> — `body`
        // reste un champ HTML (rendu via {!! !!}, actualites/show.blade.php),
        // simplement débarrassé de la structure de table/styles email.
        $paragraphs = collect(preg_split('/\n+/', $text))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '')
            ->map(fn ($line) => '<p>'.e($line).'</p>');

        return $paragraphs->implode("\n");
    }
}
