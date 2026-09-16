<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassifiedResource\Pages\ListClassifieds;
use App\Filament\Resources\EventResource\Pages\ListEvents;
use App\Filament\Resources\ListingResource\Pages\ListListings;
use App\Filament\Resources\NewsResource\Pages\ListNews;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Changement de statut directement depuis la liste, sans ouvrir la fiche
 * (demande client, 16/09/2026 : "Permettre de changer directement les
 * statuts dans les listings et non pas forcement dans l'édition des
 * fiches (pour toutes les entités confondues)") — voir
 * TECHNICAL_DOCUMENTATION.md §45. `Tables\Columns\SelectColumn` remplace
 * le badge `TextColumn` en lecture seule sur les 4 entités à workflow de
 * modération (Listing/Event/News/Classified).
 */
class AdminInlineStatusEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_listing_status_can_be_changed_from_the_list(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $listing = Listing::create(['title' => 'Le Bistrot', 'slug' => 'le-bistrot', 'tier' => 'free', 'status' => 'pending']);

        Livewire::test(ListListings::class)
            ->call('updateTableColumnState', 'status', $listing->getKey(), 'published');

        $this->assertSame('published', $listing->fresh()->status);
    }

    public function test_event_status_can_be_changed_from_the_list(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $event = Event::create(['title' => 'Concert', 'slug' => 'concert', 'status' => 'pending', 'start_date' => now()->addWeek()]);

        Livewire::test(ListEvents::class)
            ->call('updateTableColumnState', 'status', $event->getKey(), 'published');

        $this->assertSame('published', $event->fresh()->status);
    }

    public function test_news_status_can_be_changed_from_the_list(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-inline']);
        $news = News::create(['category_id' => $category->id, 'title' => 'Une actu', 'slug' => 'une-actu-inline', 'body' => 'x', 'status' => 'pending']);

        Livewire::test(ListNews::class)
            ->call('updateTableColumnState', 'status', $news->getKey(), 'published');

        $this->assertSame('published', $news->fresh()->status);
    }

    public function test_classified_status_can_be_changed_from_the_list(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures-inline']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Vélo', 'slug' => 'velo-inline',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'pending',
        ]);

        Livewire::test(ListClassifieds::class)
            ->call('updateTableColumnState', 'status', $classified->getKey(), 'published');

        $this->assertSame('published', $classified->fresh()->status);
    }
}
