<?php

namespace App\Services\Scraping\Agenda;

use App\Models\Area;
use App\Models\Event;
use App\Models\ScraperSource;
use App\Services\Scraping\Agenda\Concerns\FetchesHttp;
use App\Services\Scraping\Agenda\Concerns\ResolvesCategory;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;

/**
 * Scraper agenda pour Le Bijou (le-bijou.soticket.net) — reconstruit depuis
 * le VRAI code legacy `updateAgendaforBijou` (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~4561).
 *
 * Source : API JSON de la billetterie Soticket —
 * `GET /api/v2/shows?offset=0&limit=100&next=1`, authentifiée par un jeton
 * Bearer JWT codé en dur côté legacy. Décodage du payload JWT : `exp` vaut
 * 4864031311 (~an 2124) — un jeton pratiquement non expirant, donc repris tel
 * quel par défaut ici, MAIS rendu configurable
 * (`scraper_sources.config.bearer_token`) car le commentaire du code legacy
 * indique explicitement une procédure de renouvellement manuel : "si ne
 * fonctionne pas, lancer https://le-bijou.soticket.net/agenda dans le
 * navigateur et récupérer/changer le bearer" — donc un jeton potentiellement
 * révocable côté Soticket indépendamment de sa date d'expiration déclarée.
 *
 * NE PAS confondre avec `updateAgendaforBikini`/`updateAgendaforBikini2` du
 * legacy — un lieu DIFFÉRENT (lebikini.com, "Bikini", id_area=73), absent de
 * la liste de crons réelle fournie par le client et donc non repris ici.
 *
 * Catégorie legacy hardcodée à 7 (Spectacles).
 *
 * area_slug par défaut résolu via `areas.legacy_id = 10` : "Le Bijou"
 * (slug `le-bijou`).
 */
class BijouDriver implements ScraperDriver
{
    use FetchesHttp;
    use ResolvesCategory;

    /** Voir docblock de classe — jeton legacy, quasi non expirant mais révocable. */
    private const DEFAULT_BEARER_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJDdXN0b21lciBkZWZhdWx0IFRva2VuIiwiYXVkIjoiQ3VzdG9tZXIiLCJpYXQiOjE3MDgzNTc3MTEsInN1YiI6MCwiZXhwIjo0ODY0MDMxMzExfQ.EXVvqhh3L86KCuOQiXdIW_KfThnAsTd4Ej9zZUQkSiosFvt1ViYFW9SUmMfenCO_ZbBJkDBT-Th62kSYi64EhQiX9Ou1ajOknKfKnUVEmzLrffjT8z58lpmxuc6Z1ruXKuW_jteFZsKNYj29wlOkiBO4adm5fwylochG9E2KRrk';

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $apiUrl = $config['api_url'] ?? 'https://le-bijou.soticket.net/api/v2/shows?offset=0&limit=100&next=1';
        $areaSlug = $config['area_slug'] ?? 'le-bijou';
        $token = $config['bearer_token'] ?? self::DEFAULT_BEARER_TOKEN;

        $area = Area::where('slug', $areaSlug)->first();
        if (! $area) {
            throw new \RuntimeException("Area (slug={$areaSlug}) introuvable — vérifier la config de la source.");
        }

        $category = $this->categoryByLegacyId(7, 'spectacles');

        $payload = $this->fetchJson($apiUrl, ['Authorization' => 'Bearer '.$token]);
        if ($payload === null) {
            throw new \RuntimeException("Échec de récupération de l'API Soticket ({$apiUrl}) — le jeton Bearer a peut-être expiré/été révoqué, voir docblock de classe.");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($payload['data'] ?? [] as $show) {
            $stats['found']++;

            $slug = $show['slug'] ?? null;
            $title = $show['edito']['title'] ?? null;

            if (! $slug || ! $title) {
                $stats['skipped']++;

                continue;
            }

            $startTimestamp = $show['start_date'] ?? null;
            if (! $startTimestamp) {
                $stats['skipped']++;

                continue;
            }

            [$subtitle, $description] = $this->splitTitle($title);

            $endTimestamp = ! empty($show['end_date']) ? $show['end_date'] : $startTimestamp;

            $existing = Event::where('external_ref', $slug)->exists();

            $event = Event::updateOrCreate(
                ['external_ref' => $slug],
                array_filter([
                    'area_id' => $area->id,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'description' => $description,
                    'price' => $this->extractPrice($show['sessions'] ?? []),
                    'schedule' => $this->extractSchedule($show['sessions'] ?? []),
                    'image' => $show['picture']['src'] ?? null,
                    'start_date' => Carbon::createFromTimestamp($startTimestamp),
                    'end_date' => Carbon::createFromTimestamp($endTimestamp),
                    'booking_url' => "https://le-bijou.soticket.net/agenda/{$slug}",
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

    /** @return array{0: ?string, 1: ?string} [subtitle, description] */
    protected function splitTitle(string $title): array
    {
        $parts = explode('-', $title);
        if (count($parts) === 3) {
            return [trim($parts[1]), trim($parts[2])];
        }

        return [$title, $title];
    }

    protected function extractPrice(array $sessions): ?string
    {
        $prices = [];

        foreach ($sessions as $session) {
            $rangeTitle = $session['range']['title'] ?? null;
            if (! $rangeTitle) {
                continue;
            }

            $amounts = array_filter(explode('/', $rangeTitle), fn ($p) => ctype_digit(trim($p)));
            if ($amounts) {
                $prices[] = implode(' - ', array_map(fn ($p) => trim($p).'€', $amounts));
            }
        }

        return $prices ? implode(' / ', array_unique($prices)) : null;
    }

    /**
     * Une entrée par séance (06/09/2026, corrigé suite à l'audit §18 de
     * TECHNICAL_DOCUMENTATION.md) — le legacy concatène chaque horaire de
     * séance dans une seule chaîne " / "-séparée (ligne ~4655), reproduite
     * ici en tableau (un horaire par entrée), cohérent avec le cast `array`
     * d'`Event.schedule` et plus exploitable côté front qu'une chaîne plate
     * — silencieusement jamais reporté avant ce correctif, alors que Le
     * Bijou (un club, plusieurs séances/soir courantes) est la salle où
     * cette perte était la plus impactante des 5 concernées par l'audit.
     *
     * @return string[]|null
     */
    protected function extractSchedule(array $sessions): ?array
    {
        $schedule = [];

        foreach ($sessions as $session) {
            if (empty($session['start_date'])) {
                continue;
            }

            $date = Carbon::createFromTimestamp($session['start_date']);
            if (! empty($session['time_zone'])) {
                $date = $date->setTimezone($session['time_zone']);
            }

            $schedule[] = $date->format('Y-m-d H:i:s');
        }

        return $schedule ?: null;
    }
}
