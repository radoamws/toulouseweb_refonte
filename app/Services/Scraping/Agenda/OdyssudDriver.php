<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\Concerns\FetchesHttp;
use App\Services\Scraping\Agenda\Concerns\ParsesFrenchDates;
use App\Services\Scraping\Agenda\Concerns\ResolvesCategory;
use App\Services\Scraping\ScraperDriver;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper agenda pour Odyssud Blagnac (odyssud.com) — reconstruit depuis le
 * VRAI code legacy `updateAgendaforOdyssud`/`getIdTheaterOdyssud` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~2657).
 *
 * Listing : `https://www.odyssud.com/spectacles/normal`, cartes
 * `.spectacles--search--content.future article` (le suffixe `.future` filtre
 * déjà les événements passés côté site). Détail : accroche
 * `.spectacle--accroche`, description `.spectacle--texts`, tarifs
 * `.bundle--tarifs`, dates `.duration .duration-day span`, horaires
 * `.field--name-dates .date-field-item`, lien billetterie
 * `.link-zone.type--bloc-lien a`, discipline `.field.field--name-discipline`
 * (utilisée pour la catégorisation, par correspondance de libellé).
 *
 * area_slug par défaut résolu via `areas.legacy_id = 16` : "Odyssud Blagnac"
 * (slug `odyssud-blagnac`).
 */
class OdyssudDriver implements ScraperDriver
{
    use FetchesHttp;
    use ParsesFrenchDates;
    use ResolvesCategory;

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? 'https://www.odyssud.com/spectacles/normal';
        $areaSlug = $config['area_slug'] ?? 'odyssud-blagnac';

        $area = Area::where('slug', $areaSlug)->first();
        if (! $area) {
            throw new \RuntimeException("Area (slug={$areaSlug}) introuvable — vérifier la config de la source.");
        }

        $html = $this->fetchHtml($listingUrl);
        if ($html === null) {
            throw new \RuntimeException("Échec de récupération de la page spectacles ({$listingUrl}).");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $crawler = new Crawler($html);
        $cards = $crawler->filter('.spectacles--search--content.future article');

        foreach ($cards as $node) {
            $card = new Crawler($node);
            $stats['found']++;

            $titleNode = $card->filter('.field--name-name');
            $linkNode = $card->filter('.entity-infos .link-zone a');

            if ($titleNode->count() === 0 || $linkNode->count() === 0) {
                $stats['skipped']++;

                continue;
            }

            $title = trim($titleNode->text(''));
            $href = 'https://www.odyssud.com'.$linkNode->attr('href');

            $imageNode = $card->filter('picture source');
            $image = $imageNode->count() ? 'https://www.odyssud.com'.explode('?', (string) $imageNode->attr('srcset'))[0] : null;

            $detail = $this->fetchDetail($href);
            if (! $detail || ! $title) {
                $stats['skipped']++;

                continue;
            }

            $slugParts = array_values(array_filter(explode('/', rtrim($href, '/'))));
            $slug = end($slugParts) ?: null;

            if (! $slug || ! $detail['start_date']) {
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
                    'booking_url' => $detail['booking_url'],
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            $categoryIds = $detail['discipline']
                ? $this->categoriesMatchingLabel($detail['discipline'])->pluck('id')->all()
                : [];

            if ($categoryIds) {
                $event->categories()->syncWithoutDetaching($categoryIds);
            }

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    /** @return array{subtitle:?string,description:?string,price:?string,start_date:?\Carbon\Carbon,end_date:?\Carbon\Carbon,booking_url:?string,discipline:?string}|null */
    protected function fetchDetail(string $url): ?array
    {
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $crawler = new Crawler($html);

        $subtitle = $crawler->filter('.spectacle--accroche')->count()
            ? trim($crawler->filter('.spectacle--accroche')->text(''))
            : null;

        $description = $crawler->filter('.spectacle--texts')->count()
            ? trim($crawler->filter('.spectacle--texts')->text(''))
            : null;

        $price = $crawler->filter('.bundle--tarifs')->count()
            ? trim(preg_replace('/\s+/u', ' ', $crawler->filter('.bundle--tarifs')->text('')) ?? '')
            : null;

        $start = $end = null;
        $durationSpans = $crawler->filter('.duration .duration-day span');
        if ($durationSpans->count() >= 3) {
            $dfText = trim($durationSpans->eq(2)->text(''));
            $firstText = trim($durationSpans->first()->text(''));
            // Le 1er <span> est parfois vide (pictogramme sans texte côté
            // site pour une représentation unique, constaté en direct le
            // 25/08/2026) — dans ce cas il n'y a qu'une seule date, pas une
            // plage : on n'essaie pas de reconstruire un "jour + mois".
            if ($firstText === '') {
                [$start, $end] = $this->parseFrenchDateRange($dfText, $dfText);
            } else {
                $ddText = $firstText.' '.(explode(' ', $dfText)[1] ?? '');
                [$start, $end] = $this->parseFrenchDateRange($ddText, $dfText);
            }
        } elseif ($durationSpans->count() > 0) {
            $ddText = trim($durationSpans->first()->text(''));
            [$start, $end] = $this->parseFrenchDateRange($ddText, $ddText);
        } else {
            // Le site ne rend plus systématiquement de <span> imbriqué dans
            // `.duration-day` (constaté en direct le 25/08/2026) — chaque
            // `.duration-day` porte alors directement un texte "D mois"
            // autonome (une par date de représentation), on prend la
            // première comme début et la dernière comme fin.
            $durationDays = $crawler->filter('.duration .duration-day');
            if ($durationDays->count() > 0) {
                $ddText = trim($durationDays->first()->text(''));
                $dfText = trim($durationDays->last()->text(''));
                [$start, $end] = $this->parseFrenchDateRange($ddText, $dfText);
            }
        }

        $bookingUrl = $crawler->filter('.link-zone.type--bloc-lien a')->count()
            ? $crawler->filter('.link-zone.type--bloc-lien a')->attr('href')
            : null;

        $discipline = $crawler->filter('.field.field--name-discipline')->count()
            ? trim($crawler->filter('.field.field--name-discipline')->text(''))
            : null;

        return [
            'subtitle' => $subtitle,
            'description' => $description,
            'price' => $price,
            'start_date' => $start,
            'end_date' => $end,
            'booking_url' => $bookingUrl,
            'discipline' => $discipline,
        ];
    }
}
