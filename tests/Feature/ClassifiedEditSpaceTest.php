<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassifiedResource\Pages\EditClassified;
use App\Filament\Resources\ClassifiedResource\Pages\ListClassifieds;
use App\Mail\SubmitterNotification;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Editor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Espace client annonces (demande client, 16/11/2026, réclamation Mme Alves
 * de Sousa : "j'ai mis une annonce en ligne mais je souhaite la modifier car
 * je ne veux pas que mes coordonnées apparaissent de cette manière") — voir
 * TECHNICAL_DOCUMENTATION.md et App\Http\Controllers\ClassifiedController.
 */
class ClassifiedEditSpaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Depuis le 17/11/2026 (demande client : "changer le mail... avec le
     * lien pour pouvoir créer son compte"), un dépôt anonyme reçoit le lien
     * de CRÉATION DE COMPTE (comptes éditeur, voir
     * Controller::notifySubmitterToManageTheirAccount()), plus l'ancien
     * lien par jeton propre à cette seule annonce.
     */
    public function test_submitting_a_classified_emails_the_submitter_an_account_creation_link(): void
    {
        Mail::fake();
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);

        $this->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Citadine à vendre',
            'description' => 'Très bon état.',
            'contact_email' => 'vendeuse@example.test',
            'website' => '',
        ]);

        $classified = Classified::where('title', 'Citadine à vendre')->firstOrFail();
        $editor = Editor::where('email', 'vendeuse@example.test')->firstOrFail();

        $this->assertSame($editor->id, $classified->editor_id);
        $this->assertFalse($editor->hasRegistered());
        $this->assertSame('vendeuse@example.test', $classified->submitter_email);

        Mail::assertSent(SubmitterNotification::class, function (SubmitterNotification $mail) use ($editor) {
            return $mail->hasTo('vendeuse@example.test')
                && str_contains($mail->actionUrl, $editor->invitation_token);
        });
    }

    public function test_unknown_edit_token_returns_404(): void
    {
        $this->get('/annonces/mon-espace/un-token-invalide')->assertNotFound();
    }

    public function test_edit_space_shows_the_classified_current_data(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'Peu servi.', 'contact_email' => 'a@example.test',
            'submitter_email' => 'a@example.test', 'status' => 'published',
        ]);

        $this->get('/annonces/mon-espace/'.$classified->edit_token)
            ->assertOk()
            ->assertSee('Scooter 125')
            ->assertSee('Peu servi.');
    }

    public function test_editing_does_not_change_the_live_published_listing_until_admin_approves(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'Peu servi.', 'contact_email' => 'ancien@example.test',
            'submitter_email' => 'ancien@example.test', 'status' => 'published',
        ]);

        $response = $this->put('/annonces/mon-espace/'.$classified->edit_token, [
            'title' => 'Scooter 125 (prix baissé)',
            'description' => 'Peu servi, prix négociable.',
            'contact_email' => 'nouveau@example.test',
            'website' => '',
        ]);

        $response->assertRedirect(route('annonces.editSpace', $classified->edit_token));

        // La fiche PUBLIÉE reste inchangée tant que l'équipe n'a pas validé
        // (voir docblock de la migration — pas de status=pending ici).
        $this->get('/annonces/scooter-125')->assertOk()->assertSee('Scooter 125')->assertDontSee('prix baissé');

        $classified->refresh();
        $this->assertSame('published', $classified->status);
        $this->assertSame('ancien@example.test', $classified->submitter_email);
        $this->assertSame('Scooter 125 (prix baissé)', $classified->pending_changes['title']);
        $this->assertSame('nouveau@example.test', $classified->pending_changes['contact_email']);
    }

    public function test_editing_rejects_honeypot_bots(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'Peu servi.', 'contact_email' => 'a@example.test',
            'submitter_email' => 'a@example.test', 'status' => 'published',
        ]);

        $this->put('/annonces/mon-espace/'.$classified->edit_token, [
            'title' => 'Scooter spam',
            'description' => 'x',
            'contact_email' => 'a@example.test',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertNull($classified->fresh()->pending_changes);
    }

    public function test_admin_applying_the_pending_change_updates_the_live_listing(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'Peu servi.', 'contact_email' => 'ancien@example.test',
            'submitter_email' => 'ancien@example.test', 'status' => 'published',
            'pending_changes' => [
                'title' => 'Scooter 125',
                'description' => 'Peu servi.',
                'price' => null,
                'location' => null,
                'contact_phone' => null,
                'contact_email' => 'nouveau@example.test',
            ],
        ]);

        $classified->applyPendingChanges();
        $classified->refresh();

        $this->assertSame('nouveau@example.test', $classified->contact_email);
        $this->assertNull($classified->pending_changes);
        $this->assertSame('published', $classified->status);
    }

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_admin_can_apply_the_pending_change_from_the_list_action(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'Peu servi.', 'contact_email' => 'ancien@example.test',
            'submitter_email' => 'ancien@example.test', 'status' => 'published',
            'pending_changes' => ['title' => 'Scooter 125', 'description' => 'Peu servi.', 'price' => null, 'location' => null, 'contact_phone' => null, 'contact_email' => 'nouveau@example.test'],
        ]);

        // Le filtre "Statut" de la liste par défaut sur "pending" (brief
        // §8) — notre fiche est déjà "published", il faut le désactiver
        // pour que l'action la trouve.
        Livewire::test(ListClassifieds::class)
            ->filterTable('status', null)
            ->callTableAction('applyPendingChanges', $classified);

        $this->assertSame('nouveau@example.test', $classified->fresh()->contact_email);
        $this->assertNull($classified->fresh()->pending_changes);
    }

    public function test_classified_edit_page_renders_with_a_pending_change(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'Peu servi.', 'contact_email' => 'ancien@example.test',
            'submitter_email' => 'ancien@example.test', 'status' => 'published',
            'pending_changes' => ['title' => 'Scooter 125', 'description' => 'Peu servi.', 'price' => null, 'location' => null, 'contact_phone' => null, 'contact_email' => 'nouveau@example.test'],
        ]);

        Livewire::test(EditClassified::class, ['record' => $classified->getKey()])->assertOk();
    }
}
