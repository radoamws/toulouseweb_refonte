<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\Concerns\FetchesHttp;
use App\Services\Scraping\Agenda\Concerns\ParsesFrenchDates;
use App\Services\Scraping\ScraperDriver;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper agenda pour Les Grands Interprètes (grandsinterpretes.com) —
 * reconstruit depuis le VRAI code legacy
 * `updateAgendaforInterprete`/`getIdTheaterInterprete` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~4182).
 *
 * ⚠️ LIMITE CONNUE (constatée en direct le 25/08/2026, voir
 * TECHNICAL_DOCUMENTATION.md §13) : le site a intégralement changé depuis
 * l'écriture du code legacy — domaine `.com` → `.fr`, URL de saison
 * `/saison/2023-2024/` → `/saison2026-2027/` (sans slash), ET surtout le CMS
 * lui-même a changé (thème WordPress "EventChamp"/"The Events Calendar" —
 * classes `.gt-event-style-4` etc., plus aucune trace de `.concert-title`/
 * `.fake-link`/`.taviraj.date`...). Les sélecteurs ci-dessous sont donc ceux
 * du legacy, PAS ceux du site actuel : ce driver ne trouve actuellement
 * aucun événement (`found = 0`) et nécessiterait une reconstruction complète
 * des sélecteurs contre le nouveau CMS (hors budget de cette phase — voir
 * "Ce qui reste"). `listing_url` est néanmoins mis à jour vers la vraie page
 * de saison actuelle pour faciliter cette reconstruction future.
 *
 * Détail (legacy, à reconstruire) : sous-titre `.concert-subtitle`,
 * description `.entry-content`, tarifs `.tarifs table tr` (paires td
 * libellé/valeur), date `.taviraj.date` (texte positionnel, mois abrégé),
 * horaire `.taviraj.heure`.
 *
 * Catégorie legacy hardcodée à 3 (Concerts).
 *
 * area_slug par défaut résolu via `areas.legacy_id = 1177` : "Les Grands
 * Interprètes" (slug `les-grands-interpretes`).
 */
class InterpreteDriver implements ScraperDriver
{
    use FetchesHttp;
    use ParsesFrenchDates;

    private const CURRENT_SEASON_LISTING_URL = 'https://www.grandsinterpretes.fr/saison2026-2027/';

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? self::CURRENT_SEASON_LISTING_URL;
        $areaSlug = $config['area_slug'] ?? 'les-grands-interpretes';
        $categorySlug = $config['event_category_slug'] ?? 'concerts';

        $area = Area::where('slug', $areaSlug)->first();
        $category = EventCategory::where('slug', $categorySlug)->first();

        if (! $area || ! $category) {
            throw new \RuntimeException("Area (slug={$areaSlug}) ou EventCategory (slug={$categorySlug}) introuvable — vérifier la config de la source.");
        }

        $html = $this->fetchHtml($listingUrl);
        if ($html === null) {
            throw new \RuntimeException("Échec de récupération de la page de saison ({$listingUrl}) — l'URL est peut-être obsolète (saison suivante), voir docblock de classe.");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $crawler = new Crawler($html);
        $cards = $crawler->filter('article.fake-link');

        foreach ($cards as $node) {
            $card = new Crawler($node);
            $stats['found']++;

            $titleNode = $card->filter('.concert-title');
            $linkNode = $card->filter('a.post-link');

            if ($titleNode->count() === 0 || $linkNode->count() === 0) {
                $stats['skipped']++;

                continue;
            }

            $title = trim($titleNode->text(''));
            $href = $linkNode->attr('href');

            $imageNode = $card->filter('.img-theme-wrapper img');
            $image = $imageNode->count() ? $imageNode->attr('src') : null;

            $detail = $this->fetchDetail($href);
            if (! $title || ! $href || ! $detail || ! $detail['start_date']) {
                $stats['skipped']++;

                continue;
            }

            $slugParts = array_values(array_filter(explode('/', rtrim($href, '/'))));
            $slug = end($slugParts) ?: null;

            if (! $slug) {
                $stats['skipped']++;

                continue;
            }

            $existing = Event::where('external_ref', $slug)->exists();

            $event = Event::updateOrCreate(
                ['external_ref' => $slug],
                array_filter([
                    'area_id' => $area->id,
                    'title' => $title,
                    'subtitle' => $detail['subtitle'],
                    'description' => $detail['description'],
                    'price' => $detail['price'],
                    'image' => $image,
                    'start_date' => $detail['start_date'],
                    'end_date' => $detail['end_date'],
                    'schedule' => $detail['schedule'] ? [$detail['schedule']] : null,
                    'booking_url' => $detail['booking_url'],
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );
            $event->categories()->syncWithoutDetaching([$category->id]);

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    /** @return array{subtitle:?string,description:?string,price:?string,schedule:?string,booking_url:?string,start_date:?\Carbon\Carbon,end_date:?\Carbon\Carbon}|null */
    protected function fetchDetail(string $url): ?array
    {
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $crawler = new Crawler($html);

        $subtitle = $crawler->filter('.concert-subtitle')->count()
            ? trim($crawler->filter('.concert-subtitle')->text(''))
            : null;

        $description = $crawler->filter('.entry-content')->count()
            ? trim($crawler->filter('.entry-content')->text(''))
            : null;

        $priceRows = $crawler->filter('.tarifs table tr');
        $priceParts = [];
        foreach ($priceRows as $rowNode) {
            $row = new Crawler($rowNode);
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $priceParts[] = trim($cells->eq(0)->text('')).': '.trim($cells->eq(1)->text(''));
            }
        }
        $price = $priceParts ? implode(' | ', $priceParts) : null;

        $schedule = $crawler->filter('.taviraj.heure')->count()
            ? trim(str_replace('Heure : ', '', $crawler->filter('.taviraj.heure')->text('')))
            : null;

        $bookingUrl = $crawler->filter('.lien-resa.fixed-bottom')->count()
            ? $crawler->filter('.lien-resa.fixed-bottom')->attr('href')
            : null;

        $start = $end = null;
        $dateNode = $crawler->filter('.taviraj.date');
        if ($dateNode->count()) {
            $tokens = preg_split('/\s+/u', trim($dateNode->first()->text('')));
            if (is_array($tokens) && count($tokens) >= 5) {
                $dateText = $tokens[3].' '.$tokens[4];
                [$start, $end] = $this->parseFrenchDateRange($dateText);
            }
        }

        return [
            'subtitle' => $subtitle,
            'description' => $description,
            'price' => $price,
            'schedule' => $schedule,
            'booking_url' => $bookingUrl,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }
}
