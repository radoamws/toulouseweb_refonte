<?php

namespace Tests\Feature;

use App\Filament\Resources\ListingResource\Pages\ListListings;
use App\Mail\SubmitterNotification;
use App\Models\Category;
use App\Models\Editor;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Espace client annuaire, étape 3 (demande client, 16/11/2026) — voir
 * TECHNICAL_DOCUMENTATION.md et tests\Feature\ClassifiedEditSpaceTest (étape
 * 1, même mécanisme). Contrairement aux annonces/agenda/actualités, `email`
 * reste facultatif sur le dépôt annuaire — un dépôt sans email n'a
 * simplement pas d'espace client (voir Listing::booted()).
 */
class ListingEditSpaceTest extends TestCase
{
    use RefreshDatabase;

    /** Depuis le 17/11/2026, voir ClassifiedEditSpaceTest::test_submitting_a_classified_emails_the_submitter_an_account_creation_link(). */
    public function test_submitting_a_listing_with_an_email_emails_the_submitter_an_account_creation_link(): void
    {
        Mail::fake();
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'tier' => 'free',
            'title' => 'Le Bistrot',
            'email' => 'proprietaire@example.test',
            'url_verification' => '',
        ]);

        $listing = Listing::firstOrFail();
        $editor = Editor::where('email', 'proprietaire@example.test')->firstOrFail();
        $this->assertSame($editor->id, $listing->editor_id);
        $this->assertSame('proprietaire@example.test', $listing->submitter_email);

        Mail::assertSent(SubmitterNotification::class, fn (SubmitterNotification $mail) => $mail->hasTo('proprietaire@example.test')
            && str_contains($mail->actionUrl, $editor->invitation_token));
    }

    public function test_submitting_a_listing_without_an_email_has_no_edit_space(): void
    {
        Mail::fake();
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'tier' => 'free',
            'title' => 'Le Bistrot',
            'url_verification' => '',
        ]);

        $listing = Listing::firstOrFail();
        $this->assertNull($listing->edit_token);
        Mail::assertNotSent(SubmitterNotification::class);
    }

    public function test_unknown_edit_token_returns_404(): void
    {
        $this->get('/annuaire/mon-espace/un-token-invalide')->assertNotFound();
    }

    public function test_editing_does_not_change_the_live_published_listing_until_admin_approves(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => 'Le Bistrot', 'slug' => 'le-bistrot', 'tier' => 'free', 'status' => 'published',
            'email' => 'ancien@example.test', 'submitter_email' => 'ancien@example.test',
        ]);
        $listing->categories()->attach($category->id);

        $response = $this->put('/annuaire/mon-espace/'.$listing->edit_token, [
            'title' => 'Le Bistrot (fermé le dimanche)',
            'email' => 'nouveau@example.test',
            'url_verification' => '',
        ]);

        $response->assertRedirect(route('annuaire.editSpace', $listing->edit_token));

        $this->get('/annuaire/fiche/le-bistrot')->assertOk()->assertSee('Le Bistrot')->assertDontSee('fermé le dimanche');

        $listing->refresh();
        $this->assertSame('published', $listing->status);
        $this->assertSame('ancien@example.test', $listing->submitter_email);
        $this->assertSame('Le Bistrot (fermé le dimanche)', $listing->pending_changes['title']);
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
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => 'Le Bistrot', 'slug' => 'le-bistrot', 'tier' => 'free', 'status' => 'published',
            'email' => 'ancien@example.test', 'submitter_email' => 'ancien@example.test',
            'pending_changes' => ['title' => 'Le Bistrot (fermé le dimanche)', 'address' => null, 'city' => null, 'postal_code' => null, 'phone' => null, 'email' => 'nouveau@example.test', 'short_description' => null, 'description' => null, 'website' => null, 'reservation_url' => null],
        ]);
        $listing->categories()->attach($category->id);

        Livewire::test(ListListings::class)
            ->callTableAction('applyPendingChanges', $listing);

        $this->assertSame('Le Bistrot (fermé le dimanche)', $listing->fresh()->title);
        $this->assertNull($listing->fresh()->pending_changes);
    }
}
