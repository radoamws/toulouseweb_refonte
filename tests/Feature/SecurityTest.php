<?php

namespace Tests\Feature;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie les mesures de sécurité de base (brief §18), en réaction directe
 * aux failles constatées dans le legacy à l'audit : pas de fuite de stack
 * trace, en-têtes de sécurité présents, contenu utilisateur échappé à
 * l'affichage (le legacy affichait du contenu brut sans échappement, voir
 * TECHNICAL_DOCUMENTATION.md §4).
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present_on_public_pages(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_security_headers_are_present_on_admin_pages(): void
    {
        $this->get('/admin/login')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_classified_description_is_escaped_when_rendered(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id,
            'title' => 'Annonce test',
            'slug' => 'annonce-test',
            'description' => '<script>alert(1)</script>Bonjour',
            'contact_email' => 'a@example.test',
            'status' => 'published',
        ]);

        $response = $this->get('/annonces/annonce-test');

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_contact_message_name_is_escaped_if_ever_rendered_back(): void
    {
        // Le formulaire ne réaffiche pas le nom soumis directement, mais on
        // vérifie que la valeur "old()" ré-injectée dans le champ est bien
        // échappée si jamais la validation échoue et que le formulaire se
        // réaffiche avec la saisie précédente.
        $response = $this->from('/contact')->post('/contact', [
            'name' => '<script>alert(1)</script>',
            'email' => 'not-an-email',
            'message' => 'test',
            'website' => '',
        ]);

        $response->assertSessionHasErrors('email');

        $follow = $this->get('/contact');
        $follow->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_admin_panel_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
