<?php

namespace Tests\Feature;

use App\Filament\Resources\EventResource\Pages\ListEvents;
use App\Mail\SubmitterNotification;
use App\Models\Area;
use App\Models\Editor;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Espace client agenda, étape 2 (demande client, 16/11/2026) — voir
 * TECHNICAL_DOCUMENTATION.md et tests\Feature\ClassifiedEditSpaceTest (étape
 * 1, même mécanisme).
 */
class EventEditSpaceTest extends TestCase
{
    use RefreshDatabase;

    /** Depuis le 17/11/2026, voir ClassifiedEditSpaceTest::test_submitting_a_classified_emails_the_submitter_an_account_creation_link(). */
    public function test_submitting_an_event_emails_the_submitter_an_account_creation_link(): void
    {
        Mail::fake();
        $category = EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts']);

        $this->post('/agenda/proposer', [
            'event_category_id' => $category->id,
            'title' => 'Concert de jazz',
            'venue_name' => 'Salle des fêtes',
            'start_date' => now()->addWeek()->format('Y-m-d H:i:s'),
            'submitter_email' => 'proposant@example.test',
            'website' => '',
        ]);

        $event = Event::firstOrFail();
        $editor = Editor::where('email', 'proposant@example.test')->firstOrFail();
        $this->assertSame($editor->id, $event->editor_id);

        Mail::assertSent(SubmitterNotification::class, fn (SubmitterNotification $mail) => $mail->hasTo('proposant@example.test')
            && str_contains($mail->actionUrl, $editor->invitation_token));
    }

    public function test_unknown_edit_token_returns_404(): void
    {
        $this->get('/agenda/mon-espace/un-token-invalide')->assertNotFound();
    }

    public function test_editing_does_not_change_the_live_published_event_until_admin_approves(): void
    {
        $area = Area::create(['name' => 'Salle des fêtes']);
        $event = Event::create([
            'area_id' => $area->id, 'title' => 'Concert de jazz', 'slug' => 'concert-de-jazz',
            'start_date' => now()->addWeek(), 'submitter_email' => 'proposant@example.test', 'status' => 'published',
        ]);

        $response = $this->put('/agenda/mon-espace/'.$event->edit_token, [
            'title' => 'Concert de jazz (complet)',
            'start_date' => $event->start_date->format('Y-m-d H:i:s'),
            'website' => '',
        ]);

        $response->assertRedirect(route('agenda.editSpace', $event->edit_token));

        $this->get('/agenda/concert-de-jazz')->assertOk()->assertSee('Concert de jazz')->assertDontSee('complet');

        $event->refresh();
        $this->assertSame('published', $event->status);
        $this->assertSame('Concert de jazz (complet)', $event->pending_changes['title']);
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
        $area = Area::create(['name' => 'Salle des fêtes']);
        $event = Event::create([
            'area_id' => $area->id, 'title' => 'Concert de jazz', 'slug' => 'concert-de-jazz',
            'start_date' => now()->addWeek(), 'submitter_email' => 'proposant@example.test', 'status' => 'published',
            'pending_changes' => ['title' => 'Concert de jazz (complet)', 'description' => null, 'price' => null, 'booking_url' => null, 'start_date' => now()->addWeek()->format('Y-m-d H:i:s'), 'end_date' => null],
        ]);

        Livewire::test(ListEvents::class)
            ->filterTable('status', null)
            ->callTableAction('applyPendingChanges', $event);

        $this->assertSame('Concert de jazz (complet)', $event->fresh()->title);
        $this->assertNull($event->fresh()->pending_changes);
    }
}
