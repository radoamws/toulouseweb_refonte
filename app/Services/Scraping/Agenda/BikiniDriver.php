<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\Concerns\FetchesHttp;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper agenda pour Le Bikini (lebikini.com) — reconstruit depuis le VRAI
 * code legacy `updateAgendaforBikini`/`updateAgendaforBikini2` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php lignes ~2020/~2949).
 *
 * ⚠️ Le legacy (v2) ciblait un point JSON Next.js
 * (`_next/data/{buildId}/programmation.json`) — abandonné ici : ce buildId
 * change à CHAQUE déploiement du site (vérifié en direct, l'URL legacy
 * répond 404 aujourd'hui ; le buildId actuel diffère). Le re-découvrir à
 * chaque scraping serait fragile. Vérifié en direct à la place : la page
 * HTML elle-même (`/programmation/`) contient tout le nécessaire en SSR pur
 * (25 cartes `.event-card` sur un simple GET, sans exécution JS) — approche
 * retenue : scraping HTML direct, plus stable dans la durée qu'un buildId
 * Next.js.
 *
 * ⚠️ Découverte en construisant ce driver (vérifiée en direct) : cette page
 * n'est PAS exclusivement "Le Bikini" — elle liste aussi des événements
 * co-produits dans d'autres salles (constaté : "La Halle aux Grains", "Le
 * Rex", "Halle de la Machine", "La Cabane"). Même convention que
 * MetropoleDriver (agenda mutualisé) : `area_id` fixe sur "Le Bikini" (la
 * source elle-même), `venue_name` = le VRAI lieu de CET événement précis —
 * jamais un `Area` recréé à la volée par salle, voir
 * Event::venueDisplayName()/venueDisplayAddress(). Bonus pour la couverture
 * agenda (brief roadmap "contenu") : capture donc aussi une partie de la
 * programmation de la Halle aux Grains en passant, sans travail dédié.
 *
 * Détail structurel (DomCrawler, vérifié en direct le 15/10/2026) :
 *  - liste : `.event-card`, un par spectacle
 *  - lien+date : 1er `<a>` de la carte, `href` = `/{année}/{mois}/{jour}/{slug}`
 *    — la DATE complète est directement dans l'URL, bien plus fiable qu'un
 *    parsing de texte français ("Jeudi 15 octobre 2026")
 *  - heure + lieu réel : 2 `<div>` enfants du `<a class="... border-tabline ...">`
 *    (date+heure en texte libre, lieu juste après) — seule l'HEURE est
 *    extraite du premier (la date vient de l'URL)
 *  - titre : `h3` de la carte
 *  - image : `src` du 1er `<img>`, URL Sanity CDN réelle extraite du
 *    paramètre `url=` du proxy `/_next/image?url=...` de Next.js
 *  - prix : 1er badge de prix (texte libre, ex. "à partir de 30€")
 *  - pas de lien billetterie direct capturable (bouton "Réserver" piloté en
 *    JS côté client, pas un `<a href>`) — repli sur la page de l'événement
 *    elle-même, même convention que `GoogleIndexingObserver`/JSON-LD
 *    ailleurs dans ce projet quand aucune URL externe fiable n'existe.
 */
class BikiniDriver implements ScraperDriver
{
    use FetchesHttp;

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? 'https://lebikini.com/programmation/';
        $areaSlug = $config['area_slug'] ?? 'le-bikini';
        $categorySlug = $config['event_category_slug'] ?? 'concerts';

        $area = Area::where('slug', $areaSlug)->first();
        if (! $area) {
            throw new \RuntimeException("Area (slug={$areaSlug}) introuvable — vérifier la config de la source.");
        }

        $html = $this->fetchHtml($listingUrl);
        if ($html === null) {
            throw new \RuntimeException("Échec de récupération de la page de programmation ({$listingUrl}).");
        }

        $category = EventCategory::where('slug', $categorySlug)->first();

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $crawler = new Crawler($html);
        $cards = $crawler->filter('.event-card');

        foreach ($cards as $node) {
            $card = new Crawler($node);
            $stats['found']++;

            $linkNode = $card->filter('a')->first();
            $href = $linkNode->count() ? $linkNode->attr('href') : null;

            $titleNode = $card->filter('h3')->first();
            $title = $titleNode->count() ? trim($titleNode->text('')) : null;

            $startDate = $href ? $this->parseDateFromPath($href) : null;

            if (! $href || ! $title || ! $startDate) {
                $stats['skipped']++;

                continue;
            }

            $dateTimeNode = $card->filter('a[class*="border-tabline"]')->first();
            $dateTimeDivs = $dateTimeNode->count() ? $dateTimeNode->filter('div') : null;

            $time = $dateTimeDivs && $dateTimeDivs->count() > 0 ? $this->extractTime($dateTimeDivs->eq(0)->text('')) : null;
            $venueName = $dateTimeDivs && $dateTimeDivs->count() > 1 ? trim($dateTimeDivs->eq(1)->text('')) : null;

            if ($time) {
                $startDate->setTimeFromTimeString($time);
            }

            $slug = trim((string) parse_url($href, PHP_URL_PATH), '/');
            $externalRef = str_replace('/', '-', $slug);

            $priceNode = $card->filter('div[class*="rounded-md"][class*="bg-background"]')->first();
            $price = $priceNode->count() ? trim($priceNode->text('')) : null;

            $image = $this->extractSanityImage($card);

            $existing = Event::where('external_ref', $externalRef)->exists();

            $event = Event::updateOrCreate(
                ['external_ref' => $externalRef],
                array_filter([
                    'area_id' => $area->id,
                    'venue_name' => $venueName,
                    'title' => $title,
                    'price' => $price,
                    'image' => $image,
                    'start_date' => $startDate,
                    'end_date' => $startDate,
                    'booking_url' => $this->absoluteUrl($href),
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            if ($category) {
                $event->categories()->syncWithoutDetaching([$category->id]);
            }

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    /** `/{année}/{mois}/{jour}/{slug}` — la date est DANS l'URL elle-même, voir docblock de classe. */
    private function parseDateFromPath(string $href): ?Carbon
    {
        if (! preg_match('#^/?(\d{4})/(\d{1,2})/(\d{1,2})/#', $href, $m)) {
            return null;
        }

        try {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3]);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Ex. "Jeudi 15 octobre 2026 à 19h30" -> "19:30:00". */
    private function extractTime(string $text): ?string
    {
        if (! preg_match('/(\d{1,2})h(\d{2})?/', $text, $m)) {
            return null;
        }

        return sprintf('%02d:%02d:00', (int) $m[1], (int) ($m[2] ?? 0));
    }

    private function extractSanityImage(Crawler $card): ?string
    {
        $img = $card->filter('img')->first();
        if (! $img->count()) {
            return null;
        }

        $src = $img->attr('src') ?? '';
        if (! preg_match('/url=([^&]+)/', $src, $m)) {
            return null;
        }

        return urldecode($m[1]);
    }

    private function absoluteUrl(string $href): string
    {
        return str_starts_with($href, 'http') ? $href : 'https://lebikini.com'.$href;
    }
}
