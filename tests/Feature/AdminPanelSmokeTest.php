<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vérifie que chaque ressource d'administration Filament se rend sans
 * erreur fatale pour un utilisateur autorisé. Sert de garde-fou pendant le
 * développement des phases 4+ (voir TECHNICAL_DOCUMENTATION.md §12) : toute
 * ressource cassée (relation mal nommée, champ manquant...) fait échouer ce
 * test avant d'atteindre la production.
 */
class AdminPanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_guest_is_redirected_from_admin(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_non_admin_cannot_access_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    /**
     * @dataProvider resourceIndexRoutes
     */
    public function test_admin_resource_index_renders(string $route): void
    {
        $this->actingAs($this->authenticatedAdmin())
            ->get($route)
            ->assertOk();
    }

    /**
     * @dataProvider resourceCreateRoutes
     */
    public function test_admin_resource_create_form_renders(string $route): void
    {
        $this->actingAs($this->authenticatedAdmin())
            ->get($route)
            ->assertOk();
    }

    public static function resourceCreateRoutes(): array
    {
        // Les ressources avec formulaires personnalisés (relations, sections
        // conditionnelles, CheckboxList custom...) — celles où une erreur de
        // configuration a le plus de chances de se cacher.
        return collect([
            'listings', 'events', 'event-categories', 'categories',
            'classifieds', 'news', 'sliders', 'redirects',
        ])->mapWithKeys(fn (string $slug) => [$slug => ["/admin/{$slug}/create"]])->all();
    }

    public static function resourceIndexRoutes(): array
    {
        return collect([
            'listings', 'categories', 'areas', 'amenities',
            'events', 'event-categories',
            'cinemas', 'movies',
            'classifieds', 'classified-categories',
            'news', 'news-categories',
            'sliders', 'contact-messages', 'partner-sites',
            'redirects', 'missed-redirects', 'scraper-sources',
        ])->mapWithKeys(fn (string $slug) => [$slug => ["/admin/{$slug}"]])->all();
    }
}
