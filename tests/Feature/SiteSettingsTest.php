<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Module "Paramètres du site" (brief §13) — remplace les valeurs codées en
 * dur dans components/layouts/app.blade.php (nom du site, description,
 * réseaux sociaux) par une ligne administrable (SiteSetting::current()).
 */
class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_guest_is_redirected_from_site_settings(): void
    {
        $this->get('/admin/site-settings')->assertRedirect('/admin/login');
    }

    public function test_admin_can_view_site_settings_page(): void
    {
        $this->actingAs($this->authenticatedAdmin())
            ->get('/admin/site-settings')
            ->assertOk();
    }

    public function test_admin_can_update_site_settings(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        Livewire::test(SiteSettings::class)
            ->fillForm([
                'site_name' => 'ToulouseWeb Test',
                'tagline' => 'La Ville Rose autrement',
                'description' => 'Description de test.',
                'facebook_url' => 'https://facebook.com/toulouseweb',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = SiteSetting::current();
        $this->assertSame('ToulouseWeb Test', $settings->site_name);
        $this->assertSame('La Ville Rose autrement', $settings->tagline);
        $this->assertSame(['https://facebook.com/toulouseweb'], $settings->socialLinks());
    }

    public function test_admin_can_configure_global_seo_analytics_settings(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        Livewire::test(SiteSettings::class)
            ->fillForm([
                'google_analytics_id' => 'G-TESTID123',
                'google_site_verification' => 'test-verification-token',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = SiteSetting::current();
        $this->assertSame('G-TESTID123', $settings->google_analytics_id);
        $this->assertSame('test-verification-token', $settings->google_site_verification);
    }

    public function test_homepage_renders_analytics_and_verification_tags_only_when_configured(): void
    {
        $this->assertStringNotContainsString('googletagmanager', $this->get('/')->getContent());

        SiteSetting::current()->update([
            'google_analytics_id' => 'G-TESTID123',
            'google_site_verification' => 'test-verification-token',
        ]);

        $response = $this->get('/');
        $response->assertSee('googletagmanager.com/gtag/js?id=G-TESTID123', false);
        $response->assertSee('name="google-site-verification" content="test-verification-token"', false);
    }

    public function test_homepage_reflects_custom_site_settings_in_organization_json_ld(): void
    {
        SiteSetting::current()->update([
            'site_name' => 'Mon Portail Local',
            'description' => 'Une description personnalisée.',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Mon Portail Local');
        $response->assertSee('"@context":"https:\/\/schema.org"', false);
        $response->assertSee('Une description personnalisée.', false);
    }

    /**
     * Vrai logo ToulouseWeb (brief, voir docblock de SiteSetting::current()
     * et TECHNICAL_DOCUMENTATION.md §13) — asset statique versionné, PAS un
     * upload sur le disque `public` (ignoré par git).
     */
    public function test_default_logo_is_the_real_toulouseweb_asset(): void
    {
        $this->assertSame('/branding/toulouseweb-logo.png', SiteSetting::current()->logo_url);
        $this->assertFileExists(public_path('branding/toulouseweb-logo.png'));
    }

    public function test_header_and_footer_render_the_logo_image(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('branding/toulouseweb-logo.png', false);
    }

    public function test_favicon_and_apple_touch_icon_use_the_real_icon(): void
    {
        $this->assertFileExists(public_path('branding/toulouseweb-icon.png'));

        $response = $this->get('/');
        $response->assertSee('branding/toulouseweb-icon.png', false);
    }
}
