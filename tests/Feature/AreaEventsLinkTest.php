<?php

namespace Tests\Feature;

use App\Filament\Resources\AreaResource;
use App\Filament\Resources\AreaResource\Pages\ListAreas;
use App\Filament\Resources\EventResource;
use App\Filament\Resources\EventResource\Pages\ListEvents;
use App\Models\Area;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bouton "Voir les événements" sur /admin/areas (demande client, 19/09/2026 :
 * "ajoute un bouton pour voir tous les evenements de l'Areas... équivalent à
 * la page 'Evenements' de l'admin mais filtré selon l'area en cours") — voir
 * TECHNICAL_DOCUMENTATION.md. Réutilise le nouveau filtre `area_id` de
 * EventResource, pré-rempli via l'URL (`tableFilters[area_id][value]`).
 */
class AreaEventsLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_view_events_action_links_to_the_event_list_filtered_by_this_area(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $area = Area::create(['name' => 'Le Bijou', 'slug' => 'le-bijou-link-test']);

        $expectedUrl = EventResource::getUrl('index', [
            'tableFilters' => ['area_id' => ['value' => $area->id]],
        ]);

        Livewire::test(ListAreas::class)
            ->assertTableActionHasUrl('viewEvents', $expectedUrl, record: $area);
    }

    public function test_event_list_can_be_filtered_by_area(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $bijou = Area::create(['name' => 'Le Bijou', 'slug' => 'le-bijou-filter-test']);
        $garonne = Area::create(['name' => 'Théâtre Garonne', 'slug' => 'theatre-garonne-filter-test']);

        $bijouEvent = Event::create(['title' => 'Standup', 'slug' => 'standup-filter-test', 'area_id' => $bijou->id, 'status' => 'published', 'start_date' => now()->addWeek()]);
        $garonneEvent = Event::create(['title' => 'Les Gaulois', 'slug' => 'les-gaulois-filter-test', 'area_id' => $garonne->id, 'status' => 'published', 'start_date' => now()->addWeek()]);

        Livewire::test(ListEvents::class)
            ->filterTable('area_id', $bijou->id)
            ->assertCanSeeTableRecords([$bijouEvent])
            ->assertCanNotSeeTableRecords([$garonneEvent]);
    }
}
