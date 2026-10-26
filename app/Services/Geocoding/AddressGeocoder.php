<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Géocodage d'adresses/rues/quartiers via la Base Adresse Nationale (BAN,
 * api-adresse.data.gouv.fr) — brief roadmap "contenu", demande client
 * 26/10/2026 : "le client veut que le choix entre 2 rues se fasse, pas
 * juste entre 2 arrêts de bus [...] des fois les gens veulent juste aller
 * entre 2 quartiers sans savoir l'arrêt". Service public gratuit, sans clé
 * API, maintenu par l'État — même esprit "open data gratuit" que
 * data.toulouse-metropole.fr/Tisséo/Open-Meteo déjà utilisés dans ce
 * projet. Vérifié en direct le 26/10/2026 (rues et quartiers toulousains
 * réels correctement résolus, ex. "Saint-Cyprien Toulouse", ~900ms de
 * latence typique).
 *
 * `lat`/`lon` de biais (Capitole, centre de Toulouse) plutôt qu'un filtre
 * dur par `citycode` : le réseau Tisséo dépasse Toulouse-ville (Blagnac,
 * Colomiers, Balma...), un filtre strict exclurait à tort ces communes —
 * la pertinence géographique réelle est de toute façon filtrée ENSUITE par
 * la recherche d'arrêt le plus proche (voir
 * TransportController::nearestStopIds()), pas par ce biais de recherche
 * d'adresse lui-même. Vérifié en direct que ce biais fonctionne bien
 * (ex. "rue de la paix", nom de rue très courant en France, renvoie en
 * 1er résultat celle de Toulouse plutôt que Montauban/Castres).
 *
 * Résultats mis en cache 1 semaine (une rue ne change pas de coordonnées) —
 * SEULS les succès sont mis en cache : jamais un échec réseau transitoire,
 * qui doit pouvoir être retenté à la prochaine recherche.
 */
class AddressGeocoder
{
    private const ENDPOINT = 'https://api-adresse.data.gouv.fr/search/';

    // Capitole, centre géographique de référence pour biaiser les résultats.
    private const BIAS_LAT = 43.6045;

    private const BIAS_LON = 1.4442;

    private const CACHE_DAYS = 7;

    /** @return array{lat: float, lon: float, label: string}|null */
    public function geocode(string $query): ?array
    {
        $cacheKey = 'geocode:'.md5(Str::lower(trim($query)));

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetch($query);
        if ($result !== null) {
            Cache::put($cacheKey, $result, now()->addDays(self::CACHE_DAYS));
        }

        return $result;
    }

    /** @return array{lat: float, lon: float, label: string}|null */
    private function fetch(string $query): ?array
    {
        try {
            $response = Http::timeout(5)->get(self::ENDPOINT, [
                'q' => $query,
                'lat' => self::BIAS_LAT,
                'lon' => self::BIAS_LON,
                'limit' => 1,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $feature = $response->json('features.0');
            if (! $feature || ! isset($feature['geometry']['coordinates'])) {
                return null;
            }

            [$lon, $lat] = $feature['geometry']['coordinates'];

            return [
                'lat' => (float) $lat,
                'lon' => (float) $lon,
                'label' => $feature['properties']['label'] ?? $query,
            ];
        } catch (\Throwable $e) {
            Log::channel('single')->warning("AddressGeocoder — erreur réseau : {$e->getMessage()}");

            return null;
        }
    }
}
