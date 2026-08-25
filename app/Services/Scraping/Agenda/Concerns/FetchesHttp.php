<?php

namespace App\Services\Scraping\Agenda\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetch HTML/JSON réseau partagé par tous les drivers agenda — mêmes réglages
 * (UA desktop, timeout, retry) que TheatreDeLaCiteDriver (voir
 * TECHNICAL_DOCUMENTATION.md §13), extraits ici pour être réutilisés par les
 * 11 nouveaux drivers construits à partir du vrai code legacy fourni par le
 * client dans old/backEnd/app/Http/Controllers/AgendaController.php.
 */
trait FetchesHttp
{
    protected function fetchHtml(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            ])->timeout(20)->retry(2, 500)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::channel('single')->warning(static::class." — erreur réseau (HTML) sur {$url} : {$e->getMessage()}");

            return null;
        }
    }

    /** @return array<string,mixed>|null */
    protected function fetchJson(string $url, array $headers = []): ?array
    {
        try {
            $response = Http::withHeaders(array_merge([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                'Accept' => 'application/json',
            ], $headers))->timeout(20)->retry(2, 500)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::channel('single')->warning(static::class." — erreur réseau (JSON) sur {$url} : {$e->getMessage()}");

            return null;
        }
    }
}
