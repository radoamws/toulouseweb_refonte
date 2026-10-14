<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Widget météo + qualité de l'air de la home (brief roadmap "contenu",
 * 14/10/2026, demande client). Voir App\Services\Weather\OpenMeteoService.
 */
class WeatherWidgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ Le cache (driver `array`) n'est PAS réinitialisé entre les tests par
     * `RefreshDatabase` (qui ne touche qu'à la base de données) — sans ce
     * `forget()`, l'ordre réel d'exécution des tests (pas forcément l'ordre
     * de déclaration) peut faire fuiter le résultat mis en cache par un
     * test vers un autre qui attend une réponse HTTP différente.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('homepage-weather');
    }

    public function test_homepage_shows_the_weather_widget_when_open_meteo_responds(): void
    {
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response(['current' => ['temperature_2m' => 16.5, 'weather_code' => 3]], 200),
            'https://air-quality-api.open-meteo.com/v1/air-quality*' => Http::response(['current' => ['european_aqi' => 45]], 200),
        ]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('17°C');
        $response->assertSee('Couvert');
        $response->assertSee('Dégradé');
    }

    /** Un widget d'usage quotidien ne doit JAMAIS casser la home si Open-Meteo est indisponible. */
    public function test_homepage_renders_without_the_widget_when_open_meteo_is_unreachable(): void
    {
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response(null, 503),
            'https://air-quality-api.open-meteo.com/v1/air-quality*' => Http::response(null, 503),
        ]);

        $this->get('/')->assertOk()->assertDontSee('à Toulouse');
    }

    public function test_weather_result_is_cached_across_requests(): void
    {
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response(['current' => ['temperature_2m' => 10.0, 'weather_code' => 0]], 200),
            'https://air-quality-api.open-meteo.com/v1/air-quality*' => Http::response(['current' => ['european_aqi' => 10]], 200),
        ]);

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        Http::assertSentCount(2); // 1 requête météo + 1 qualité de l'air, jamais répétées grâce au cache.
    }

    public function test_air_quality_failure_does_not_hide_the_weather_itself(): void
    {
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response(['current' => ['temperature_2m' => 20.0, 'weather_code' => 0]], 200),
            'https://air-quality-api.open-meteo.com/v1/air-quality*' => Http::response(null, 503),
        ]);

        $response = $this->get('/')->assertOk();
        $response->assertSee('20°C');
        $response->assertDontSee('Dégradé');
    }
}
