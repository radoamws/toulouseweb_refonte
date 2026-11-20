<?php

namespace App\Services\Scraping\News;

use App\Models\News;
use App\Models\NewsCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scraper d'actualités locales pour la section "Actualités de Toulouse" de
 * la home (demande client, 20/11/2026 : "les news ne sont pas à jour").
 *
 * Source choisie après comparaison (voir TECHNICAL_DOCUMENTATION.md) :
 * `actu.fr` est protégé par un anti-bot (Cloudflare bloque la première
 * requête, y compris sur `/robots.txt`) — écarté. Le Journal Toulousain
 * expose un flux RSS officiel et déjà filtré sur Toulouse, bien plus stable
 * qu'un scraping HTML (pas de sélecteur CSS à maintenir face à une refonte
 * du site source) :
 * https://www.lejournaltoulousain.fr/occitanie/haute-garonne/toulouse/feed/
 *
 * ⚠️ Décision client (20/11/2026) : seul un EXTRAIT est repris ici (`excerpt`/
 * `body` courts, voir `<description>` du flux), JAMAIS le corps complet de
 * l'article (`<content:encoded>`, ignoré ici sauf pour en extraire la
 * première image) — évite le contenu dupliqué (risque SEO) et republie sans
 * autorisation le travail d'un autre éditeur (risque de droits d'auteur).
 * `website` pointe vers l'article source, mis en avant sur la fiche détail
 * ("Lire l'article complet →", voir actualites/show.blade.php) plutôt que
 * republié tel quel.
 *
 * Priorisation sur la home (voir HomeController::index()) : ces actualités
 * ont `source = 'scraped'`, les soumissions publiques validées
 * (`/actualites/proposer`) ont `source = 'user_submitted'` — la home
 * affiche les secondes en priorité, voir §82.
 *
 * Dédup par `external_ref` = l'URL canonique de l'article (`<link>`),
 * stable d'un passage à l'autre, même convention que les scrapers agenda
 * (`external_ref`, voir TheatreDeLaCiteDriver).
 */
class LeJournalToulousainDriver implements ScraperDriver
{
    private const DEFAULT_FEED_URL = 'https://www.lejournaltoulousain.fr/occitanie/haute-garonne/toulouse/feed/';

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $feedUrl = $config['feed_url'] ?? self::DEFAULT_FEED_URL;
        $categorySlug = $config['category_slug'] ?? 'vie-locale';

        $category = NewsCategory::where('slug', $categorySlug)->first();
        if (! $category) {
            throw new \RuntimeException("NewsCategory (slug={$categorySlug}) introuvable — vérifier la config de la source.");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $xml = $this->fetchFeed($feedUrl);
        if ($xml === null) {
            throw new \RuntimeException("Échec de récupération ou de lecture du flux RSS ({$feedUrl}).");
        }

        foreach ($xml->channel->item as $item) {
            $stats['found']++;

            $link = trim((string) $item->link);
            $title = trim((string) $item->title);

            if ($link === '' || $title === '') {
                $stats['skipped']++;

                continue;
            }

            $publishedAt = $this->parseDate((string) $item->pubDate);
            if (! $publishedAt) {
                Log::channel('single')->warning("scrape:news (Le Journal Toulousain) — date illisible pour \"{$title}\" ({$link}), ignoré.");
                $stats['skipped']++;

                continue;
            }

            $excerpt = $this->cleanExcerpt((string) $item->description);
            $contentEncoded = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
            $image = $this->firstImage($contentEncoded);

            $existing = News::where('external_ref', $link)->exists();

            $news = News::updateOrCreate(
                ['external_ref' => $link],
                array_filter([
                    'category_id' => $category->id,
                    'title' => $title,
                    'excerpt' => $excerpt ? Str::limit($excerpt, 255, '') : null,
                    // `body` ne peut pas être vide (colonne NOT NULL) —
                    // reprend le même extrait, jamais le texte intégral
                    // (`<content:encoded>`, volontairement ignoré ici, voir
                    // docblock de la classe).
                    'body' => '<p>'.e($excerpt ?: $title).'</p>',
                    'image' => $image,
                    'website' => $link,
                    'published_at' => $publishedAt,
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    protected function parseDate(string $pubDate): ?Carbon
    {
        if ($pubDate === '') {
            return null;
        }

        try {
            return Carbon::parse($pubDate);
        } catch (\Throwable) {
            return null;
        }
    }

    /** `<description>` d'un flux WordPress : texte brut (CDATA déjà décodé par SimpleXML), parfois avec un lien "The post ... appeared first on" en fin — retiré, sans objet sur notre propre site. */
    protected function cleanExcerpt(string $description): string
    {
        $text = trim(strip_tags($description));
        $text = preg_replace('/The post .* appeared first on .*/is', '', $text) ?? $text;

        return trim($text);
    }

    /** Première image du corps complet de l'article (`<content:encoded>`) — jamais le corps lui-même, voir docblock de la classe. */
    protected function firstImage(string $contentEncoded): ?string
    {
        if ($contentEncoded === '') {
            return null;
        }

        if (! preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $contentEncoded, $matches)) {
            return null;
        }

        return $matches[1];
    }

    protected function fetchFeed(string $url): ?\SimpleXMLElement
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            ])->timeout(20)->retry(2, 500)->get($url);

            if (! $response->successful()) {
                return null;
            }

            // `LIBXML_NOCDATA` : les champs `<description>`/`<content:encoded>`
            // d'un flux WordPress sont en CDATA — sans ce flag, SimpleXML les
            // expose comme un noeud enfant vide au lieu du texte brut attendu.
            $xml = @simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NOCDATA);

            return $xml instanceof \SimpleXMLElement ? $xml : null;
        } catch (\Throwable $e) {
            Log::channel('single')->warning("scrape:news (Le Journal Toulousain) — erreur réseau sur {$url} : {$e->getMessage()}");

            return null;
        }
    }
}
