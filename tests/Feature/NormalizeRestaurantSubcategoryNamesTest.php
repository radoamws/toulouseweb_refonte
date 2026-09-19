<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `content:normalize-restaurant-subcategories` — demande client, 19/09/2026 :
 * "il y avait une rubrique 'restaurant spectacle'" alors que la sous-catégorie
 * migrée s'appelle littéralement `diner_spectacle` (valeur technique legacy
 * jamais retraduite). Voir App\Console\Commands\Migration\NormalizeRestaurantSubcategoryNames.
 */
class NormalizeRestaurantSubcategoryNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function restaurantsTree(): Category
    {
        $restaurants = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants']);
        Category::create(['name' => 'diner_spectacle', 'slug' => 'diner-spectacle', 'parent_id' => $restaurants->id]);
        Category::create(['name' => 'traditionnel', 'slug' => 'traditionnel', 'parent_id' => $restaurants->id]);
        Category::create(['name' => 'Guinguettes', 'slug' => 'guinguettes', 'parent_id' => $restaurants->id]);

        return $restaurants;
    }

    public function test_renames_the_raw_legacy_labels(): void
    {
        $this->restaurantsTree();

        Artisan::call('content:normalize-restaurant-subcategories');

        $this->assertSame('Restaurant spectacle', Category::where('slug', 'diner-spectacle')->first()->name);
        $this->assertSame('Traditionnel', Category::where('slug', 'traditionnel')->first()->name);
    }

    public function test_does_not_touch_categories_already_correctly_named(): void
    {
        $this->restaurantsTree();

        Artisan::call('content:normalize-restaurant-subcategories');

        $this->assertSame('Guinguettes', Category::where('slug', 'guinguettes')->first()->name);
    }

    public function test_slugs_are_never_changed(): void
    {
        $this->restaurantsTree();

        Artisan::call('content:normalize-restaurant-subcategories');

        $this->assertNotNull(Category::where('slug', 'diner-spectacle')->first());
    }

    public function test_dry_run_does_not_modify_anything(): void
    {
        $this->restaurantsTree();

        Artisan::call('content:normalize-restaurant-subcategories', ['--dry-run' => true]);

        $this->assertSame('diner_spectacle', Category::where('slug', 'diner-spectacle')->first()->name);
    }

    public function test_fails_gracefully_when_restaurants_category_does_not_exist(): void
    {
        $exitCode = Artisan::call('content:normalize-restaurant-subcategories');

        $this->assertSame(1, $exitCode);
    }
}
