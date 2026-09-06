<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Demande d'indexation automatique Google Search Console à chaque ajout/
 * modif/suppression de contenu (demande client, TECHNICAL_DOCUMENTATION.md
 * §20). Search Console lui-même n'expose aucune API publique pour son
 * bouton "Demander une indexation" — le mécanisme réellement disponible est
 * l'API Indexing de Google (`indexing.googleapis.com`), authentifiée par un
 * compte de service (JWT signé RS256, flux OAuth2 "JWT Bearer", RFC 7523),
 * PAS l'API Search Console (qui ne couvre que sitemaps/analytics/vérification
 * de propriété, aucune demande d'indexation).
 *
 * ⚠️ Limite officielle Google : cette API n'est documentée par Google que
 * pour les pages `JobPosting`/`BroadcastEvent` — l'utiliser pour d'autres
 * types de contenu (actualités, événements, annuaire...) fonctionne en
 * pratique (l'endpoint accepte n'importe quelle URL et déclenche une
 * tentative de exploration/crawl) mais n'est PAS officiellement garanti par
 * Google pour ces types. Décision du client, appliquée telle quelle.
 *
 * Quota par défaut de l'API : 200 requêtes/jour/projet — contrairement à
 * Cloudflare (jusqu'à 30 URLs par appel), l'API Indexing traite UNE URL par
 * appel HTTP. `queue()`/`flush()` suit le même principe de découplage que
 * App\Services\Cache\CloudflareCachePurger (accumulation en mémoire pendant
 * le processus, envoi groupé via `app()->terminating()`) MAIS avec un garde
 * de quota supplémentaire (compteur journalier en cache) : au-delà du quota
 * configuré, les URLs restantes sont journalisées comme ignorées plutôt que
 * d'échouer ou de dépasser silencieusement la limite réelle de l'API.
 */
class GoogleIndexingService
{
    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected const PUBLISH_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    protected const SCOPE = 'https://www.googleapis.com/auth/indexing';

    /** URL => type ('URL_UPDATED'|'URL_DELETED'), dédoublonné (la dernière demande gagne pour une même URL). */
    protected array $queued = [];

    public function queue(?string $url, string $type): void
    {
        if (! $url) {
            return;
        }

        $this->queued[$url] = $type;
    }

    public function flush(): void
    {
        if ($this->queued === []) {
            return;
        }

        $items = $this->queued;
        $this->queued = [];

        if (! config('services.google_indexing.enabled')) {
            Log::channel('single')->debug('GoogleIndexingService — désactivée (GOOGLE_INDEXING_ENABLED), URLs ignorées : '.implode(', ', array_keys($items)));

            return;
        }

        $token = $this->fetchAccessToken();
        if (! $token) {
            return; // déjà journalisé par fetchAccessToken()
        }

        foreach ($items as $url => $type) {
            if ($this->remainingQuota() <= 0) {
                Log::channel('single')->warning("GoogleIndexingService — quota journalier atteint, ignoré : {$url}");

                continue;
            }

            $this->publish($token, $url, $type);
            $this->incrementQuotaUsage();
        }
    }

    protected function publish(string $token, string $url, string $type): void
    {
        try {
            $response = Http::withToken($token)->timeout(10)->post(self::PUBLISH_URL, [
                'url' => $url,
                'type' => $type,
            ]);

            if (! $response->successful()) {
                Log::channel('single')->warning("GoogleIndexingService — échec publish ({$type}) sur {$url} : ".$response->body());
            }
        } catch (\Throwable $e) {
            Log::channel('single')->warning("GoogleIndexingService — erreur réseau (publish) sur {$url} : {$e->getMessage()}");
        }
    }

    /**
     * Jeton d'accès OAuth2, mis en cache jusqu'à ~5 min avant son expiration
     * réelle (un jeton dure 1h côté Google) — évite de re-signer un JWT et de
     * refaire l'échange à chaque flush() alors que plusieurs sauvegardes
     * admin/exécutions de commande peuvent se succéder dans la même heure.
     */
    protected function fetchAccessToken(): ?string
    {
        return Cache::remember('google-indexing-access-token', now()->addMinutes(55), function () {
            $credentials = $this->credentials();
            if (! $credentials) {
                Log::channel('single')->warning('GoogleIndexingService — activée mais GOOGLE_INDEXING_CREDENTIALS_JSON_BASE64 manquant/invalide.');

                return null;
            }

            $jwt = $this->buildSignedJwt($credentials);
            if (! $jwt) {
                Log::channel('single')->warning('GoogleIndexingService — échec de signature du JWT (clé privée invalide ?).');

                return null;
            }

            try {
                $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);
            } catch (\Throwable $e) {
                Log::channel('single')->warning("GoogleIndexingService — erreur réseau (token) : {$e->getMessage()}");

                return null;
            }

            if (! $response->successful() || ! $response->json('access_token')) {
                Log::channel('single')->warning('GoogleIndexingService — échec d\'obtention du jeton OAuth2 : '.$response->body());

                return null;
            }

            return $response->json('access_token');
        });
    }

    /** @return array{client_email: string, private_key: string}|null */
    protected function credentials(): ?array
    {
        $encoded = config('services.google_indexing.credentials_json_base64');
        if (! $encoded) {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        $json = $decoded !== false ? json_decode($decoded, true) : null;

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            return null;
        }

        return ['client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    /** @param array{client_email: string, private_key: string} $credentials */
    protected function buildSignedJwt(array $credentials): ?string
    {
        $now = time();

        $segments = [
            self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $signingInput = implode('.', $segments);

        $signature = null;
        $signed = @openssl_sign($signingInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);

        if (! $signed || $signature === null) {
            return null;
        }

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    protected static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function remainingQuota(): int
    {
        $used = Cache::get($this->quotaCacheKey(), 0);

        return max(0, (int) config('services.google_indexing.daily_quota') - $used);
    }

    protected function incrementQuotaUsage(): void
    {
        $key = $this->quotaCacheKey();

        if (! Cache::has($key)) {
            Cache::put($key, 0, now()->endOfDay());
        }

        Cache::increment($key);
    }

    protected function quotaCacheKey(): string
    {
        return 'google-indexing-quota:'.now()->toDateString();
    }
}
