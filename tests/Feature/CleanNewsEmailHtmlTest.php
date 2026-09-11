<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:clean-news-email-html` (demande client, 11/09/2026 — audit
 * UI/UX : articles dont le corps est un copier-coller brut d'email,
 * `<table>`/styles inline provoquant un débordement horizontal réel sur
 * mobile). Voir docblock de App\Console\Commands\Migration\CleanNewsEmailHtml.
 */
class CleanNewsEmailHtmlTest extends TestCase
{
    use RefreshDatabase;

    protected function makeNews(string $body): News
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);

        return News::create([
            'category_id' => $category->id, 'title' => 'Un article', 'slug' => 'un-article',
            'body' => $body, 'status' => 'published', 'published_at' => now(),
        ]);
    }

    public function test_strips_table_and_inline_styles_while_keeping_the_text(): void
    {
        $news = $this->makeNews(
            '<table style="max-width:600px;font-family:Lato"><tr><td class="x_ydp33a656db">'
            .'Le concert aura lieu &agrave; 20h.</td></tr><tr><td>R&eacute;servation conseill&eacute;e.</td></tr></table>'
        );

        Artisan::call('content:clean-news-email-html');

        $news->refresh();
        $this->assertStringNotContainsString('<table', $news->body);
        $this->assertStringNotContainsString('max-width', $news->body);
        $this->assertStringNotContainsString('x_ydp', $news->body);
        $this->assertStringContainsString('Le concert aura lieu à 20h.', $news->body);
        $this->assertStringContainsString('Réservation conseillée.', $news->body);
    }

    public function test_articles_without_a_table_are_left_untouched(): void
    {
        $news = $this->makeNews('<p>Un article normal, déjà bien formé.</p>');

        Artisan::call('content:clean-news-email-html');

        $this->assertSame('<p>Un article normal, déjà bien formé.</p>', $news->fresh()->body);
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $news = $this->makeNews('<table><tr><td>Contenu</td></tr></table>');

        Artisan::call('content:clean-news-email-html', ['--dry-run' => true]);

        $this->assertStringContainsString('<table', $news->fresh()->body);
    }

    /** Même garde-fou qu'au §29 : News implémente HasCloudflarePurgeUrls/HasGoogleIndexingUrl. */
    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        Http::fake();

        $this->makeNews('<table><tr><td>Contenu</td></tr></table>');

        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        Http::fake();

        Artisan::call('content:clean-news-email-html');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();

        Http::assertNothingSent();
    }
}
