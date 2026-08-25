<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper agenda pour le Théâtre de la Cité (theatre-cite.com) — brief §7/§15
 * ("Repérer les 404 fréquentes"... non, voir plutôt §6/§21 : scraping agenda).
 *
 * IMPORTANT — contexte de cette implémentation (voir TECHNICAL_DOCUMENTATION.md
 * §13) : l'audit initial n'avait trouvé aucun scraper agenda fonctionnel dans
 * le code legacy (`t_agenda_scrapping` listait des noms de méthode —
 * `updateAgendaforZenith` etc. — référencés par des routes, mais AUCUNE de
 * ces méthodes n'existe dans `AgendaController.php`, ni ailleurs dans
 * `old/backEnd/`). Sur demande du client, deuxième passage : les valeurs
 * réelles et récentes de `t_agendas.lien_detail` (URLs de détail d'événements,
 * datées de la saison 2026-2027 — donc alimentées EN PRODUCTION, par un
 * mécanisme absent de cette copie du dépôt) pointent vers plusieurs vrais
 * sites de salles (theatre-cite.com, leventdessignes.fr, ardei-soft.com,
 * le-bijou.soticket.net...). Le CODE de ce mécanisme reste introuvable — mais
 * les VRAIS sites cibles, eux, sont accessibles depuis cet environnement
 * (contrairement à AlloCiné/Pathé-Gaumont, bloqués). Ce driver a donc été
 * construit et **vérifié en direct** contre le vrai site, pas deviné à
 * l'aveugle — voir la sélection de classes CSS ci-dessous, extraites en
 * inspectant réellement le HTML retourné (`programmation-grid__item--evenements`,
 * `spectacle__informations__content__line`...).
 *
 * Portée volontairement limitée à CE site pour cette phase : theatre-cite.com
 * est un site propre, rendu côté serveur, avec des classes CSS stables — le
 * candidat le plus fiable parmi les sources identifiées. Les autres restent
 * à construire au cas par cas (voir docs) :
 *   - ardei-soft.com (le plus gros volume réel, ~92 événements valides) :
 *     contenu chargé en JavaScript via une plateforme de billetterie
 *     propriétaire ("VEL"), endpoint obfusqué (`/SenousritPGI?JAVOPP=...`)
 *     — nécessiterait une rétro-ingénierie substantielle, non tentée ici.
 *   - le-bijou.soticket.net, leventdessignes.fr : non encore investigués en
 *     profondeur (accessibles, structure non inspectée).
 */
class TheatreDeLaCiteDriver implements ScraperDriver
{
    private const DEFAULT_LISTING_URL = 'https://theatre-cite.com/programmation';

    private const FRENCH_MONTHS = [
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
    ];

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $listingUrl = $config['listing_url'] ?? self::DEFAULT_LISTING_URL;
        $areaSlug = $config['area_slug'] ?? 'tnt-theatre-de-la-cite';
        $categorySlug = $config['event_category_slug'] ?? 'theatre';

        $area = Area::where('slug', $areaSlug)->first();
        $category = EventCategory::where('slug', $categorySlug)->first();

