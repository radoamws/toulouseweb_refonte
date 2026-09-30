<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\Concerns\FetchesHttp;
use App\Services\Scraping\Agenda\Concerns\ParsesFrenchDates;
use App\Services\Scraping\Agenda\Concerns\ResolvesCategory;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper agenda pour L'Escale (Tournefeuille) — demande client, 30/09/2026 :
 * remplace l'ancienne source (l'API JSON de la plateforme de billetterie
 * Ardei-Soft/VEL, voir git blame) par le VRAI site vitrine de la salle,
 * lescale-tournefeuille.fr, plus riche (adresse/prix/description par
 * spectacle, quasi absents côté API VEL).
 *
 * Le client a fourni les pistes CSS initiales (`.spectacle-item`, indices de
 * position pour les dates/la description...) mais avait lui-même prévenu que
 * ce n'étaient que des pistes, pas une garantie ("je te laisse analyser et
 * améliorer selon le DOM en standard"). Vérifié en direct le 30/09/2026 :
 *
 * - `https://lescale-tournefeuille.fr/spectacles/` ne contient AUCUNE carte
 *   `.spectacle-item` server-side (0 occurrence sur le HTML brut) : la liste
 *   est chargée en JavaScript/AJAX (calendrier filtrable), pas exploitable en
 *   scraping HTML classique. Le site expose en revanche une VRAIE API REST
 *   WordPress standard pour son custom post type "les_spectacles"
 *   (`/wp-json/wp/v2/les_spectacles`) — bien plus fiable (title/slug/link/
 *   image déjà structurés) que parser un listing Elementor. 242 entrées au
 *   total, dont la plupart taguées `etat_du_spectacle` "Saisons précédentes"
 *   (id 67) ou "Archives des rencontres et ateliers" (id 68) — exclues (ne
 *   laisse que ~78 spectacles de la saison en cours, cohérent avec le volume
 *   de l'ancienne source VEL).
 * - Les indices `h2.elementor-heading-title.elementor-size-default` donnés
 *   ("3ème et 4ème") ne correspondent PAS à la réalité : un bandeau
 *   d'annonce SITE-WIDE (`<header class="elementor-location-header">`,
 *   affichant parfois un message d'actu genre "CONCERT ANNULE...") utilise la
 *   MÊME classe et décale tous les index — cause probable de l'incertitude du
 *   client lui-même. Corrigé en scopant la recherche à
 *   `.elementor-location-single` (le vrai corps de la fiche) : les dates y
 *   sont alors systématiquement les 1 ou 2 PREMIERS `h2` de cette liste,
 *   suivis d'un `h2` "Galerie" qui sert de borne de fin stable.
 * - La description ("aucune distinction ni classe css distincte", confirmé)
 *   est identifiée par un indicateur plus fiable que sa position : parmi les
 *   blocs de premier niveau `.e-con-full.e-ecs-flex.e-flex.e-con.e-parent`
 *   AVANT "Galerie", en excluant le fil d'Ariane ("L'Escale > ..."), le bloc
 *   date/lieu (contient "Lieu") et le bloc tarifs (contient "Tarif"), c'est
 *   le bloc restant avec le PLUS de texte (vérifié sur 2 fiches réelles très
 *   différentes : synopsis classique de 1324 caractères sur une fiche,
 *   encart d'annulation de 992 caractères sur une autre — les deux fois le
 *   bloc le plus long est bien le bon).
 * - "Lieu :" est un `<p>` à PART ENTIÈRE (texte "Lieu : " seul, sans valeur)
 *   — la vraie valeur ("l'Escale", "Cinéma Utopia"...) est dans le
 *   `.elementor-widget-text-editor` SUIVANT (élément frère), jamais dans le
 *   même nœud.
 * - Réservation : le sélecteur du client
 *   (`a.elementor-button.elementor-button-link.elementor-size-sm`) matche 4
 *   boutons différents par fiche (billetterie, site de la compagnie, lien
 *   rencontres/ateliers, retour liste) — filtré sur le TEXTE "Billetterie"
 *   (stable sur les 2 fiches vérifiées) plutôt que la position. Pointe
 *   d'ailleurs vers... ardei-soft.com : confirme que ce nouveau site vitrine
 *   est un habillage au-dessus de la MÊME plateforme de billetterie que
 *   l'ancienne source.
 */
class EscaleDriver implements ScraperDriver
{
    use FetchesHttp;
    use ParsesFrenchDates;
    use ResolvesCategory;

    private const API_BASE = 'https://lescale-tournefeuille.fr/wp-json/wp/v2';

    /** Termes `etat_du_spectacle` signalant un spectacle passé/archivé — jamais scrapés (voir docblock de classe). */
    private const ARCHIVE_TERM_NAME_PATTERNS = ['précédente', 'archives'];

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $areaSlug = $config['area_slug'] ?? 'lescale-2';

        $area = Area::where('slug', $areaSlug)->first();
        if (! $area) {
            throw new \RuntimeException("Area (slug={$areaSlug}) introuvable — vérifier la config de la source.");
        }

        $typeTermNames = $this->fetchTaxonomyTermNames('type_de_spectacle');
        $archiveTermIds = $this->fetchArchiveTermIds();

        $shows = $this->fetchAllShows();
        if ($shows === null) {
            throw new \RuntimeException('Échec de récupération de l\'API WordPress ('.self::API_BASE.'/les_spectacles).');
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($shows as $show) {
            $etats = $show['etat_du_spectacle'] ?? [];
            if (array_intersect($etats, $archiveTermIds)) {
                // Spectacle d'une saison précédente / archive — jamais scrapé, voir docblock de classe.
                continue;
            }

            $stats['found']++;

            $externalRef = $show['slug'] ?? null;
            $title = html_entity_decode($show['title']['rendered'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $detailUrl = $show['link'] ?? null;

            if (! $externalRef || ! $title || ! $detailUrl) {
                $stats['skipped']++;

                continue;
            }

            $detail = $this->fetchDetail($detailUrl);
            if (! $detail || ! $detail['start_date']) {
                $stats['skipped']++;

                continue;
            }

            $image = $show['_embedded']['wp:featuredmedia'][0]['source_url'] ?? null;

            $existing = Event::where('external_ref', $externalRef)->exists();

            $event = Event::updateOrCreate(
                ['external_ref' => $externalRef],
                array_filter([
                    'area_id' => $area->id,
                    'title' => $title,
                    'description' => $detail['description'],
                    'image' => $image,
                    'price' => $detail['price'],
                    'schedule' => $detail['schedule'] ? [$detail['schedule']] : null,
                    'start_date' => $detail['start_date'],
                    'end_date' => $detail['end_date'],
                    'booking_url' => $detail['booking_url'],
                    'venue_name' => $detail['venue_name'],
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            $categoryIds = $this->resolveCategoryIds($show['type_de_spectacle'] ?? [], $typeTermNames);
            if ($categoryIds) {
                $event->categories()->syncWithoutDetaching($categoryIds);
            }

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    /** @return array<int,array<string,mixed>>|null */
    protected function fetchAllShows(): ?array
    {
        $all = [];

        for ($page = 1; $page <= 5; $page++) {
            $url = self::API_BASE."/les_spectacles?per_page=100&page={$page}&_embed=1";
            $payload = $this->fetchJson($url);

            if ($payload === null) {
                // La dernière page dépassant X-WP-TotalPages répond en 400 —
                // pas une vraie panne réseau si on a déjà des résultats.
                break;
            }

            if (empty($payload)) {
                break;
            }

            $all = array_merge($all, $payload);
        }

        return $all ?: null;
    }

    /** @return array<int,string> id de terme => nom */
    protected function fetchTaxonomyTermNames(string $taxonomy): array
    {
        $terms = $this->fetchJson(self::API_BASE."/{$taxonomy}?per_page=100") ?? [];

        return collect($terms)->pluck('name', 'id')->all();
    }

    /** @return int[] */
    protected function fetchArchiveTermIds(): array
    {
        $terms = $this->fetchJson(self::API_BASE.'/etat_du_spectacle?per_page=100') ?? [];

        return collect($terms)
            ->filter(function (array $term) {
                $name = mb_strtolower($term['name'] ?? '');

                foreach (self::ARCHIVE_TERM_NAME_PATTERNS as $pattern) {
                    if (str_contains($name, $pattern)) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->all();
    }

    /**
     * @param  int[]  $termIds
     * @param  array<int,string>  $termNames
     * @return int[]
     */
    protected function resolveCategoryIds(array $termIds, array $termNames): array
    {
        $ids = collect();

        foreach ($termIds as $termId) {
            $name = $termNames[$termId] ?? null;
            // "Spectacle" est un terme fourre-tout appliqué à quasi tous les
            // shows (167/242 constatés en direct) — sans valeur de
            // catégorisation propre, exclu pour ne pas fausser le fallback.
            if (! $name || mb_strtolower($name) === 'spectacle') {
                continue;
            }

            $ids = $ids->merge($this->categoriesMatchingLabel($name)->pluck('id'));
        }

        $ids = $ids->unique()->values();

        if ($ids->isEmpty()) {
            $fallback = $this->categoryByLegacyId(7);

            return $fallback ? [$fallback->id] : [];
        }

        return $ids->all();
    }

    /** @return array{description:?string,price:?string,schedule:?string,venue_name:?string,booking_url:?string,start_date:?Carbon,end_date:?Carbon}|null */
    protected function fetchDetail(string $url): ?array
    {
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $crawler = new Crawler($html);
        $body = $crawler->filter('.elementor-location-single')->first();
        if ($body->count() === 0) {
            return null;
        }

        [$startDate, $endDate] = $this->extractDates($body);
        $dateVenueBlock = $this->findDateVenueBlock($body) ?? $body;

        return [
            'description' => $this->extractDescription($body),
            'price' => $this->extractPrice($body),
            'schedule' => $this->extractSchedule($dateVenueBlock),
            'venue_name' => $this->extractVenueName($dateVenueBlock),
            'booking_url' => $this->extractBookingUrl($body),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    /**
     * Dates = les h2.elementor-size-default AVANT le h2 "Galerie" (borne de
     * fin stable, toujours présent) — 1 seul (date unique) ou 2 (plage).
     * Quand il y en a 2, le PREMIER n'a souvent ni mois ni année dans le DOM
     * ("30 septembre" pour une plage qui finit "4 octobre 2026") : on lui
     * emprunte le mois/l'année du second avant de reparser. Voir docblock de
     * classe pour le pourquoi du scope `.elementor-location-single`.
     *
     * @return array{0:?Carbon,1:?Carbon}
     */
    protected function extractDates(Crawler $body): array
    {
        $texts = $body->filter('h2.elementor-heading-title.elementor-size-default')
            ->each(fn (Crawler $n) => trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $n->text(''))) ?? ''));

        $galerieIndex = array_search('Galerie', $texts, true);
        $dateTexts = $galerieIndex === false ? $texts : array_slice($texts, 0, $galerieIndex);
        $dateTexts = array_values(array_filter($dateTexts, fn ($t) => $t !== ''));

        if (count($dateTexts) === 0) {
            return [null, null];
        }

        if (count($dateTexts) === 1) {
            $single = $this->parseSingleFrenchDate($dateTexts[0]);

            return [$single, $single];
        }

        $end = $this->parseSingleFrenchDate($dateTexts[1]);
        if (! $end) {
            return [null, null];
        }

        // Le début précède toujours la fin dans une plage affichée ("30
        // septembre – 4 octobre 2026") : on lui emprunte directement
        // l'année (et le mois s'il manque aussi) de la fin, texte reconstruit
        // AVANT reparsing plutôt que via les paramètres $yearHint/
        // $referenceMonth de parseSingleFrenchDate() — leur heuristique
        // "mois < référence => année suivante" suppose l'inverse (la fin
        // empruntant au début), et donnait ici +1 an à tort (septembre(9) <
        // octobre(10) déclenchait le bascule alors qu'il n'y a jamais de
        // changement d'année entre les deux bornes d'une même plage).
        $startRaw = preg_match('/[a-zA-Zéûôîâ]/u', $dateTexts[0])
            ? $dateTexts[0].' '.$end->year
            : $dateTexts[0].' '.$this->monthNameFromCarbon($end).' '.$end->year;

        $start = $this->parseSingleFrenchDate($startRaw);

        return [$start ?? $end, $end];
    }

    /**
     * Remonte depuis le premier h2 de date jusqu'à son ancêtre bloc
     * `.e-con-full...e-parent` — c'est CE bloc (pas toute la fiche) qui
     * contient aussi l'horaire et le "Lieu :", constaté en direct : sans ce
     * scope, la recherche du premier `.elementor-widget-text-editor` de
     * toute la fiche remontait par erreur un texte d'un bloc plus HAUT dans
     * le document (ex. "Cie Grenier de Toulouse", le sous-titre de la
     * fiche) au lieu du véritable horaire.
     */
    protected function findDateVenueBlock(Crawler $body): ?Crawler
    {
        $dateH2 = $body->filter('h2.elementor-heading-title.elementor-size-default')->first();
        if ($dateH2->count() === 0) {
            return null;
        }

        $node = $dateH2->getNode(0)->parentNode;
        while ($node instanceof \DOMElement) {
            $class = $node->getAttribute('class');
            if (str_contains($class, 'e-con-full') && str_contains($class, 'e-parent')) {
                return new Crawler($node);
            }
            $node = $node->parentNode;
        }

        return null;
    }

    private const FRENCH_MONTH_NAMES = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    protected function monthNameFromCarbon(Carbon $date): string
    {
        return self::FRENCH_MONTH_NAMES[$date->month];
    }

    /**
     * Parmi les blocs de premier niveau avant "Galerie", en excluant le fil
     * d'Ariane / le bloc date-lieu / le bloc tarifs, le bloc restant avec le
     * plus de texte — voir docblock de classe pour la vérification en direct
     * sur 2 fiches très différentes.
     */
    protected function extractDescription(Crawler $body): ?string
    {
        $blocks = $body->filter('.e-con-full.e-ecs-flex.e-flex.e-con.e-parent');

        $galerieBlockIndex = null;
        $blocks->each(function (Crawler $n, int $i) use (&$galerieBlockIndex) {
            if ($galerieBlockIndex === null && trim($n->text('')) === 'Galerie') {
                $galerieBlockIndex = $i;
            }
        });

        $best = null;
        $bestLength = 0;

        $blocks->each(function (Crawler $n, int $i) use ($galerieBlockIndex, &$best, &$bestLength) {
            if ($galerieBlockIndex !== null && $i >= $galerieBlockIndex) {
                return;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $n->text('')) ?? '');

            if ($text === '' || str_starts_with($text, "L'Escale >") || str_starts_with($text, 'L’Escale >')) {
                return;
            }
            if (str_contains($text, 'Lieu :') || str_starts_with($text, 'Tarif')) {
                return;
            }

            if (mb_strlen($text) > $bestLength) {
                $bestLength = mb_strlen($text);
                $best = $text;
            }
        });

        return $best && $bestLength >= 20 ? $best : null;
    }

    /** Toutes les lignes ".elementor-widget-text-editor" commençant par "Tarif", jointes — voir docblock de classe. */
    protected function extractPrice(Crawler $body): ?string
    {
        $lines = $body->filter('.elementor-widget-text-editor .elementor-widget-container')
            ->each(fn (Crawler $n) => trim(preg_replace('/\s+/u', ' ', $n->text('')) ?? ''));

        $priceLines = array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'Tarif')));

        if (! $priceLines) {
            return null;
        }

        $joined = implode(' | ', $priceLines);

        return mb_substr($joined, 0, 255) ?: null;
    }

    /**
     * Le "Lieu : " est un `<p>` à part entière, la vraie valeur est dans le
     * `.elementor-widget-text-editor` FRÈRE suivant — voir docblock de classe.
     */
    protected function extractVenueName(Crawler $body): ?string
    {
        $containers = $body->filter('.elementor-widget-text-editor');
        $found = null;

        $containers->each(function (Crawler $n, int $i) use ($containers, &$found) {
            if ($found !== null) {
                return;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $n->text('')) ?? '');
            if (! preg_match('/^Lieu\s*:?\s*$/ui', $text)) {
                return;
            }

            if ($i + 1 < $containers->count()) {
                $next = trim(preg_replace('/\s+/u', ' ', $containers->eq($i + 1)->text('')) ?? '');
                $found = $next !== '' ? $next : null;
            }
        });

        return $found;
    }

    /**
     * Horaire(s) affiché(s) juste après la/les date(s), avant "Lieu :" —
     * texte libre ("Du mardi au samedi 20H30 – Dimanche 16H00", "19h"...).
     */
    protected function extractSchedule(Crawler $body): ?string
    {
        $containers = $body->filter('.elementor-widget-text-editor .elementor-widget-container');

        foreach ($containers as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', (new Crawler($node))->text('')) ?? '');
            if ($text === '') {
                continue;
            }
            if (preg_match('/^Lieu\s*:?\s*$/ui', $text) || str_starts_with($text, 'Tarif')) {
                return null;
            }

            return $text;
        }

        return null;
    }

    /** Le bouton dont le TEXTE est "Billetterie" (pas la position, voir docblock de classe). */
    protected function extractBookingUrl(Crawler $body): ?string
    {
        $found = null;

        $body->filter('a.elementor-button')->each(function (Crawler $n) use (&$found) {
            if ($found === null && trim($n->text('')) === 'Billetterie') {
                $found = $n->attr('href') ?: null;
            }
        });

        return $found;
    }
}
