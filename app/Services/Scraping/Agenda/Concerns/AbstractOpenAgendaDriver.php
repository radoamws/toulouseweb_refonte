<?php

namespace App\Services\Scraping\Agenda\Concerns;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\ScraperSource;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;

/**
 * Base commune aux drivers utilisant l'API publique OpenAgenda
 * (`https://openagenda.com/api/agendas/slug/{slug}/events`), telle
 * qu'utilisée par le VRAI code legacy pour deux salles au moins
 * (`updateAgendaforZenith` et `updateAgendaforMetropole`, voir
 * old/backEnd/app/Http/Controllers/AgendaController.php lignes ~988 et
 * ~4387) — une API JSON propre, sans reverse-engineering nécessaire.
 */
abstract class AbstractOpenAgendaDriver implements ScraperDriver
{
    use FetchesHttp;
    use ResolvesCategory;

    abstract protected function defaultOpenAgendaSlug(): string;

    abstract protected function defaultAreaSlug(): string;

    /** @return int[] ids de App\Models\EventCategory à attacher à cet événement */
    protected function resolveCategoryIds(array $event, array $payload): array
    {
        $category = EventCategory::where('slug', 'divers')->first();

        return $category ? [$category->id] : [];
    }

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $slug = $config['openagenda_slug'] ?? $this->defaultOpenAgendaSlug();
        $areaSlug = $config['area_slug'] ?? $this->defaultAreaSlug();

        $area = Area::where('slug', $areaSlug)->first();
        if (! $area) {
            throw new \RuntimeException("Area (slug={$areaSlug}) introuvable — vérifier la config de la source.");
        }

        // NOTE : le legacy utilisait `aggsSizeLimit=1500&size=500`, mais l'API
        // OpenAgenda a depuis durci sa limite ("size must not exceed 300",
        // vérifié en direct le 25/08/2026) — 500 renvoie une erreur 400. On
        // plafonne donc à 300 ; voir TECHNICAL_DOCUMENTATION.md §13 pour la
        // conséquence (pagination non implémentée, seuls les ~300 prochains
        // événements sont couverts par exécution).
        $apiUrl = $config['api_url'] ?? "https://openagenda.com/api/agendas/slug/{$slug}/events?aggsSizeLimit=300&size=300";

        $payload = $this->fetchJson($apiUrl);
        if ($payload === null) {
            throw new \RuntimeException("Échec de récupération de l'API OpenAgenda ({$apiUrl}).");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $events = array_filter($payload['events'] ?? [], fn (array $event) => ! empty($event['nextTiming']));

        foreach ($events as $event) {
            $stats['found']++;

            $externalRef = $event['slug'] ?? null;
            $title = $event['title']['fr'] ?? null;

            if (! $externalRef || ! $title) {
                $stats['skipped']++;

                continue;
            }

            [$start, $end] = $this->extractDates($event);
            if (! $start) {
                $stats['skipped']++;

                continue;
            }

            $existing = Event::where('external_ref', $externalRef)->exists();

            $ev = Event::updateOrCreate(
                ['external_ref' => $externalRef],
                array_filter([
                    'area_id' => $area->id,
                    'title' => $title,
                    'description' => $event['description']['fr'] ?? null,
                    'image' => $this->extractImage($event),
                    'start_date' => $start,
                    'end_date' => $end,
                    'booking_url' => "https://openagenda.com/{$slug}/events/{$externalRef}",
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            $categoryIds = $this->resolveCategoryIds($event, $payload);
            if ($categoryIds) {
                $ev->categories()->syncWithoutDetaching($categoryIds);
            }

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    protected function extractDates(array $event): array
    {
        $begin = $event['nextTiming']['begin'] ?? $event['firstTiming']['begin'] ?? null;
        $end = $event['nextTiming']['end'] ?? $event['lastTiming']['end'] ?? $begin;

        try {
            $start = $begin ? Carbon::parse($begin) : null;
            $endDate = $end ? Carbon::parse($end) : $start;
        } catch (\Throwable) {
            return [null, null];
        }

        return [$start, $endDate];
    }

    protected function extractImage(array $event): ?string
    {
        $image = $event['image'] ?? null;
        if (! is_array($image)) {
            return null;
        }

        $filename = $image['filename'] ?? ($image['variants'][0]['filename'] ?? null);
        if (! $filename) {
            return null;
        }

        // Le legacy construisait l'URL avec un domaine codé en dur
        // ("https://images.openagenda.com/") qui ne correspond plus à la
        // vraie réponse API (`image.base`, ex. "https://img.openagenda.com/main/",
        // vérifié en direct le 25/08/2026) — on privilégie `base` quand
        // présent, avec repli sur l'ancien domaine si absent.
        $base = $image['base'] ?? 'https://images.openagenda.com/';

        return $base.$filename;
    }
}
