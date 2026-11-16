<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Cinema;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\TransportLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demande client (13/11/2026) : les pages de catégorie principales
 * (/cinema, /agenda, /annuaire, /annonces, /transports, /actualites) —
 * exactement celles visées par des requêtes comme "cinéma à Toulouse" —
 * n'avaient jusqu'ici AUCUNE donnée structurée schema.org, contrairement
 * aux fiches détail. Voir TECHNICAL_DOCUMENTATION.md §71.
 */
class CategoryPageItemListSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cinema_index_exposes_an_item_list_of_cinemas(): void
    {
        Cinema::create(['name' => 'Pathé Wilson Test', 'zone' => 'complexe', 'is_active' => true]);

        $response = $this->get('/cinema');

        $response->assertOk();
        $response->assertSee('"@type":"ItemList"', false);
        $response->assertSee('Pathé Wilson Test', false);
    }

    public function test_agenda_index_exposes_an_item_list_of_events(): void
    {
        Event::create(['title' => 'Concert ItemList Test', 'status' => 'published', 'source' => 'scraped', 'start_date' => now()->addWeek()]);

        $response = $this->get('/agenda');

        $response->assertOk();
        $response->assertSee('"@type":"ItemList"', false);
        $response->assertSee('Concert ItemList Test', false);
    }

    public function test_annuaire_index_exposes_an_item_list_of_listings(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-schema', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create(['title' => 'Resto ItemList Test', 'tier' => 'free', 'status' => 'published']);
        $listing->categories()->attach($category->id);

        $response = $this->get('/annuaire');

        $response->assertOk();
        $response->assertSee('"@type":"ItemList"', false);
        $response->assertSee('Resto ItemList Test', false);
    }

    public function test_annonces_index_exposes_an_item_list_of_classifieds(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Véhicules', 'slug' => 'vehicules-schema', 'is_active' => true]);
        Classified::create([
            'category_id' => $category->id, 'title' => 'Vélo ItemList Test',
            'description' => 'Bon état', 'status' => 'published',
        ]);

        $response = $this->get('/annonces');

        $response->assertOk();
        $response->assertSee('"@type":"ItemList"', false);
        $response->assertSee('Vélo ItemList Test', false);
    }

    public function test_actualites_index_exposes_an_item_list_of_news(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-schema']);
        News::create([
            'category_id' => $category->id, 'title' => 'Actu ItemList Test', 'slug' => 'actu-itemlist-test',
            'body' => 'Contenu', 'status' => 'published', 'published_at' => now(),
        ]);

        $response = $this->get('/actualites');

        $response->assertOk();
        $response->assertSee('"@type":"ItemList"', false);
        $response->assertSee('Actu ItemList Test', false);
    }

    public function test_transports_index_exposes_an_item_list_of_lines(): void
    {
        TransportLine::create([
            'gtfs_route_id' => 'ITEMLIST-TEST', 'name' => 'Ligne ItemList Test', 'slug' => 'ligne-itemlist-test',
            'type' => 'bus', 'stops' => [], 'is_active' => true,
        ]);

        $response = $this->get('/transports');

        $response->assertOk();
        $response->assertSee('"@type":"ItemList"', false);
        $response->assertSee('Ligne ItemList Test', false);
    }
}