        if (! $area || ! $category) {
            throw new \RuntimeException("Area (slug={$areaSlug}) ou EventCategory (slug={$categorySlug}) introuvable — vérifier la config de la source.");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $html = $this->fetch($listingUrl);
        if ($html === null) {
            throw new \RuntimeException("Échec de récupération de la page de programmation ({$listingUrl}).");
        }

        $crawler = new Crawler($html);
        $cards = $crawler->filter('.programmation-grid__item--evenements');

        foreach ($cards as $node) {
            $card = new Crawler($node);
            $stats['found']++;

            $link = $card->filter('a')->first();
            if ($link->count() === 0) {
                $stats['skipped']++;

                continue;
            }

            $href = $link->attr('href');
            $externalRef = trim((string) parse_url($href, PHP_URL_PATH), '/');
            $externalRef = basename($externalRef) ?: null;

            $title = trim($card->filter('.programmation-grid__item__title__inner')->count()
                ? $card->filter('.programmation-grid__item__title__inner')->text('')
                : (string) $link->attr('title'));

            if (! $externalRef || ! $title) {
                $stats['skipped']++;

                continue;
            }

            $startDate = $this->extractDate($card);
            if (! $startDate) {
                Log::channel('single')->warning("scrape:events (Théâtre de la Cité) — date illisible pour \"{$title}\" ({$href}), ignoré.");
                $stats['skipped']++;

                continue;
            }

            $image = $card->filter('img.desktop-image')->count()
                ? $card->filter('img.desktop-image')->attr('data-original')
                : null;

            $detail = $this->fetchDetail($href);

            $existing = Event::where('external_ref', $externalRef)->exists();

            $event = Event::updateOrCreate(
                ['external_ref' => $externalRef],
                array_filter([
                    'area_id' => $area->id,
                    'title' => $title,
                    'image' => $image,
                    'start_date' => $startDate,
                    'end_date' => $startDate,
                    'booking_url' => $detail['booking_url'] ?? null,
                    'price' => $detail['price_text'] ?? null,
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );
            $event->categories()->syncWithoutDetaching([$category->id]);

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    protected function extractDate(Crawler $card): ?Carbon
    {
        $dateNode = $card->filter('.programmation-grid__item__date');
        if ($dateNode->count() === 0) {
            return null;
        }

        $timeText = null;
        $timeNode = $dateNode->filter('.period-heure');
        if ($timeNode->count()) {
            $timeText = trim($timeNode->text(''));
        }

        $fullText = preg_replace('/\s+/u', ' ', trim($dateNode->text('')));
        $dateOnlyText = $timeText ? trim(str_replace($timeText, '', $fullText)) : $fullText;

        if (! preg_match('/(\d{1,2})\s+([a-zéû]+)\s+(\d{4})/ui', $dateOnlyText, $matches)) {
            return null;
        }

        $month = self::FRENCH_MONTHS[mb_strtolower($matches[2])] ?? null;
        if (! $month) {
            return null;
        }

        try {
            $date = Carbon::create((int) $matches[3], $month, (int) $matches[1]);
        } catch (\Throwable) {
            return null;
        }

        if ($timeText && preg_match('/(\d{1,2}):(\d{2})/', $timeText, $timeMatches)) {
            $date->setTime((int) $timeMatches[1], (int) $timeMatches[2]);
        }

        return $date;
    }

    /** @return array{booking_url: ?string, price_text: ?string}|null */
    protected function fetchDetail(string $url): ?array
    {
        $html = $this->fetch($url);
        if ($html === null) {
            return null;
        }

        $crawler = new Crawler($html);

        $bookingUrl = null;
        foreach ($crawler->filter('a') as $node) {
            if (str_contains((new Crawler($node))->text(''), 'Réserver')) {
                $bookingUrl = $node->getAttribute('href') ?: null;

                break;
            }
        }

        $priceText = null;
        $infoLines = $crawler->filter('.spectacle__informations__content__line');
        if ($infoLines->count()) {
            // La première ligne mélange date/lieu/durée/tarif en texte libre
            // côté site (pas de champ prix isolé) — on la garde telle quelle,
            // c'est déjà l'information affichée aux visiteurs sur le site source.
            $priceText = trim(preg_replace('/\s+/u', ' ', $infoLines->first()->text('')));
            $priceText = mb_substr($priceText, 0, 255) ?: null;
        }

        return ['booking_url' => $bookingUrl, 'price_text' => $priceText];
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            ])->timeout(20)->retry(2, 500)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::channel('single')->warning("scrape:events (Théâtre de la Cité) — erreur réseau sur {$url} : {$e->getMessage()}");

            return null;
        }
    }
}
