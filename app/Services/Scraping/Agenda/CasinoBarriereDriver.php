<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\Concerns\FetchesHttp;
use App\Services\Scraping\Agenda\Concerns\ParsesFrenchDates;
use App\Services\Scraping\Agenda\Concerns\ResolvesCategory;
use App\Services\Scraping\ScraperDriver;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper agenda pour le Casino Théâtre Barrière Toulouse
 * (casinosbarriere.com) — reconstruit depuis le VRAI code legacy actif
 * `updateAgendaforCasinoBarriere` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~1631 ; une
 * fonction `getIdTheaterCasinobarriere` plus ancienne existe dans le même
 * fichier mais n'est plus appelée par le flux actif — code mort, ignoré ici).
 *
 * Listing : `https://www.casinosbarriere.com/nos-spectacles`, groupé par
 * catégorie (`.CsnNationalShowsPreviewCategory` > titre
 * `.CarouselContainer__title`, ex. "Concert / Humour"), puis par spectacle
 * (`.CsnCard`, un lien vers une fiche détail). CHAQUE fiche détail liste à
 * son tour plusieurs dates ponctuelles (`.LocalShowsList .LocalShows
 * .cartridge`, ex. "du 12 mars 2026 au 14 mars 2026") : contrairement aux
 * autres salles, on crée donc potentiellement PLUSIEURS `Event` par
 * spectacle (un par date/plage de dates), avec `external_ref` =
 * "{slug-titre}-{date-début}" pour les distinguer (même logique que legacy).
 *
 * Catégorisation : le libellé de catégorie (`.CarouselContainer__title`) est
 * scindé sur "/" ou "-" puis chaque terme est mis en correspondance avec un
 * slug `event_categories` (voir `ResolvesCategory::categoriesMatchingLabel`),
 * fallback "divers".
 *
 * area_slug par défaut résolu via `areas.legacy_id = 1667` : "Casino Théâtre
 * Barrière" (slug `casino-theatre-barriere`).
 *
 * ⚠️ LIMITE CONNUE (constatée en direct le 25/08/2026, voir
 * TECHNICAL_DOCUMENTATION.md §13) : casinosbarriere.com a migré vers un
 * front Nuxt3/Vue3 depuis l'écriture du code legacy. `.CsnNationalShowsPreviewCategory`
 * existe toujours (10 blocs) et les cartes spectacle aussi
 * (`.CsnCardShowPortrait`, 133 constatées), MAIS leur `<a>` englobant est
 * rendu SANS attribut `href` server-side — la navigation vers la fiche
 * détail est purement client-side (gérée en JS après hydratation Vue). Le
 * scraper détecte donc bien les spectacles (`found`) mais ne peut PAS
 * atteindre leurs fiches détail (dates, description, réservation) sans
 * exécuter le JS du site — tous comptabilisés en `skipped`. Une piste non
 * creusée : le payload Nuxt `/nos-spectacles/_payload.json` (format
 * `devalue`, pas du JSON standard) contient probablement les données
 * complètes mais nécessiterait un dé-sérialiseur dédié, non implémenté ici
 * faute de temps (voir "Ce qui reste").
 */
class CasinoBarriereDriver implements ScraperDriver
{
    use FetchesHttp;
    use ParsesFrenchDates;
    use ResolvesCategory;

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? 'https://www.casinosbarriere.com/nos-spectacles';
        $areaSlug = $config['area_slug'] ?? 'casino-theatre-barriere';
        $baseUrl = 'https://www.casinosbarriere.com';

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

