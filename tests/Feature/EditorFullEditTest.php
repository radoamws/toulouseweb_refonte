<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Category;
use App\Models\Editor;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Espace éditeur, édition à champs complets répliquée sur Event/News/Listing
 * (demande client, 17/11/2026, "oui, applique pour les 3 entités
 * restantes") — voir tests\Feature\EditorAccountTest (pilote Classified) et
 * TECHNICAL_DOCUMENTATION.md §77.
 */
class EditorFullEditTest extends TestCase
{
    use RefreshDatabase;

    protected function editor(): Editor
    {
        return Editor::create(['email' => 'jeanne@example.test', 'name' => 'Jeanne', 'password' => Hash::make('motdepasse123')]);
    }

    // ----- Event -----

    public function test_editor_cannot_open_the_edit_form_of_someone_elses_event(): void
    {
        $editor = $this->editor();
        $other = Editor::create(['email' => 'autre@example.test', 'password' => Hash::make('x')]);
        $area = Area::create(['name' => 'Salle des fêtes']);
        $event = Event::create(['area_id' => $area->id, 'title' => 'Concert', 'slug' => 'concert', 'start_date' => now()->addWeek(), 'editor_id' => $other->id, 'status' => 'published']);

        $this->actingAs($editor, 'editor')->get(route('editeur.agenda.modifier', $event))->assertForbidden();
    }

    public function test_editor_can_edit_their_own_event_including_category_without_touching_the_live_record(): void
    {
        $editor = $this->editor();
        $area = Area::create(['name' => 'Salle des fêtes']);
        $categoryA = EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts']);
        $categoryB = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre-full-edit']);
        $event = Event::create(['area_id' => $area->id, 'title' => 'Concert', 'slug' => 'concert', 'start_date' => now()->addWeek(), 'editor_id' => $editor->id, 'status' => 'published']);
        $event->categories()->attach($categoryA->id);

        $response = $this->actingAs($editor, 'editor')->put(route('editeur.agenda.modifier.store', $event), [
            'event_category_id' => $categoryB->id,
            'title' => 'Concert (complet)',
            'venue_name' => 'Salle des fêtes',
            'start_date' => $event->start_date->format('Y-m-d H:i:s'),
            'website' => '',
        ]);

        $response->assertRedirect(route('editeur.mes-publications'));

        $event->refresh();
        $this->assertSame('Concert', $event->title);
        $this->assertTrue($event->categories->contains($categoryA->id));
        $this->assertSame('Concert (complet)', $event->pending_changes['title']);
        $this->assertSame((string) $categoryB->id, (string) $event->pending_changes['_pending_category_id']);

        $event->applyPendingChanges();
        $event->refresh();
        $this->assertSame('Concert (complet)', $event->title);
        $this->assertTrue($event->categories->contains($categoryB->id));
        $this->assertFalse($event->categories->contains($categoryA->id));
        $this->assertNull($event->pending_changes);
    }

    // ----- News -----

    public function test_editor_cannot_open_the_edit_form_of_someone_elses_news(): void
    {
        $editor = $this->editor();
        $other = Editor::create(['email' => 'autre@example.test', 'password' => Hash::make('x')]);
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $news = News::create(['category_id' => $category->id, 'title' => 'Brocante', 'slug' => 'brocante', 'body' => 'x', 'editor_id' => $other->id, 'status' => 'published']);

        $this->actingAs($editor, 'editor')->get(route('editeur.actualites.modifier', $news))->assertForbidden();
    }

    public function test_editor_can_edit_their_own_news_with_full_fields(): void
    {
        $editor = $this->editor();
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $news = News::create(['category_id' => $category->id, 'title' => 'Brocante', 'slug' => 'brocante', 'body' => 'x', 'editor_id' => $editor->id, 'status' => 'published']);

        $response = $this->actingAs($editor, 'editor')->put(route('editeur.actualites.modifier.store', $news), [
            'category_id' => $category->id,
            'title' => 'Brocante (annulée)',
            'body' => 'x',
            'start_date' => now()->addWeek()->format('Y-m-d'),
            'end_date' => now()->addWeek()->format('Y-m-d'),
            'url_verification' => '',
        ]);

        $response->assertRedirect(route('editeur.mes-publications'));

        $news->refresh();
        $this->assertSame('Brocante', $news->title);
        $this->assertSame('Brocante (annulée)', $news->pending_changes['title']);

        $news->applyPendingChanges();
        $this->assertSame('Brocante (annulée)', $news->fresh()->title);
    }

    // ----- Listing -----

    public function test_editor_cannot_open_the_edit_form_of_someone_elses_listing(): void
    {
        $editor = $this->editor();
        $other = Editor::create(['email' => 'autre@example.test', 'password' => Hash::make('x')]);
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-full-edit', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create(['title' => 'Le Bistrot', 'slug' => 'le-bistrot', 'tier' => 'free', 'status' => 'published', 'editor_id' => $other->id]);
        $listing->categories()->attach($category->id);

        $this->actingAs($editor, 'editor')->get(route('editeur.annuaire.modifier', $listing))->assertForbidden();
    }

    public function test_editor_can_edit_their_own_listing_including_category_and_logo(): void
    {
        Storage::fake('public');
        $editor = $this->editor();
        $categoryA = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-full-edit', 'level' => 0, 'is_active' => true]);
        $categoryB = Category::create(['name' => 'Bars', 'slug' => 'bars-full-edit', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create(['title' => 'Le Bistrot', 'slug' => 'le-bistrot', 'tier' => 'free', 'status' => 'published', 'editor_id' => $editor->id]);
        $listing->categories()->attach($categoryA->id);

        $response = $this->actingAs($editor, 'editor')->put(route('editeur.annuaire.modifier.store', $listing), [
            'category_id' => $categoryB->id,
            'tier' => 'paid',
            'title' => 'Le Bistrot (fermé le dimanche)',
            'logo' => UploadedFile::fake()->image('logo.jpg', 200, 200),
            'url_verification' => '',
        ]);

        $response->assertRedirect(route('editeur.mes-publications'));

        $listing->refresh();
        $this->assertSame('Le Bistrot', $listing->title);
        $this->assertSame('free', $listing->tier);
        $this->assertTrue($listing->categories->contains($categoryA->id));
        $this->assertSame('Le Bistrot (fermé le dimanche)', $listing->pending_changes['title']);
        $this->assertSame('paid', $listing->pending_changes['tier']);
        $this->assertArrayHasKey('_pending_logo_path', $listing->pending_changes);

        $listing->applyPendingChanges();
        $listing->refresh();
        $this->assertSame('Le Bistrot (fermé le dimanche)', $listing->title);
        $this->assertSame('paid', $listing->tier);
        $this->assertTrue($listing->categories->contains($categoryB->id));
        $this->assertFalse($listing->categories->contains($categoryA->id));
        $this->assertCount(1, $listing->getMedia('logo'));
        $this->assertNull($listing->pending_changes);
    }
}
