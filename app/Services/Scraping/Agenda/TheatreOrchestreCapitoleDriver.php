<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\Concerns\FetchesHttp;
use App\Services\Scraping\Agenda\Concerns\ResolvesCategory;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper agenda partagé par les 2 sites de l'établissement public du
 * Capitole (brief roadmap "contenu", 14/10/2026, demande client : "driver
 * Halle aux Grains/Théâtre du Capitole, scraping sur-mesure") :
 *  - onct.toulouse.fr/agenda/ (Orchestre National du Capitole, saison
 *    symphonique — vérifié en direct : les événements y mentionnent "Halle
 *    aux Grains", jamais le Théâtre du Capitole)
 *  - opera.toulouse.fr/agenda/ (Opéra national du Capitole, saison
 *    lyrique/ballet — le vrai domaine derrière theatreducapitole.fr, qui n'y
 *    est qu'une redirection, vérifié en direct)
 *
 * Les deux sites utilisent EXACTEMENT le même thème/plugin WordPress
 * (classes `card-item*` identiques, microdonnées schema.org/Event
 * identiques, même domaine de billetterie
 * `billetterie.theatreorchestre.toulouse-metropole.fr` — vérifié en direct
 * le 15/10/2026) : UN seul driver, réutilisé par 2 `ScraperSource` distinctes
 * (voir AgendaScraperSourcesSeeder), chacune avec son propre
 * `listing_url`/`area_slug` — même principe que les drivers OpenAgenda
 * (Zénith/Métropole), mais sans sous-classe nécessaire ici (pas de logique
 * de catégorisation à diverger entre les deux sites).
 *
 * Détail structurel (DomCrawler, microdonnées schema.org, vérifié en direct) :
 *  - liste : `.card-item[itemscope][itemtype="https://schema.org/Event"]`
 *  - titre : `[itemprop="name"]`
 *  - date : `time[itemprop="startDate"]`, attribut `datetime` ISO 8601 —
 *    FIN de la plage non capturée (le texte affiché type "17 → 23 octobre
 *    2026" n'a pas d'équivalent machine-lisible pour la fin) ; `end_date` =
 *    `start_date`, même convention que
 *    `AbstractOpenAgendaDriver::extractDates()` quand aucune fin fiable
 *    n'existe.
 *  - genre : `.card-item-taxonomies li` (ex. "Ballet", "Concert en famille")
 *    — résolu via `categoriesMatchingLabel()` (même logique que les autres
 *    drivers), repli sur `event_category_slug` de la config si rien ne
 *    correspond.
 *  - lien détail : `.card-item-link`
 *  - image : `img[itemprop="image"]`, URL directe (CDN OpenAgenda), aucun
 *    proxy à déchiffrer.
 *  - prix + lien billetterie réel : 2e requête HTTP vers la page de détail
 *    (même principe que GaronneDriver::fetchTicketingInfo()) —
 *    `[itemprop="price"]` et `.single-item-status a[href*="billetterie"]`.
 */
class TheatreOrchestreCapitoleDriver implements ScraperDriver
{
    use FetchesHttp;
    use ResolvesCategory;

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? null;
        $areaSlug = $config['area_slug'] ?? null;
        $fallbackCategorySlug = $config['event_category_slug'] ?? 'concerts';

        if (! $listingUrl || ! $areaSlug) {
            throw new \RuntimeException('listing_url et area_slug sont requis dans la config de cette source.');
        }

        $area = Area::where('slug', $areaSlug)->first();
        if (! $area) {
            throw new \RuntimeException("Area (slug={$areaSlug}) introuvable — vérifier la config de la source.");
        }

        $html = $this->fetchHtml($listingUrl);
        if ($html === null) {
            throw new \RuntimeException("Échec de récupération de la page agenda ({$listingUrl}).");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $crawler = new Crawler($html);
        $cards = $crawler->filter('.card-item');

        foreach ($cards as $node) {
            $card = new Crawler($node);
            $stats['found']++;

            $titleNode = $card->filter('[itemprop="name"]')->first();
            $title = $titleNode->count() ? trim($titleNode->text('')) : null;

            $dateNode = $card->filter('time[itemprop="startDate"]')->first();
            $startDate = $dateNode->count() ? $this->parseIsoDate($dateNode->attr('datetime')) : null;

            $linkNode = $card->filter('.card-item-link')->first();
            $href = $linkNode->count() ? $linkNode->attr('href') : null;

            $slug = $href ? (basename(trim((string) parse_url($href, PHP_URL_PATH), '/')) ?: null) : null;

            if (! $title || ! $startDate || ! $slug) {
                $stats['skipped']++;

                continue;
            }

            $descriptionNode = $card->filter('[itemprop="description"]')->first();
            $description = $descriptionNode->count() ? trim($descriptionNode->text('')) : null;

            $imageNode = $card->filter('img[itemprop="image"]')->first();
            $image = $imageNode->count() ? $imageNode->attr('src') : null;

            $taxonomyNode = $card->filter('.card-item-taxonomies li')->first();
            $taxonomyLabel = $taxonomyNode->count() ? trim($taxonomyNode->text('')) : null;

            $ticketing = $this->fetchTicketingInfo($href);

            $existing = Event::where('external_ref', $slug)->exists();

            $event = Event::updateOrCreate(
                ['external_ref' => $slug],
                array_filter([
                    'area_id' => $area->id,
                    'title' => $title,
                    'description' => $description,
                    'image' => $image,
                    'price' => $ticketing['price'],
                    'start_date' => $startDate,
                    'end_date' => $startDate,
                    'booking_url' => $ticketing['booking_url'] ?? $href,
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            $categories = $taxonomyLabel
                ? $this->categoriesMatchingLabel($taxonomyLabel, $fallbackCategorySlug)
                : collect([$this->categoryByLegacyId(null, $fallbackCategorySlug)])->filter();

            if ($categories->isNotEmpty()) {
                $event->categories()->syncWithoutDetaching($categories->pluck('id')->all());
            }

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    private function parseIsoDate(?string $datetime): ?Carbon
    {
        if (! $datetime) {
            return null;
        }

        try {
            return Carbon::parse($datetime);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reproduit le même principe que GaronneDriver::fetchTicketingInfo() :
     * un 2e appel HTTP vers la page de détail de l'événement pour en extraire
     * le tarif réel et le lien de billetterie externe (jamais présents sur
     * la carte de la page listing elle-même).
     *
     * @return array{price: ?string, booking_url: ?string}
     */
    private function fetchTicketingInfo(?string $detailUrl): array
    {
        if (! $detailUrl) {
            return ['price' => null, 'booking_url' => null];
        }

        $html = $this->fetchHtml($detailUrl);
        if ($html === null) {
            return ['price' => null, 'booking_url' => null];
        }

        $crawler = new Crawler($html);

        $priceNode = $crawler->filter('[itemprop="price"]')->first();
        $price = $priceNode->count() ? trim(preg_replace('/\s+/u', ' ', $priceNode->text('')) ?? '') : null;

        $linkNode = $crawler->filter('.single-item-status a[href*="billetterie"]')->first();
        $bookingUrl = $linkNode->count() ? $linkNode->attr('href') : null;

        return ['price' => $price ?: null, 'booking_url' => $bookingUrl];
    }
}
