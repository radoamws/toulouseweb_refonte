<?php

namespace Tests\Feature;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Editor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Comptes éditeur ("Grande modification", demande client 17/11/2026) — voir
 * TECHNICAL_DOCUMENTATION.md, App\Models\Editor et
 * App\Http\Controllers\EditorAuthController.
 */
class EditorAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_link_shows_the_signup_form_for_an_unregistered_account(): void
    {
        $editor = Editor::create(['email' => 'nouveau@example.test', 'invitation_token' => 'un-token-de-test']);

        $this->get('/mon-compte/inscription/un-token-de-test')
            ->assertOk()
            ->assertSee('nouveau@example.test');
    }

    public function test_invitation_link_redirects_to_login_once_already_registered(): void
    {
        $editor = Editor::create([
            'email' => 'deja@example.test', 'name' => 'Déjà Inscrit',
            'password' => Hash::make('un-mot-de-passe'), 'invitation_token' => null,
        ]);
        // Un lien déjà utilisé garde son token le temps du test suivant — ici on simule le cas où il est encore connu quelque part (ex. mis en cache côté client).
        $editor->forceFill(['invitation_token' => 'token-deja-utilise'])->save();

        $this->get('/mon-compte/inscription/token-deja-utilise')
            ->assertRedirect(route('editeur.connexion'));
    }

    public function test_unknown_invitation_token_returns_404(): void
    {
        $this->get('/mon-compte/inscription/inconnu')->assertNotFound();
    }

    public function test_registering_sets_the_password_clears_the_token_and_logs_in(): void
    {
        $editor = Editor::create(['email' => 'nouveau@example.test', 'invitation_token' => 'un-token-de-test']);

        $response = $this->post('/mon-compte/inscription/un-token-de-test', [
            'name' => 'Jeanne Dupont',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'website' => '',
        ]);

        $response->assertRedirect(route('editeur.mes-publications'));

        $editor->refresh();
        $this->assertSame('Jeanne Dupont', $editor->name);
        $this->assertNull($editor->invitation_token);
        $this->assertTrue(Hash::check('motdepasse123', $editor->password));
        $this->assertAuthenticatedAs($editor, 'editor');
    }

    public function test_registering_rejects_honeypot_bots(): void
    {
        $editor = Editor::create(['email' => 'nouveau@example.test', 'invitation_token' => 'un-token-de-test']);

        $this->post('/mon-compte/inscription/un-token-de-test', [
            'name' => 'Bot',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertNull($editor->fresh()->password);
    }

    public function test_login_with_correct_credentials_succeeds(): void
    {
        $editor = Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('motdepasse123')]);

        $response = $this->post('/mon-compte/connexion', [
            'email' => 'jeanne@example.test',
            'password' => 'motdepasse123',
            'website' => '',
        ]);

        $response->assertRedirect(route('editeur.mes-publications'));
        $this->assertAuthenticatedAs($editor, 'editor');
    }

    public function test_login_with_wrong_password_fails(): void
    {
        Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('motdepasse123')]);

        $this->post('/mon-compte/connexion', [
            'email' => 'jeanne@example.test',
            'password' => 'mauvais-mot-de-passe',
            'website' => '',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('editor');
    }

    public function test_logout_clears_the_session(): void
    {
        $editor = Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('motdepasse123')]);

        $this->actingAs($editor, 'editor')
            ->post('/mon-compte/deconnexion')
            ->assertRedirect(route('home'));

        $this->assertGuest('editor');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/mon-compte')->assertRedirect(route('editeur.connexion'));
    }

    public function test_dashboard_lists_only_the_authenticated_editors_own_classifieds(): void
    {
        $editor = Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('motdepasse123')]);
        $otherEditor = Editor::create(['email' => 'autre@example.test', 'name' => 'Autre', 'password' => Hash::make('motdepasse123')]);
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);

        $mine = Classified::create([
            'category_id' => $category->id, 'title' => 'Ma voiture', 'slug' => 'ma-voiture',
            'description' => 'x', 'contact_email' => 'jeanne@example.test', 'editor_id' => $editor->id, 'status' => 'published',
        ]);
        Classified::create([
            'category_id' => $category->id, 'title' => 'Pas la mienne', 'slug' => 'pas-la-mienne',
            'description' => 'x', 'contact_email' => 'autre@example.test', 'editor_id' => $otherEditor->id, 'status' => 'published',
        ]);

        $this->actingAs($editor, 'editor')
            ->get('/mon-compte')
            ->assertOk()
            ->assertSee('Ma voiture')
            ->assertDontSee('Pas la mienne');
    }

    public function test_editor_cannot_open_the_edit_form_of_someone_elses_classified(): void
    {
        $editor = Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('motdepasse123')]);
        $otherEditor = Editor::create(['email' => 'autre@example.test', 'name' => 'Autre', 'password' => Hash::make('motdepasse123')]);
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $notMine = Classified::create([
            'category_id' => $category->id, 'title' => 'Pas la mienne', 'slug' => 'pas-la-mienne',
            'description' => 'x', 'contact_email' => 'autre@example.test', 'editor_id' => $otherEditor->id, 'status' => 'published',
        ]);

        $this->actingAs($editor, 'editor')
            ->get(route('editeur.annonces.modifier', $notMine))
            ->assertForbidden();
    }

    public function test_editor_can_edit_their_own_classified_with_full_fields_including_category(): void
    {
        $editor = Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('motdepasse123')]);
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $otherCategory = ClassifiedCategory::create(['name' => 'Immobilier', 'slug' => 'immobilier']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Ma voiture', 'slug' => 'ma-voiture',
            'description' => 'x', 'contact_email' => 'jeanne@example.test', 'editor_id' => $editor->id, 'status' => 'published',
        ]);

        $response = $this->actingAs($editor, 'editor')->put(route('editeur.annonces.modifier.store', $classified), [
            'category_id' => $otherCategory->id,
            'title' => 'Ma voiture (prix baissé)',
            'description' => 'x',
            'contact_email' => 'jeanne@example.test',
            'website' => '',
        ]);

        $response->assertRedirect(route('editeur.mes-publications'));

        $classified->refresh();
        $this->assertSame('published', $classified->status);
        $this->assertSame($category->id, $classified->category_id);
        $this->assertSame((string) $otherCategory->id, (string) $classified->pending_changes['category_id']);
        $this->assertSame('Ma voiture (prix baissé)', $classified->pending_changes['title']);
    }
}
