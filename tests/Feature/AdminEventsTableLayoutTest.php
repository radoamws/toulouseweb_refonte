<?php

namespace Tests\Feature;

use App\Filament\Resources\EventResource\Pages\ListEvents;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⚠️ Bug réel trouvé et corrigé (21/09/2026, capture client) : sur
 * /admin/events, un titre long ("MOUSQUETAIRE, UNE CREATION DU PUY DU FOU",
 * "Johnny Symphonique Tour"...) débordait sur une seule ligne non coupée,
 * chevauchant visuellement la colonne "Catégories" suivante — voir
 * EventResource::table(), colonne `title`, `->wrap()` ajouté.
 */
class AdminEventsTableLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_long_event_title_column_wraps_instead_of_overflowing(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        Event::create([
            'title' => 'MOUSQUETAIRE, UNE CREATION DU PUY DU FOU EN TOURNEE NATIONALE 2027',
            'slug' => 'mousquetaire-creation-puy-du-fou',
            'status' => 'published',
            'start_date' => now()->addMonth(),
        ]);

        $html = Livewire::test(ListEvents::class)->html();

        $this->assertStringContainsString('MOUSQUETAIRE, UNE CREATION DU PUY DU FOU', $html);
        $this->assertStringContainsString('whitespace-normal', $html);
    }
}
