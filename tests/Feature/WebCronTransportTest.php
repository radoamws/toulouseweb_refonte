<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Déclencheur HTTP SÉPARÉ de `transport:import-tisseo-schedule` (brief
 * roadmap "contenu", 16/10/2026) — voir docblock de
 * App\Http\Controllers\WebCronTransportController pour pourquoi il ne doit
 * SURTOUT PAS être fusionné avec /webcron (voir WebCronTest).
 * WEBCRON_TRANSPORT_SECRET="test-webcron-transport-secret-value" fixé dans
 * phpunit.xml pour ces tests uniquement.
 */
class WebCronTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_correct_token_runs_the_import_and_returns_200(): void
    {
        Http::fake(['https://data.toulouse-metropole.fr/explore/dataset/tisseo-gtfs/*' => Http::response(null, 503)]);

        // Échec du téléchargement attendu (pas de vraie archive GTFS ici) :
        // seul le déclenchement HTTP -> commande est testé, pas l'import
        // lui-même (couvert par ImportTisseoScheduleTest).
        $response = $this->get('/webcron-transport/test-webcron-transport-secret-value');
        $response->assertOk();
        $this->assertStringContainsString('Échec du téléchargement', $response->getContent());
    }

    public function test_wrong_token_returns_404_not_403(): void
    {
        $this->get('/webcron-transport/un-jeton-invente')->assertNotFound();
    }

    public function test_empty_token_segment_does_not_match_the_route(): void
    {
        $this->get('/webcron-transport/')->assertNotFound();
    }

    public function test_route_is_disallowed_in_robots_txt(): void
    {
        $this->assertStringContainsString('Disallow: /webcron-transport', file_get_contents(public_path('robots.txt')));
    }
}
