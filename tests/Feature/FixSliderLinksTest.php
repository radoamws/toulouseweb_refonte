<?php

namespace Tests\Feature;

use App\Models\Slider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:fix-slider-links` (demande client, 11/09/2026 — capture d'écran
 * d'un slide homepage affichant littéralement son URL cible comme titre,
 * non cliquable). Voir docblock de App\Console\Commands\Migration\FixSliderLinks.
 */
class FixSliderLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_url_from_title_to_link_url_and_restores_readable_title(): void
    {
        $slider = Slider::create([
            'title' => 'https://lescale-tournefeuille.fr/les_spectacles/valseavecw/',
            'client_name' => 'Escale',
            'image' => 'https://example.test/img.jpg',
        ]);

        Artisan::call('content:fix-slider-links');

        $slider->refresh();
        $this->assertSame('https://lescale-tournefeuille.fr/les_spectacles/valseavecw/', $slider->link_url);
        $this->assertSame('Escale', $slider->title);
    }

    public function test_falls_back_to_url_host_when_client_name_is_missing(): void
    {
        $slider = Slider::create([
            'title' => 'https://www.144danceavenue.com/evenement/soiree-rock',
            'client_name' => null,
            'image' => 'https://example.test/img.jpg',
        ]);

        Artisan::call('content:fix-slider-links');

        $slider->refresh();
        $this->assertSame('https://www.144danceavenue.com/evenement/soiree-rock', $slider->link_url);
        $this->assertSame('www.144danceavenue.com', $slider->title);
    }

    /** Valeur "URL-like" mais pas exploitable — laissée telle quelle plutôt que de produire un lien cassé. */
    public function test_leaves_malformed_url_looking_titles_untouched(): void
    {
        $slider = Slider::create([
            'title' => 'https://SLB CONSULTING',
            'client_name' => 'SLB CONSULTING',
            'image' => 'https://example.test/img.jpg',
        ]);

        Artisan::call('content:fix-slider-links');

        $slider->refresh();
        $this->assertNull($slider->link_url);
        $this->assertSame('https://SLB CONSULTING', $slider->title);
    }

    public function test_does_not_touch_sliders_that_already_have_a_link_url(): void
    {
        $slider = Slider::create([
            'title' => 'Déjà correct',
            'link_url' => 'https://example.test/',
            'image' => 'https://example.test/img.jpg',
        ]);

        Artisan::call('content:fix-slider-links');

        $slider->refresh();
        $this->assertSame('Déjà correct', $slider->title);
        $this->assertSame('https://example.test/', $slider->link_url);
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $slider = Slider::create([
            'title' => 'https://lescale-tournefeuille.fr/les_spectacles/valseavecw/',
            'client_name' => 'Escale',
            'image' => 'https://example.test/img.jpg',
        ]);

        Artisan::call('content:fix-slider-links', ['--dry-run' => true]);

        $slider->refresh();
        $this->assertNull($slider->link_url);
        $this->assertSame('https://lescale-tournefeuille.fr/les_spectacles/valseavecw/', $slider->title);
    }

    /** Même garde-fou que CleanLegacyHtmlText (§29) : Slider implémente HasCloudflarePurgeUrls. */
    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        Http::fake();

        $slider = Slider::create([
            'title' => 'https://lescale-tournefeuille.fr/les_spectacles/valseavecw/',
            'client_name' => 'Escale',
            'image' => 'https://example.test/img.jpg',
        ]);
        $slider->placements()->create(['page' => 'home']);

        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        Http::fake(); // réinitialise le journal des requêtes envoyées

        Artisan::call('content:fix-slider-links');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();

        Http::assertNothingSent();
    }
}