        foreach ($crawler->filter('.CsnNationalShowsPreviewCategory') as $categoryNode) {
            $categoryBlock = new Crawler($categoryNode);

            $categoryLabel = $categoryBlock->filter('.CarouselContainer__title')->count()
                ? trim($categoryBlock->filter('.CarouselContainer__title')->text(''))
                : '';

            $categoryIds = collect(preg_split('/\s*\/\s*|\s*-\s*/', $categoryLabel) ?: [])
                ->filter()
                ->flatMap(fn ($label) => $this->categoriesMatchingLabel($label))
                ->pluck('id')
                ->unique()
                ->values()
                ->all();

            // `.CsnCard` (sélecteur du legacy) a disparu — le site a migré
            // vers un front Nuxt3/Vue3 depuis l'écriture du code legacy.
            // `.CsnCardShowPortrait` est le sélecteur RÉEL constaté en direct
            // le 25/08/2026 ; conservé en repli `.CsnCard` par prudence si le
            // site redevient plus proche du legacy. Voir le docblock de
            // classe pour la limite connue (lien de détail absent du HTML).
            $cards = $categoryBlock->filter('.CsnCardShowPortrait');
            if ($cards->count() === 0) {
                $cards = $categoryBlock->filter('.CsnCard');
            }

            foreach ($cards as $cardNode) {
                $card = new Crawler($cardNode);
                $stats['found']++;

                $href = $card->filter('a')->count() ? $card->filter('a')->attr('href') : $card->attr('href');

                if (! $href) {
                    // Limite connue (voir docblock de classe) : la grille de
                    // spectacles ne porte plus AUCUN lien de détail dans le
                    // HTML servi sans JS (navigation 100% client-side côté
                    // site source) — impossible d'atteindre la fiche détail
                    // (dates, description, réservation) sans exécuter le JS
                    // du site ou rétro-ingénierier son payload Nuxt interne.
                    $stats['skipped']++;

                    continue;
                }

                $detailUrl = $baseUrl.$href;
                $imageNode = $card->filter('img');
                $image = $imageNode->count() ? $imageNode->attr('src') : null;

                $this->importShowDates($detailUrl, $image, $categoryIds, $baseUrl, $area->id, $stats);
            }
        }

        return $stats;
    }

    protected function importShowDates(string $detailUrl, ?string $image, array $categoryIds, string $baseUrl, int $areaId, array &$stats): void
    {
        $html = $this->fetchHtml($detailUrl);
        if ($html === null) {
            return;
        }

        $crawler = new Crawler($html);

        $title = $crawler->filter('.cartridge__title')->count() ? trim($crawler->filter('.cartridge__title')->text('')) : null;
        $subtitle = $crawler->filter('.cartridge__subtitle')->count() ? trim($crawler->filter('.cartridge__subtitle')->text('')) : null;
        $description = $crawler->filter('.cartridge__description')->count() ? trim($crawler->filter('.cartridge__description')->text('')) : null;

        if (! $title) {
            return;
        }

        $baseSlug = Str::slug($title);

        foreach ($crawler->filter('.LocalShowsList .LocalShows .cartridge') as $dateNode) {
            $dateBlock = new Crawler($dateNode);
            $items = $dateBlock->filter('ul.cartridge__list li');

            if ($items->count() <= 1) {
                continue;
            }

            // NB : `found` compte les spectacles (cartes) au niveau de la
            // boucle appelante, pas les instances de dates individuelles
            // créées ici (un spectacle peut produire plusieurs `Event`, un
            // par date).
            $dateString = trim($items->eq(1)->text(''));

            if (preg_match('/du\s+(\d{1,2}\s+\p{L}+\s+\d{4})\s+au\s+(\d{1,2}\s+\p{L}+\s+\d{4})/ui', $dateString, $matches)) {
                [$start, $end] = $this->parseFrenchDateRange($matches[1], $matches[2]);
            } else {
                [$start, $end] = $this->parseFrenchDateRange($dateString);
            }

            if (! $start) {
                $stats['skipped']++;

                continue;
            }

            $priceNode = $dateBlock->filter('.cartridge__details li');
            $price = $priceNode->count() ? trim($priceNode->first()->text('')) : null;

            $schedule = $items->count() > 2 ? trim($items->eq(2)->text('')) : null;

            $bookingNode = $dateBlock->filter('.cartridge__cta a');
            $bookingUrl = $bookingNode->count() ? $baseUrl.$bookingNode->attr('href') : null;

            $externalRef = $baseSlug.'-'.$start->format('Y-m-d');

            $existing = Event::where('external_ref', $externalRef)->exists();

            $event = Event::updateOrCreate(
                ['external_ref' => $externalRef],
                array_filter([
                    'area_id' => $areaId,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'description' => $description,
                    'price' => $price,
                    'image' => $image,
                    'start_date' => $start,
                    'end_date' => $end,
                    'schedule' => $schedule ? [$schedule] : null,
                    'booking_url' => $bookingUrl,
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            if ($categoryIds) {
                $event->categories()->syncWithoutDetaching($categoryIds);
            }

            $existing ? $stats['updated']++ : $stats['created']++;
        }
    }
}
