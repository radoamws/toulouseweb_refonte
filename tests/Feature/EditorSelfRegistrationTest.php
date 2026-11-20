<?php

namespace Tests\Feature;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Editor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Inscription libre espace éditeur (demande client, 17/11/2026 : "ajoute
 * aussi l'option de s'inscrire en front") — voir
 * App\Http\Controllers\EditorAuthController::showSignup()/signup() et
 * TECHNICAL_DOCUMENTATION.md.
 */
class EditorSelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_page_is_accessible(): void
    {
        $this->get('/mon-compte/inscription')->assertOk()->assertSee("Créer mon espace");
    }

    public function test_signing_up_creates_an_account_and_logs_in(): void
    {
        $response = $this->post('/mon-compte/inscription', [
            'name' => 'Jeanne Dupont',
            'email' => 'jeanne@example.test',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'website' => '',
        ]);

        $response->assertRedirect(route('editeur.mes-publications'));

        $editor = Editor::where('email', 'jeanne@example.test')->firstOrFail();
        $this->assertSame('Jeanne Dupont', $editor->name);
        $this->assertTrue(Hash::check('motdepasse123', $editor->password));
        $this->assertAuthenticatedAs($editor, 'editor');
    }

    public function test_signup_rejects_honeypot_bots(): void
    {
        $this->post('/mon-compte/inscription', [
            'name' => 'Bot',
            'email' => 'bot@example.test',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('editors', ['email' => 'bot@example.test']);
    }

    public function test_signup_with_an_email_already_registered_is_rejected(): void
    {
        Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('ancien-mdp')]);

        $this->post('/mon-compte/inscription', [
            'name' => 'Jeanne Dupont',
            'email' => 'jeanne@example.test',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'website' => '',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('editor');
    }

    /**
     * Cas réel visé : un déposant historique (migré par
     * `editeurs:migrer-historique`, §76) a un compte Editor déjà créé mais
     * jamais inscrit (password NULL, invitation en attente). S'il
     * s'inscrit lui-même via ce formulaire plutôt que d'utiliser son lien
     * reçu par email, il doit RÉCUPÉRER ce compte (et donc ses publications
     * déjà rattachées), pas en créer un second en échec sur l'email unique.
     */
    public function test_signing_up_with_an_email_that_has_an_unregistered_historical_account_claims_it(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $historical = Editor::create(['email' => 'jeanne@example.test', 'invitation_token' => 'un-token-historique']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'x', 'contact_email' => 'jeanne@example.test', 'editor_id' => $historical->id, 'status' => 'published',
        ]);

        $response = $this->post('/mon-compte/inscription', [
            'name' => 'Jeanne Dupont',
            'email' => 'jeanne@example.test',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'website' => '',
        ]);

        $response->assertRedirect(route('editeur.mes-publications'));

        $this->assertDatabaseCount('editors', 1);
        $historical->refresh();
        $this->assertSame('Jeanne Dupont', $historical->name);
        $this->assertNull($historical->invitation_token);
        $this->assertTrue(Hash::check('motdepasse123', $historical->password));
        $this->assertAuthenticatedAs($historical, 'editor');

        $this->actingAs($historical, 'editor')->get('/mon-compte')->assertSee($classified->title);
    }

    public function test_login_page_links_to_signup(): void
    {
        $this->get('/mon-compte/connexion')->assertSee(route('editeur.inscription.libre'), false);
    }

    // ----- Point 4 : préremplissage de l'email + rattachement auto -----

    public function test_classified_create_form_prefills_the_email_for_a_logged_in_editor(): void
    {
        $editor = Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('x')]);

        $this->actingAs($editor, 'editor')
            ->get('/annonces/deposer')
            ->assertSee('value="jeanne@example.test"', false);
    }

    public function test_classified_create_form_leaves_the_email_empty_for_a_guest(): void
    {
        $this->get('/annonces/deposer')->assertDontSee('value="jeanne@example.test"', false);
    }

    public function test_submitting_a_classified_while_logged_in_links_it_to_the_editor(): void
    {
        $editor = Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('x')]);
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);

        $this->actingAs($editor, 'editor')->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Citadine à vendre',
            'description' => 'Très bon état.',
            'contact_email' => 'jeanne@example.test',
            'website' => '',
        ]);

        $classified = Classified::where('title', 'Citadine à vendre')->firstOrFail();
        $this->assertSame($editor->id, $classified->editor_id);
    }

    /**
     * Mis à jour le 17/11/2026 (§80) : un dépôt anonyme n'est plus "orphelin"
     * — `editor_id` pointe désormais vers un compte éditeur trouvé/créé par
     * email (`Editor::findOrInviteByEmail()`), pas encore inscrit, voir
     * ClassifiedEditSpaceTest::test_submitting_a_classified_emails_the_submitter_an_account_creation_link().
     */
    public function test_submitting_a_classified_as_a_guest_creates_and_links_an_unregistered_editor(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);

        $this->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Citadine à vendre',
            'description' => 'Très bon état.',
            'contact_email' => 'anonyme@example.test',
            'website' => '',
        ]);

        $classified = Classified::where('title', 'Citadine à vendre')->firstOrFail();
        $editor = Editor::where('email', 'anonyme@example.test')->firstOrFail();
        $this->assertSame($editor->id, $classified->editor_id);
        $this->assertFalse($editor->hasRegistered());
    }
}
