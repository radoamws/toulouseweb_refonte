<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\Concerns\FetchesHttp;
use App\Services\Scraping\Agenda\Concerns\ParsesFrenchDates;
use App\Services\Scraping\Agenda\Concerns\ResolvesCategory;
use App\Services\Scraping\ScraperDriver;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper agenda pour le Théâtre Garonne (theatregaronne.com) — reconstruit
 * depuis le VRAI code legacy `updateAgendaforGaronne`/`getIdTheaterGaronne`
 * (voir old/backEnd/app/Http/Controllers/AgendaController.php ligne ~343).
 *
 * Listing : `https://www.theatregaronne.com/saison`, cartes `article.carte`
 * (les classes CSS du type `carte--xxx` encodent aussi la/les catégorie(s)
 * de l'événement côté legacy — reproduit ici en tentant de faire correspondre
 * ces suffixes à un slug `event_categories`, sinon repli sur "théâtre").
 * Détail : description `.single--spectacle__desc__droite`, sous-titre
 * `.single--spectacle__header h1`, dates `.delta--dates time` (1 ou 2 nœuds),
 * lien billetterie `.single--spectacle__billetterie a`.
 *
 * area_slug par défaut résolu via `areas.legacy_id = 6` : "Théâtre Garonne"
 * (slug `theatre-garonne`).
 */
class GaronneDriver implements ScraperDriver
{
    use FetchesHttp;
    use ParsesFrenchDates;
    use ResolvesCategory;

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? 'https://www.theatregaronne.com/saison';
        $areaSlug = $config['area_slug'] ?? 'theatre-garonne';
        $fallbackCategorySlug = $config['fallback_category_slug'] ?? 'theatre';

        $area = Area::where('slug', $areaSlug)->first();
        if (! $area) {
            throw new \RuntimeException("Area (slug={$areaSlug}) introuvable — vérifier la config de la source.");
        }

        $html = $this->fetchHtml($listingUrl);
        if ($html === null) {
            throw new \RuntimeException("Échec de récupération de la page de saison ({$listingUrl}).");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $crawler = new Crawler($html);
        $cards = $crawler->filter('article.carte');

        foreach ($cards as $node) {
            $card = new Crawler($node);
            $stats['found']++;

            $titleNode = $card->filter('.carte--spectacle__title h2');
            $linkNode = $card->filter('.carte--spectacle__title a');

            if ($titleNode->count() === 0 || $linkNode->count() === 0) {
                $stats['skipped']++;

                continue;
            }

            $title = trim($titleNode->text(''));
            $href = $linkNode->attr('href');
            $slug = trim((string) parse_url($href, PHP_URL_PATH), '/');
            $slug = basename($slug) ?: null;

            if (! $title || ! $slug) {
                $stats['skipped']++;

                continue;
            }

            $categorySlugs = $this->categorySuffixesFromClasses((string) $card->attr('class'));

            $imageNode = $card->filter('.carte--spectacle__visuel img');
            $image = $imageNode->count() ? 'https://www.theatregaronne.com'.$imageNode->attr('src') : null;

            $detail = $this->fetchDetail($this->absoluteUrl($href));
            if (! $detail || ! $detail['start_date']) {
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
                    'image' => $image,
                    'start_date' => $detail['start_date'],
                    'end_date' => $detail['end_date'],
                    'booking_url' => $detail['booking_url'],
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            $categoryIds = $categorySlugs->isNotEmpty()
                ? EventCategory::whereIn('slug', $categorySlugs)->pluck('id')
                : collect();

            if ($categoryIds->isEmpty()) {
                $fallback = EventCategory::where('slug', $fallbackCategorySlug)->first();
                $categoryIds = $fallback ? collect([$fallback->id]) : collect();
            }

            if ($categoryIds->isNotEmpty()) {
                $event->categories()->syncWithoutDetaching($categoryIds->all());
            }

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    /** @return \Illuminate\Support\Collection<int,string> */
    protected function categorySuffixesFromClasses(string $classAttr): \Illuminate\Support\Collection
    {
        return collect(explode(' ', trim($classAttr)))
            ->filter(fn ($class) => str_contains($class, '--'))
            ->map(fn ($class) => \Illuminate\Support\Str::slug(explode('--', $class)[1] ?? ''))
            ->filter();
    }

    /** @return array{subtitle: ?string, description: ?string, booking_url: ?string, start_date: ?\Carbon\Carbon, end_date: ?\Carbon\Carbon}|null */
    protected function fetchDetail(string $url): ?array
    {
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $crawler = new Crawler($html);

        $subtitle = $crawler->filter('.single--spectacle__header h1')->count()
            ? trim($crawler->filter('.single--spectacle__header h1')->text(''))
            : null;

        $description = $crawler->filter('.single--spectacle__desc__droite')->count()
            ? trim($crawler->filter('.single--spectacle__desc__droite')->text(''))
            : null;

        $bookingUrl = $crawler->filter('.single--spectacle__billetterie a')->count()
            ? $crawler->filter('.single--spectacle__billetterie a')->attr('href')
            : null;

        $dateNodes = $crawler->filter('.delta--dates time');
        if ($dateNodes->count() === 0) {
            return ['subtitle' => $subtitle, 'description' => $description, 'booking_url' => $bookingUrl, 'start_date' => null, 'end_date' => null];
        }

        $startText = trim($dateNodes->first()->text(''));
        $endText = $dateNodes->count() > 1 ? trim($dateNodes->last()->text('')) : null;

        // Cas à 2 nœuds <time> : le site n'y répète PAS le mois sur le
        // premier nœud quand les deux dates partagent le même mois (ex.
        // "07" / "15 Oct" = du 7 au 15 octobre). Le legacy traitait chaque
        // nœud comme une date complète indépendante — un bug réel constaté
        // en direct le 25/08/2026 (le 1er nœud n'a alors pas de mois,
        // `$months['']` legacy produit une date invalide). Corrigé ici : si
        // le nœud de début n'a pas de mois, on lui emprunte celui du nœud de
        // fin avant de parser.
        if ($endText && ! preg_match('/[a-zA-Zéûôîâ]/u', $startText)) {
            $endMonth = trim(preg_replace('/^\d+\s*/', '', $endText) ?? '');
            $startText = trim($startText.' '.$endMonth);
        }

        [$start, $end] = $this->parseFrenchDateRange($startText, $endText);

        return [
            'subtitle' => $subtitle,
            'description' => $description,
            'booking_url' => $bookingUrl,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }

    protected function absoluteUrl(string $href): string
    {
        return str_starts_with($href, 'http') ? $href : 'https://www.theatregaronne.com'.$href;
    }
}
