<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Météo + qualité de l'air de Toulouse pour le widget homepage (brief
 * roadmap "contenu — élargir la couverture", 14/10/2026, demande client).
 * Open-Meteo : gratuit, sans clé API pour cet usage non commercial/faible
 * volume (un seul point géographique, mis en cache — voir `current()`).
 *
 * Deux appels indépendants (prévisions + qualité de l'air, deux hôtes
 * distincts chez Open-Meteo) : un widget d'usage quotidien ne doit JAMAIS
 * faire planter ou ralentir la home si l'un des deux services est
 * indisponible — `current()` retourne `null` dans ce cas (widget
 * simplement absent, voir `x-site.weather-widget`), jamais d'exception
 * remontée à l'appelant.
 */
class OpenMeteoService
{
    private const LATITUDE = 43.6045;

    private const LONGITUDE = 1.4442;

    private const CACHE_KEY = 'homepage-weather';

    /**
     * Rafraîchi toutes les 30 min (brief : "widget simple", pas un besoin de
     * précision à la minute) — limite très large de toute façon sur le
     * plan gratuit (voir docblock de la classe).
     */
    private const CACHE_MINUTES = 30;

    /**
     * Table officielle des codes "WMO Weather interpretation codes" utilisée
     * par Open-Meteo (https://open-meteo.com/en/docs, section "WMO Weather
     * interpretation codes (WW)") — regroupée ici par famille visuelle
     * (icône) plutôt qu'un libellé par code exact, un widget homepage n'a
     * pas besoin de distinguer "pluie modérée" de "pluie forte".
     */
    private const WEATHER_CODES = [
        0 => ['label' => 'Ciel dégagé', 'icon' => 'clear'],
        1 => ['label' => 'Plutôt dégagé', 'icon' => 'clear'],
        2 => ['label' => 'Partiellement nuageux', 'icon' => 'cloudy'],
        3 => ['label' => 'Couvert', 'icon' => 'cloudy'],
        45 => ['label' => 'Brouillard', 'icon' => 'fog'],
        48 => ['label' => 'Brouillard givrant', 'icon' => 'fog'],
        51 => ['label' => 'Bruine légère', 'icon' => 'rain'],
        53 => ['label' => 'Bruine', 'icon' => 'rain'],
        55 => ['label' => 'Bruine dense', 'icon' => 'rain'],
        56 => ['label' => 'Bruine verglaçante', 'icon' => 'rain'],
        57 => ['label' => 'Bruine verglaçante dense', 'icon' => 'rain'],
        61 => ['label' => 'Pluie légère', 'icon' => 'rain'],
        63 => ['label' => 'Pluie', 'icon' => 'rain'],
        65 => ['label' => 'Pluie forte', 'icon' => 'rain'],
        66 => ['label' => 'Pluie verglaçante', 'icon' => 'rain'],
        67 => ['label' => 'Pluie verglaçante forte', 'icon' => 'rain'],
        71 => ['label' => 'Neige légère', 'icon' => 'snow'],
        73 => ['label' => 'Neige', 'icon' => 'snow'],
        75 => ['label' => 'Neige forte', 'icon' => 'snow'],
        77 => ['label' => 'Grains de neige', 'icon' => 'snow'],
        80 => ['label' => 'Averses légères', 'icon' => 'rain'],
        81 => ['label' => 'Averses', 'icon' => 'rain'],
        82 => ['label' => 'Averses violentes', 'icon' => 'rain'],
        85 => ['label' => 'Averses de neige', 'icon' => 'snow'],
        86 => ['label' => 'Averses de neige fortes', 'icon' => 'snow'],
        95 => ['label' => 'Orage', 'icon' => 'storm'],
        96 => ['label' => 'Orage avec grêle', 'icon' => 'storm'],
        99 => ['label' => 'Orage avec grêle forte', 'icon' => 'storm'],
    ];

    /**
     * @return array{temperature: int, description: string, icon: string, aqi: ?int, aqi_label: ?string}|null
     */
    public function current(): ?array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), function () {
            try {
                $weather = Http::timeout(5)->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => self::LATITUDE,
                    'longitude' => self::LONGITUDE,
                    'current' => 'temperature_2m,weather_code',
                    'timezone' => 'Europe/Paris',
                ]);

                if (! $weather->successful() || $weather->json('current.temperature_2m') === null) {
                    Log::channel('single')->warning('OpenMeteoService — réponse prévisions invalide : '.$weather->body());

                    return null;
                }

                $code = (int) $weather->json('current.weather_code');
                $meta = self::WEATHER_CODES[$code] ?? ['label' => 'Temps variable', 'icon' => 'cloudy'];

                return [
                    'temperature' => (int) round($weather->json('current.temperature_2m')),
                    'description' => $meta['label'],
                    'icon' => $meta['icon'],
                    ...$this->fetchAirQuality(),
                ];
            } catch (\Throwable $e) {
                Log::channel('single')->warning("OpenMeteoService — erreur réseau (prévisions) : {$e->getMessage()}");

                return null;
            }
        });
    }

    /**
     * Qualité de l'air (indice européen, 0-100+) — service séparé chez
     * Open-Meteo, volontairement isolé dans son propre try/catch : son
     * indisponibilité ne doit jamais invalider la météo elle-même, qui a sa
     * propre valeur d'usage indépendamment de l'air.
     *
     * Seuils officiels de l'indice européen (European Environment Agency) :
     * 0-20 Bon, 20-40 Moyen, 40-60 Dégradé, 60-80 Mauvais, 80-100 Très
     * mauvais, 100+ Extrêmement mauvais.
     *
     * @return array{aqi: ?int, aqi_label: ?string}
     */
    private function fetchAirQuality(): array
    {
        try {
            $air = Http::timeout(5)->get('https://air-quality-api.open-meteo.com/v1/air-quality', [
                'latitude' => self::LATITUDE,
                'longitude' => self::LONGITUDE,
                'current' => 'european_aqi',
            ]);

            $aqi = $air->successful() ? $air->json('current.european_aqi') : null;

            if ($aqi === null) {
                return ['aqi' => null, 'aqi_label' => null];
            }

            $aqi = (int) round($aqi);

            return ['aqi' => $aqi, 'aqi_label' => match (true) {
                $aqi < 20 => 'Bon',
                $aqi < 40 => 'Moyen',
                $aqi < 60 => 'Dégradé',
                $aqi < 80 => 'Mauvais',
                $aqi < 100 => 'Très mauvais',
                default => 'Extrêmement mauvais',
            }];
        } catch (\Throwable $e) {
            Log::channel('single')->warning("OpenMeteoService — erreur réseau (qualité de l'air) : {$e->getMessage()}");

            return ['aqi' => null, 'aqi_label' => null];
        }
    }
}
