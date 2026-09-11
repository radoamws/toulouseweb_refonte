<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `content:seed-category-icons` (demande client, 11/09/2026 — icônes
 * génériques identiques pour toutes les catégories sur la homepage). Voir
 * docblock de App\Console\Commands\Migration\SeedCategoryIcons.
 */
class SeedCategoryIconsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_a_distinct_icon_to_mapped_categories(): void
    {
        $animaux = Category::create(['name' => 'Animaux', 'slug' => 'animaux', 'level' => 0, 'is_active' => true]);
        $restaurants = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        Artisan::call('content:seed-category-icons');

        $animaux->refresh();
        $restaurants->refresh();
        $this->assertSame('/images/category-icons/paw.svg', $animaux->icon);
        $this->assertSame('/images/category-icons/food.svg', $restaurants->icon);
        $this->assertNotSame($animaux->icon, $restaurants->icon);
    }

    public function test_writes_real_svg_files_to_public_disk(): void
    {
        Category::create(['name' => 'Animaux', 'slug' => 'animaux', 'level' => 0, 'is_active' => true]);

        Artisan::call('content:seed-category-icons');

        $this->assertFileExists(public_path('images/category-icons/paw.svg'));
        $this->assertStringContainsString('<svg', file_get_contents(public_path('images/category-icons/paw.svg')));
    }

    public function test_unmapped_slug_is_left_without_an_icon(): void
    {
        $stripTease = Category::create(['name' => 'Strip-tease', 'slug' => 'strip-tease', 'level' => 0, 'is_active' => true]);

        Artisan::call('content:seed-category-icons');

        $this->assertNull($stripTease->fresh()->icon);
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $category = Category::create(['name' => 'Animaux', 'slug' => 'animaux', 'level' => 0, 'is_active' => true]);

        Artisan::call('content:seed-category-icons', ['--dry-run' => true]);

        $this->assertNull($category->fresh()->icon);
    }

    /** Category fait partie de AppServiceProvider::SITEMAP_MODELS (RegeneratesSitemapObserver). */
    public function test_does_not_trigger_any_model_observer_side_effects(): void
    {
        config(['services.cloudflare.enabled' => true, 'services.cloudflare.zone_id' => 'test', 'services.cloudflare.api_token' => 'test']);
        Http::fake();

        Category::create(['name' => 'Animaux', 'slug' => 'animaux', 'level' => 0, 'is_active' => true]);

        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();
        Http::fake();

        Artisan::call('content:seed-category-icons');
        app(\App\Services\Cache\CloudflareCachePurger::class)->flush();

        Http::assertNothingSent();
    }
}
