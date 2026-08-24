<?php

namespace Tests\Feature;

use App\Models\ClickEvent;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dashboard admin des statistiques de clics (brief : "Ajoute aussi un
 * statistiques dans l'admin et chaque clic... doit être ajouté dans cette
 * statistique"). Vérifie que les 3 widgets (vue d'ensemble, graphique par
 * type, top clics) se rendent sans erreur et reflètent de vrais clics
 * enregistrés — pas seulement que la page répond 200.
 */
class AdminDashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_admin_dashboard_renders_with_click_stats_widgets(): void
    {
        $listing = Listing::create(['title' => 'Restaurant Le Test', 'slug' => 'restaurant-le-test', 'tier' => 'free', 'status' => 'published']);

        ClickEvent::create([
            'entity_type' => 'listing', 'entity_id' => $listing->id, 'context' => 'homepage_annuaire',
            'created_at' => now(),
        ]);
        ClickEvent::create([
            'entity_type' => 'listing', 'entity_id' => $listing->id, 'context' => 'annuaire_listing',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->authenticatedAdmin())->get('/admin');

        $response->assertOk();
        $response->assertSee('Clics aujourd\'hui');
        $response->assertSee('Restaurant Le Test');
        $response->assertSee('Fiches annuaire');
    }

    public function test_dashboard_renders_without_error_when_no_clicks_recorded(): void
    {
        $response = $this->actingAs($this->authenticatedAdmin())->get('/admin');

        $response->assertOk();
        $response->assertSee('Aucun clic enregistré');
    }
}
