<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dépôt public d'un événement (brief §6, phase 7 — proposition d'événement
 * par le public, gap identifié au §0/§13 de TECHNICAL_DOCUMENTATION.md).
 * Même workflow de modération strict que les annonces/fiches annuaire
 * (App\Http\Controllers\EventController::store()) : vérifie qu'il ne peut
 * pas être contourné depuis le frontend.
 */
class PublicEventSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_renders(): void
    {
        EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);

        $this->get('/agenda/proposer')->assertOk()->assertSee('Proposer un événement');
    }

    public function test_submission_always_starts_pending_and_is_not_publicly_visible(): void
    {
        $category = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);

        $response = $this->post('/agenda/proposer', [
            'event_category_id' => $category->id,
            'title' => 'Ma pièce de théâtre',
            'venue_name' => 'Salle des Fêtes de Test',
            'start_date' => now()->addWeek()->format('Y-m-d\TH:i'),
            'website' => '', // honeypot vide = humain
        ]);

        $response->assertRedirect(route('agenda.index'));

        $event = Event::where('title', 'Ma pièce de théâtre')->firstOrFail();
        $this->assertSame('pending', $event->status);
        $this->assertSame('user_submitted', $event->source);

        $this->assertNotNull($event->area);
        $this->assertSame('Salle des Fêtes de Test', $event->area->name);

        // Pas visible publiquement tant qu'il n'est pas validé par l'admin.
        $this->get('/agenda/'.$event->slug)->assertNotFound();
        $this->get('/agenda')->assertOk()->assertDontSee('Ma pièce de théâtre');
    }

    public function test_submission_cannot_inject_a_status_or_source_field(): void
    {
        $category = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);

        $this->post('/agenda/proposer', [
            'event_category_id' => $category->id,
            'title' => 'Tentative de contournement',
            'venue_name' => 'Un lieu',
            'start_date' => now()->addWeek()->format('Y-m-d\TH:i'),
            'status' => 'published', // ne doit jamais être pris en compte
            'source' => 'scraped', // idem
            'website' => '',
        ]);

        $event = Event::where('title', 'Tentative de contournement')->firstOrFail();
        $this->assertSame('pending', $event->status);
        $this->assertSame('user_submitted', $event->source);
    }

    public function test_submission_rejected_when_honeypot_filled(): void
    {
        $category = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);

        $this->post('/agenda/proposer', [
            'event_category_id' => $category->id,
            'title' => 'Spam bot',
            'venue_name' => 'Un lieu',
            'start_date' => now()->addWeek()->format('Y-m-d\TH:i'),
            'website' => 'http://spam.example', // honeypot rempli = bot
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('events', ['title' => 'Spam bot']);
    }

    public function test_reuses_existing_area_with_the_same_name_instead_of_duplicating(): void
    {
        $category = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $existingArea = Area::create(['name' => 'Salle Déjà Connue', 'slug' => 'salle-deja-connue']);

        $this->post('/agenda/proposer', [
            'event_category_id' => $category->id,
            'title' => 'Un évènement',
            'venue_name' => 'Salle Déjà Connue',
            'start_date' => now()->addWeek()->format('Y-m-d\TH:i'),
            'website' => '',
        ]);

        $event = Event::where('title', 'Un évènement')->firstOrFail();
        $this->assertSame($existingArea->id, $event->area_id);
        $this->assertSame(1, Area::where('name', 'Salle Déjà Connue')->count());
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $category = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);

        $this->post('/agenda/proposer', [
            'event_category_id' => $category->id,
            'title' => 'Dates incohérentes',
            'venue_name' => 'Un lieu',
            'start_date' => now()->addWeek()->format('Y-m-d\TH:i'),
            'end_date' => now()->format('Y-m-d\TH:i'),
            'website' => '',
        ])->assertSessionHasErrors('end_date');

        $this->assertDatabaseMissing('events', ['title' => 'Dates incohérentes']);
    }
}
