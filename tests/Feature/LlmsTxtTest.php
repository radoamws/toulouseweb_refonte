<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/llms.txt` (brief §13, "optimisation GEO/AI Search") — voir docblock de
 * LlmsTxtController.
 */
class LlmsTxtTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_as_plain_text_with_main_sections(): void
    {
        $response = $this->get('/llms.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('/annuaire', false);
        $response->assertSee('/agenda', false);
        $response->assertSee('/cinema', false);
        $response->assertSee('/actualites', false);
        $response->assertSee('/annonces', false);
        $response->assertSee('sitemap.xml', false);
    }

    public function test_reflects_configured_site_name(): void
    {
        SiteSetting::current()->update(['site_name' => 'Mon Portail Test']);

        $this->get('/llms.txt')->assertSee('Mon Portail Test');
    }

    public function test_is_not_blocked_by_robots_txt(): void
    {
        // GPTBot/ClaudeBot/PerplexityBot etc. ne sont explicitement exclus
        // nulle part — seuls /admin et /track-click le sont.
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringNotContainsString('llms.txt', $robots);
        $this->assertStringContainsString('Disallow: /admin', $robots);
    }
}
