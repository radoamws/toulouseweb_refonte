<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Purge du cache Cloudflare (demande client, TECHNICAL_DOCUMENTATION.md
 * §17) — réécrit `SharedController::purgeEntityCache()`/
 * `purgeSingleCloudflareCacheNoEncode()` du legacy
 * (old/backEnd/app/Http/Controllers/SharedController.php lignes ~1149-1286),
 * qui n'était réellement câblé que sur 3 entités (`slider`, `news`,
 * `agenda` — vérifié : l'appel générique dans BOController.php était mort,
 * commenté). Ici, TOUS les modèles de contenu public (News, Event, Listing,
 * Classified, Movie, Cinema, Slider, Page) sont couverts, voir
 * App\Observers\CloudflarePurgeObserver.
 *
 * Auth par jeton API (Bearer), pas le couple email + clé API globale utilisé
 * par le legacy (identifiants en clair dans le code, moins sûr et
 * deprecated côté Cloudflare) — un jeton scopé "Zone.Cache Purge" sur la
 * seule zone du site limite les dégâts en cas de fuite.
 *
 * Traite ses appels en 2 temps plutôt qu'un appel HTTP par modèle sauvegardé
 * (voir docblock de App\Observers\CloudflarePurgeObserver pour le pourquoi) :
 * `queue()` accumule des URLs en mémoire (dédoublonnées) pendant toute la
 * durée d'une requête HTTP ou d'une commande artisan, `flush()` envoie tout
 * en une seule fois (par lots de 30 URLs, limite de l'API Cloudflare pour un
 * appel `purge_cache` par liste de fichiers) — appelé automatiquement à la
 * fin du processus via `app()->terminating()` (voir AppServiceProvider).
 *
 * Ne lève jamais d'exception : une purge de cache manquée est un défaut de
 * fraîcheur temporaire (le cache Cloudflare a de toute façon un TTL), pas une
 * raison de faire échouer une sauvegarde admin ou un scraping cron.
 */
class CloudflareCachePurger
{
    /** URLs en attente, dédoublonnées (clé = URL). */
    protected array $queuedUrls = [];

    /** Nombre max d'URLs par appel `purge_cache` (limite documentée de l'API Cloudflare). */
    protected const CHUNK_SIZE = 30;

    public function queue(array $urls): void
    {
        foreach (array_filter($urls) as $url) {
            $this->queuedUrls[$url] = true;
        }
    }

    public function flush(): void
    {
        if ($this->queuedUrls === []) {
            return;
        }

        $urls = array_keys($this->queuedUrls);
        $this->queuedUrls = [];

        if (! config('services.cloudflare.enabled')) {
            Log::channel('single')->debug('CloudflareCachePurger — purge désactivée (CLOUDFLARE_CACHE_PURGE_ENABLED), URLs ignorées : '.implode(', ', $urls));

            return;
        }

        $zoneId = config('services.cloudflare.zone_id');
        $apiToken = config('services.cloudflare.api_token');

        if (! $zoneId || ! $apiToken) {
            Log::channel('single')->warning('CloudflareCachePurger — activée mais CLOUDFLARE_ZONE_ID/CLOUDFLARE_API_TOKEN manquants, purge ignorée.');

            return;
        }

        foreach (array_chunk($urls, self::CHUNK_SIZE) as $chunk) {
            $this->purgeChunk($zoneId, $apiToken, $chunk);
        }
    }

    protected function purgeChunk(string $zoneId, string $apiToken, array $urls): void
    {
        try {
            $response = Http::withToken($apiToken)
                ->timeout(10)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache", [
                    'files' => $urls,
                ]);

            if (! $response->successful() || ! ($response->json('success') ?? false)) {
                Log::channel('single')->warning('CloudflareCachePurger — échec purge_cache : '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::channel('single')->warning('CloudflareCachePurger — erreur réseau : '.$e->getMessage());
        }
    }
}
