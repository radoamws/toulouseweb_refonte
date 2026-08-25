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
 * Scraper agenda pour Le Vent des Signes (leventdessignes.fr) — reconstruit
 * depuis le VRAI code legacy `updateAgendaforLeventdessignes` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~3623).
 *
 * Listing : page d'accueil, cartes `article` → lien détail. Détail : titre
 * `.single-title`, sous-titre `.single-genre` + `.single-auteur`, description
 * `.single-presentation`, image `.slides img`, dates `.single-dates` (texte
 * du type "à partir du 12 mars > 14 mars | 20h30", séparé par `|` puis `>`).
 *
 * Catégorie legacy hardcodée à 4 (Théâtre).
 *
 * area_slug par défaut résolu via `areas.legacy_id = 57` : "Le Vent des
 * Signes" (slug `le-vent-des-signes`).
 */
class LeventDesSignesDriver implements ScraperDriver
{
    use FetchesHttp;
    use ParsesFrenchDates;

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? 'https://www.leventdessignes.fr';
        $areaSlug = $config['area_slug'] ?? 'le-vent-des-signes';
        $categorySlug = $config['event_category_slug'] ?? 'theatre';

        $area = Area::where('slug', $areaSlug)->first();
        $category = EventCategory::where('slug', $categorySlug)->first();

        if (! $area || ! $category) {
            throw new \RuntimeException("Area (slug={$areaSlug}) ou EventCategory (slug={$categorySlug}) introuvable — vérifier la config de la source.");
        }

        $html = $this->fetchHtml($listingUrl);
        if ($html === null) {
            throw new \RuntimeException("Échec de récupération de la page d'accueil ({$listingUrl}).");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $crawler = new Crawler($html);
        $articles = $crawler->filter('article');

        foreach ($articles as $node) {
            $article = new Crawler($node);
            $stats['found']++;

            $linkNode = $article->filter('a')->first();
            if ($linkNode->count() === 0) {
                $stats['skipped']++;

                continue;
            }

            $href = $linkNode->attr('href');
            $detail = $this->fetchDetail($href);

            if (! $detail || ! $detail['title'] || ! $detail['start_date']) {
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
                    'title' => $detail['title'],
                    'subtitle' => $detail['subtitle'],
                    'description' => $detail['description'],
                    'image' => $detail['image'],
                    'start_date' => $detail['start_date'],
                    'end_date' => $detail['end_date'],
                    'booking_url' => $href,
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );
            $event->categories()->syncWithoutDetaching([$category->id]);

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    /** @return array{title:?string,subtitle:?string,description:?string,image:?string,start_date:?\Carbon\Carbon,end_date:?\Carbon\Carbon}|null */
    protected function fetchDetail(string $url): ?array
    {
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $crawler = new Crawler($html);

        $title = $crawler->filter('.single-title')->count() ? trim($crawler->filter('.single-title')->text('')) : null;

        $subtitle = trim(implode(' ', array_filter([
            $crawler->filter('.single-genre')->count() ? trim($crawler->filter('.single-genre')->text('')) : null,
            $crawler->filter('.single-auteur')->count() ? '('.trim($crawler->filter('.single-auteur')->text('')).')' : null,
        ]))) ?: null;

        $description = $crawler->filter('.single-presentation')->count()
            ? trim($crawler->filter('.single-presentation')->text(''))
            : null;

        $image = $crawler->filter('.slides img')->count() ? $crawler->filter('.slides img')->attr('src') : null;

        $dateText = $crawler->filter('.single-dates')->count() ? trim($crawler->filter('.single-dates')->text('')) : '';
        $dateSegment = trim(explode('|', $dateText)[0] ?? '');
        $dateSegment = trim(str_ireplace('à partir du', '', $dateSegment));
        $rangeParts = array_map('trim', explode('>', $dateSegment));
        $startPart = $rangeParts[0] ?? '';
        $endPart = $rangeParts[1] ?? null;

        // Cas "13 > 17 MAI" : le jour de début n'a pas de mois propre (partagé
        // avec la fin) — même correctif que GaronneDriver (voir son
        // docblock), constaté en direct le 25/08/2026 sur ce site aussi.
        if ($endPart && ! preg_match('/[a-zA-Zéûôîâ]/u', $startPart)) {
            $endMonth = trim(preg_replace('/^\d+\s*/', '', $endPart) ?? '');
            $startPart = trim($startPart.' '.$endMonth);
        }

        [$start, $end] = $this->parseFrenchDateRange($startPart, $endPart);

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'image' => $image,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }
}
