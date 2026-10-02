<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:strip-html-from-news-titles` — voir docblock de la commande
 * (demande client, 02/10/2026 : "Verifie pour toutes les encodage HTML de
 * toutes les entités" — `news.title` contient des balises HTML résiduelles
 * sur un petit nombre de lignes, confirmé en direct le 02/10/2026).
 */
class StripHtmlFromNewsTitlesTest extends TestCase
{
    use RefreshDatabase;

    protected function makeNews(string $title, string $slug): News
    {
        $category = NewsCategory::firstOrCreate(['slug' => 'vie-locale-strip-html'], ['name' => 'Vie locale']);

        return News::create([
            'title' => $title, 'slug' => $slug, 'body' => 'x',
            'category_id' => $category->id, 'status' => 'published', 'published_at' => now(),
        ]);
    }

    public function test_strips_html_tags_from_a_contaminated_title(): void
    {
        $news = $this->makeNews('<b>***Soirée & AFTER Cabaret*** à Toulouse !!!</b>', 'soiree-after-cabaret');

        Artisan::call('content:strip-html-from-news-titles');

        $this->assertSame('***Soirée & AFTER Cabaret*** à Toulouse !!!', $news->fresh()->title);
    }

    /**
     * ⚠️ Piège réel (constaté en base) : un "<" littéral utilisé comme
     * séparateur de date ("13<17 decembre") n'est PAS une balise HTML — ne
     * doit JAMAIS être touché. Voir LegacyCleaner::stripHtml().
     */
    public function test_does_not_touch_a_title_using_a_literal_less_than_sign_as_a_separator(): void
    {
        $news = $this->makeNews('Marin / Belarbi - Théâtre Garonne - 13<17 decembre', 'marin-belarbi-dates');

        Artisan::call('content:strip-html-from-news-titles');

        $this->assertSame('Marin / Belarbi - Théâtre Garonne - 13<17 decembre', $news->fresh()->title);
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé avant exécution en production
     * (02/10/2026) : un titre avec de simples espaces multiples ("mot  mot")
     * mais AUCUNE vraie balise ne doit jamais être touché — 291 lignes
     * auraient été modifiées à tort sans ce filtre (contre 5 avec une vraie
     * balise), un problème différent et hors de la portée de cette commande.
     */
    public function test_does_not_touch_a_title_with_only_multiple_spaces_and_no_real_tag(): void
    {
        $news = $this->makeNews('Expo d’été 2023 -  Musée Ingres Bourdelle', 'expo-ete-espaces-multiples');

        Artisan::call('content:strip-html-from-news-titles');

        $this->assertSame('Expo d’été 2023 -  Musée Ingres Bourdelle', $news->fresh()->title);
    }

    public function test_never_changes_the_slug(): void
    {
        $news = $this->makeNews('<b>Titre contaminé</b>', 'titre-contamine-slug-fixe');

        Artisan::call('content:strip-html-from-news-titles');

        $this->assertSame('titre-contamine-slug-fixe', $news->fresh()->slug);
    }

    public function test_dry_run_reports_but_does_not_modify_anything(): void
    {
        $news = $this->makeNews('<b>Titre contaminé</b>', 'titre-contamine-dry-run');

        Artisan::call('content:strip-html-from-news-titles', ['--dry-run' => true]);

        $this->assertSame('<b>Titre contaminé</b>', $news->fresh()->title);
    }

    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        config(['services.google_indexing.enabled' => true, 'services.google_indexing.credentials_json_base64' => base64_encode('{}')]);

        $this->makeNews('<b>Titre contaminé</b>', 'titre-contamine-observers');

        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();
        Http::fake();

        Artisan::call('content:strip-html-from-news-titles');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        app(\App\Services\Seo\GoogleIndexingService::class)->flush();

        Http::assertNothingSent();
    }
}
