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
 * Scraper agenda pour le Théâtre du Grand Rond (grand-rond.org) — reconstruit
 * depuis le VRAI code legacy `updateAgendaforGrandRond` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~3154 — une
 * variante `_OldWebsite` plus ancienne existe aussi dans le fichier,
 * ignorée ici).
 *
 * Listing : `https://www.grand-rond.org/programmation`, blocs
 * `.container.principal .programmation .container-fluid` contenant un `h3`
 * (titre) et un lien `a.bouton_plus` (détail, +éventuel 2e lien = résa).
 * Détail : description `#responsiveTabsDemo #tab-1`, horaire
 * `.col-md-5.bloc_type p strong` (texte du type "Durée 1h Genre..." séparé
 * par " à "), dates `#principal table p strong` (texte libre, mois français
 * en toutes lettres).
 *
 * Catégorie legacy hardcodée à 4 (Théâtre).
 *
 * area_slug par défaut résolu via `areas.legacy_id = 1973` : "Théâtre du
 * Grand Rond" (slug `theatre-du-grand-rond-3` — attention, DISTINCT de
 * l'entrée `theatre-du-grand-rond` sans suffixe : plusieurs doublons existent
 * dans la table `areas` migrée pour ce lieu, celui-ci est le seul dont le
 * `legacy_id` correspond exactement au code source réel).
 *
 * ⚠️ Bug réel trouvé et corrigé (19/09/2026, audit "aucun agenda retourné"
 * demandé par le client) : `#principal table p strong` (sélecteur repris du
 * legacy) n'existe PLUS DU TOUT sur la fiche détail actuelle — constaté en
 * direct le 19/09/2026, `#principal` a disparu du site. Résultat : `start_date`
 * était toujours `null`, donc CHAQUE fiche était `skipped` — ce driver ne
 * créait/mettait à jour aucun événement depuis (au moins) le début du
 * scraping en prod. Le texte de la date existe toujours, mais a été déplacé
 * dans `.col-md-5.bloc_type p strong` (le même bloc que l'horaire, déjà lu
 * pour le "schedule") — sous forme de texte libre très variable
 * ("Du 24 septembre au 3 octobre 2026, ...", "Du 24 au 26 septembre 2026,
 * ...", "Mercredi 30 septembre et samedi 3 octobre 2026 ..."), voir
 * `extractDateRangeFromText()`.
 */
class GrandRondDriver implements ScraperDriver
{
    use FetchesHttp;
    use ParsesFrenchDates;

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? 'https://www.grand-rond.org/programmation';
        $areaSlug = $config['area_slug'] ?? 'theatre-du-grand-rond-3';
        $categorySlug = $config['event_category_slug'] ?? 'theatre';

        $area = Area::where('slug', $areaSlug)->first();
        $category = EventCategory::where('slug', $categorySlug)->first();

        if (! $area || ! $category) {
            throw new \RuntimeException("Area (slug={$areaSlug}) ou EventCategory (slug={$categorySlug}) introuvable — vérifier la config de la source.");
        }

        $html = $this->fetchHtml($listingUrl);
        if ($html === null) {
            throw new \RuntimeException("Échec de récupération de la page de programmation ({$listingUrl}).");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $crawler = new Crawler($html);
        $blocks = $crawler->filter('.container.principal .programmation .container-fluid');

        foreach ($blocks as $node) {
            $block = new Crawler($node);

            $titleNode = $block->filter('h3');
            if ($titleNode->count() === 0) {
                continue;
            }

            $stats['found']++;

            $title = trim($titleNode->text(''));
            $links = $block->filter('a.bouton_plus');

            if ($title === '' || $links->count() === 0) {
                $stats['skipped']++;

                continue;
            }

            $detailHref = $links->first()->attr('href');
            if (! $detailHref) {
                $stats['skipped']++;

                continue;
            }

            $imageNode = $block->filter('img');
            $image = $imageNode->count() ? 'https://www.grand-rond.org/'.ltrim((string) $imageNode->attr('src'), '/') : null;

            $detail = $this->fetchDetail($detailHref);
            if (! $detail || ! $detail['start_date']) {
                $stats['skipped']++;

                continue;
            }

            $slugParts = array_values(array_filter(explode('/', rtrim($detailHref, '/'))));
            $slug = 'gr'.(str_replace('Number=', '', end($slugParts)) ?: '');

            $existing = Event::where('external_ref', $slug)->exists();

            $event = Event::updateOrCreate(
                ['external_ref' => $slug],
                array_filter([
                    'area_id' => $area->id,
                    'title' => $title,
                    'subtitle' => $title,
                    'description' => $detail['description'],
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

    /** @return array{description:?string,schedule:?string,booking_url:?string,start_date:?\Carbon\Carbon,end_date:?\Carbon\Carbon}|null */
    protected function fetchDetail(string $url): ?array
    {
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $crawler = new Crawler($html);

        $description = $crawler->filter('#responsiveTabsDemo #tab-1')->count()
            ? trim($crawler->filter('#responsiveTabsDemo #tab-1')->text(''))
            : null;

        // Bloc texte libre contenant à la fois la date/plage et l'horaire —
        // `#principal` (ancien emplacement de la date) a disparu du site, voir
        // docblock de classe : c'est désormais le SEUL endroit où la date existe.
        $blocText = null;
        $scheduleNode = $crawler->filter('.col-md-5.bloc_type p strong');
        if ($scheduleNode->count()) {
            $blocText = trim(preg_replace('/\s+/u', ' ', $scheduleNode->first()->text('')) ?? '');
        }

        $schedule = null;
        if ($blocText) {
            $parts = explode(' à ', $blocText);
            if (count($parts) >= 2) {
                $schedule = trim(str_replace('Genre', '', $parts[1]));
            }
        }

        $bookingUrl = null;
        $bookingNode = $crawler->filter('a.bouton_plus');
        if ($bookingNode->count() && trim($bookingNode->first()->text('')) === 'Réserver') {
            $bookingUrl = $bookingNode->first()->attr('href');
        }

        [$start, $end] = $blocText ? $this->extractDateRangeFromText($blocText) : [null, null];

        return [
            'description' => $description,
            'schedule' => $schedule,
            'booking_url' => $bookingUrl,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }

    /**
     * Extrait une date/plage depuis le texte libre de `.col-md-5.bloc_type p
     * strong` — 3 formats réels constatés en direct le 19/09/2026, du plus
     * spécifique au plus générique :
     *   1. "Du 24 septembre au 3 octobre 2026..." (mois différents, année en fin)
     *   2. "Du 24 au 26 septembre 2026..." (même mois)
     *   3. "Mercredi 30 septembre et samedi 3 octobre 2026..." (dates ponctuelles
     *      listées librement) — repli générique : on prend la première et la
     *      dernière date valide (jour+mois reconnu) trouvée dans le texte.
     *
     * @return array{0: ?\Carbon\Carbon, 1: ?\Carbon\Carbon}
     */
    protected function extractDateRangeFromText(string $text): array
    {
        if (preg_match('/du\s+(\d{1,2})\s+([a-zéûôîâ]+)\s+au\s+(\d{1,2})\s+([a-zéûôîâ]+)\s+(\d{4})/ui', $text, $m)) {
            return $this->parseFrenchDateRange("{$m[1]} {$m[2]} {$m[5]}", "{$m[3]} {$m[4]} {$m[5]}");
        }

        if (preg_match('/du\s+(\d{1,2})\s+au\s+(\d{1,2})\s+([a-zéûôîâ]+)\s+(\d{4})/ui', $text, $m)) {
            return $this->parseFrenchDateRange("{$m[1]} {$m[3]} {$m[4]}", "{$m[2]} {$m[3]} {$m[4]}");
        }

        if (preg_match_all('/(\d{1,2})(?:er)?\s+([a-zéûôîâ]+)\.?\s*(\d{4})?/ui', $text, $matches, PREG_SET_ORDER)) {
            $dates = array_filter(array_map(
                fn (array $match) => $this->parseSingleFrenchDate(trim($match[0])),
                $matches
            ));

            if ($dates) {
                return [min($dates), max($dates)];
            }
        }

        return [null, null];
    }
}
