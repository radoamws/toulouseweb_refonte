<?php

namespace Tests\Feature;

use App\Filament\Widgets\ClicksOverview;
use App\Models\Cinema;
use App\Models\ClickEvent;
use App\Models\Listing;
use App\Models\PageView;
use App\Models\User;
use App\Services\Stats\ClickTrackingService;
use App\Services\Stats\EntityLabelResolver;
use App\Services\Stats\PageViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Filtres "type de contenu" / "élément précis" du dashboard (demande
 * client, 16/09/2026 : "ajoute toutes les filtrages possible... filtre
 * pour les stats par salle de cinéma, filtre par news actifs, filtre par
 * annonce" — voir App\Filament\Pages\Dashboard et
 * TECHNICAL_DOCUMENTATION.md §45). Teste directement les services et la
 * résolution des filtres (réflexion sur les méthodes protégées du widget,
 * même approche que ClickTrackingTest) plutôt que de fouiller le HTML
 * rendu — voir son docblock pour le pourquoi.
 */
class DashboardEntityFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_click_tracking_service_narrows_by_entity_type_and_id(): void
    {
        $cinemaA = Cinema::create(['name' => 'CGR Blagnac', 'slug' => 'cgr-blagnac-df', 'is_active' => true]);
        $cinemaB = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson-df', 'is_active' => true]);
        $listing = Listing::create(['title' => 'Le Bistrot', 'slug' => 'le-bistrot-df', 'tier' => 'free', 'status' => 'published']);

        ClickEvent::create(['entity_type' => 'cinema', 'entity_id' => $cinemaA->id, 'created_at' => now()]);
        ClickEvent::create(['entity_type' => 'cinema', 'entity_id' => $cinemaA->id, 'created_at' => now()]);
        ClickEvent::create(['entity_type' => 'cinema', 'entity_id' => $cinemaB->id, 'created_at' => now()]);
        ClickEvent::create(['entity_type' => 'listing', 'entity_id' => $listing->id, 'created_at' => now()]);

        $tracking = app(ClickTrackingService::class);
        $from = now()->subDay();
        $to = now()->addDay();

        // Sans filtre : tous les clics, toutes entités confondues.
        $this->assertSame(4, $tracking->totalCount($from, $to));
        // Filtré par type seul : uniquement les clics "cinema" (2 salles).
        $this->assertSame(3, $tracking->totalCount($from, $to, 'cinema'));
        // Filtré par type + élément précis : uniquement CETTE salle.
        $this->assertSame(2, $tracking->totalCount($from, $to, 'cinema', $cinemaA->id));
        $this->assertSame(1, $tracking->totalCount($from, $to, 'cinema', $cinemaB->id));
    }

    public function test_page_view_service_narrows_by_entity_type_and_id(): void
    {
        $listing = Listing::create(['title' => 'Le Bistrot', 'slug' => 'le-bistrot-pv-df', 'tier' => 'free', 'status' => 'published']);

        PageView::create(['entity_type' => 'listing', 'entity_id' => $listing->id, 'path' => 'x', 'created_at' => now()]);
        PageView::create(['entity_type' => 'news', 'entity_id' => 1, 'path' => 'x', 'created_at' => now()]);
        PageView::create(['path' => '/', 'created_at' => now()]); // home, sans entité

        $views = app(PageViewService::class);
        $from = now()->subDay();
        $to = now()->addDay();

        $this->assertSame(3, $views->totalCount($from, $to));
        $this->assertSame(1, $views->totalCount($from, $to, 'listing'));
        $this->assertSame(1, $views->totalCount($from, $to, 'listing', $listing->id));
        $this->assertSame(0, $views->totalCount($from, $to, 'listing', 999));
    }

    public function test_entity_label_resolver_exposes_type_and_entity_options_for_the_filter_selects(): void
    {
        $cinemaA = Cinema::create(['name' => 'CGR Blagnac', 'slug' => 'cgr-blagnac-opt', 'is_active' => true]);
        $cinemaB = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson-opt', 'is_active' => true]);

        $resolver = app(EntityLabelResolver::class);

        $types = $resolver->typeOptions();
        $this->assertArrayHasKey('cinema', $types);
        $this->assertSame('Salles de cinéma', $types['cinema']);
        // screening_time n'a pas de liste simple à peupler (résolu via
        // relations) — volontairement absent du filtre "par élément précis".
        $this->assertArrayNotHasKey('screening_time', $types);

        $entities = $resolver->entityOptions('cinema');
        $this->assertSame([$cinemaA->id => 'CGR Blagnac', $cinemaB->id => 'Gaumont Wilson'], $entities);
        $this->assertSame([], $resolver->entityOptions('type_inconnu'));
    }

    /** Même approche que ClickTrackingTest::test_clicks_overview_filter_dates_resolve_from_the_page_filters(). */
    public function test_clicks_overview_reads_entity_filters_from_the_page_filters(): void
    {
        $widget = new ClicksOverview();
        $widget->filters = ['entity_type' => 'cinema', 'entity_id' => '7'];

        $type = (new \ReflectionMethod($widget, 'filterEntityType'))->invoke($widget);
        $id = (new \ReflectionMethod($widget, 'filterEntityId'))->invoke($widget);

        $this->assertSame('cinema', $type);
        $this->assertSame(7, $id);
    }

    /** Sans filtre choisi : aucune restriction (comportement inchangé par rapport à avant ce correctif). */
    public function test_clicks_overview_entity_filters_default_to_null_when_unset(): void
    {
        $widget = new ClicksOverview();
        $widget->filters = null;

        $type = (new \ReflectionMethod($widget, 'filterEntityType'))->invoke($widget);
        $id = (new \ReflectionMethod($widget, 'filterEntityId'))->invoke($widget);

        $this->assertNull($type);
        $this->assertNull($id);
    }

    /** Le dashboard admin se rend toujours correctement avec les 2 nouveaux filtres (régression). */
    public function test_admin_dashboard_still_renders_with_the_entity_filters(): void
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Filtrer par type de contenu');
    }
}
