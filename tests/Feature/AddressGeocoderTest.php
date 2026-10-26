<?php

namespace Tests\Feature;

use App\Services\Geocoding\AddressGeocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `App\Services\Geocoding\AddressGeocoder` (brief roadmap "contenu",
 * demande client 26/10/2026 — "le choix entre 2 rues, pas juste entre 2
 * arrêts de bus"). Réponse BAN reproduite fidèlement (vérifiée en direct le
 * 26/10/2026 contre api-adresse.data.gouv.fr).
 */
class AddressGeocoderTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function fakeBanResponse(string $label, float $lat, float $lon): void
    {
        Http::fake(['https://api-adresse.data.gouv.fr/search/*' => Http::response([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [$lon, $lat]],
                'properties' => ['label' => $label, 'name' => $label, 'city' => 'Toulouse', '_type' => 'address'],
            ]],
        ], 200)]);
    }

    public function test_geocode_parses_the_first_feature(): void
    {
        $this->fakeBanResponse('Rue Alsace Lorraine 31000 Toulouse', 43.604291, 1.445669);

        $result = app(AddressGeocoder::class)->geocode('rue alsace lorraine toulouse');

        $this->assertNotNull($result);
        $this->assertSame('Rue Alsace Lorraine 31000 Toulouse', $result['label']);
        $this->assertEqualsWithDelta(43.604291, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(1.445669, $result['lon'], 0.0001);
    }

    public function test_geocode_returns_null_when_no_feature_matches(): void
    {
        Http::fake(['https://api-adresse.data.gouv.fr/search/*' => Http::response(['type' => 'FeatureCollection', 'features' => []], 200)]);

        $this->assertNull(app(AddressGeocoder::class)->geocode('adresse qui n\'existe pas du tout xyz123'));
    }

    public function test_geocode_returns_null_on_http_failure_without_throwing(): void
    {
        Http::fake(['https://api-adresse.data.gouv.fr/search/*' => Http::response(null, 503)]);

        $this->assertNull(app(AddressGeocoder::class)->geocode('capitole'));
    }

    public function test_geocode_caches_a_successful_result(): void
    {
        $this->fakeBanResponse('Capitole 31000 Toulouse', 43.6045, 1.4442);

        app(AddressGeocoder::class)->geocode('capitole');
        Http::fake(['https://api-adresse.data.gouv.fr/search/*' => Http::response(null, 503)]);
        $result = app(AddressGeocoder::class)->geocode('capitole');

        $this->assertNotNull($result);
    }

    /**
     * Un échec réseau transitoire ne doit JAMAIS rester en cache — sinon une
     * adresse valide resterait injoignable une semaine. `Http::sequence()`
     * (1 seule mise en place de fake, 2 réponses successives) plutôt que 2
     * appels à `Http::fake()` : plus explicite sur l'ordre réel des 2
     * requêtes HTTP attendues.
     */
    public function test_geocode_does_not_cache_a_failure(): void
    {
        Http::fake(['https://api-adresse.data.gouv.fr/search/*' => Http::sequence()
            ->push(null, 503)
            ->push([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [1.4442, 43.6045]],
                    'properties' => ['label' => 'Capitole 31000 Toulouse'],
                ]],
            ], 200),
        ]);

        $this->assertNull(app(AddressGeocoder::class)->geocode('capitole'));
        $this->assertNotNull(app(AddressGeocoder::class)->geocode('capitole'));
    }
}
