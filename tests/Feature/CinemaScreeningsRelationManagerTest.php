<?php

namespace Tests\Feature;

use App\Filament\Resources\CinemaResource\Pages\EditCinema;
use App\Filament\Resources\CinemaResource\RelationManagers\ScreeningsRelationManager;
use App\Models\Cinema;
use App\Models\Language;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\ScreeningTime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vérifie la saisie/correction manuelle des horaires de séance depuis
 * l'admin (`CinemaResource` → onglet Séances) — complément du scraper
 * AlloCiné (`scrape:cinema`) pour les salles non couvertes ou les
 * corrections ponctuelles, voir TECHNICAL_DOCUMENTATION.md §13.
 */
class CinemaScreeningsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_cinema_edit_page_renders_with_screenings_relation_manager(): void
    {
        // Le relation manager est chargé en lazy via Livewire (pas de texte
        // du panneau dans le HTML de la réponse initiale) — assertOk() garde
        // déjà le rôle de garde-fou de AdminPanelSmokeTest ; le test suivant
        // vérifie le comportement réel du relation manager en le montant
        // directement via Livewire::test().
        $cinema = Cinema::create(['name' => 'Le Castelia', 'slug' => 'le-castelia', 'is_active' => true]);

        $this->actingAs($this->authenticatedAdmin())
            ->get("/admin/cinemas/{$cinema->id}/edit")
            ->assertOk();
    }

    public function test_admin_can_create_a_screening_with_nested_times_via_relation_manager(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        $cinema = Cinema::create(['name' => 'Le Castelia', 'slug' => 'le-castelia', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Un film indépendant', 'slug' => 'un-film-independant']);
        $language = Language::create(['legacy_id' => 2, 'name' => 'VF']);

        Livewire::test(ScreeningsRelationManager::class, [
            'ownerRecord' => $cinema,
            'pageClass' => EditCinema::class,
        ])
            ->mountTableAction('create')
            ->setTableActionData([
                'movie_id' => $movie->id,
                'language_id' => $language->id,
                'start_date' => '2026-08-19',
                'end_date' => '2026-08-25',
                'times' => [
                    ['weekday' => 3, 'time' => '20:30', 'booking_url' => 'https://booking.example.test/seance'],
                ],
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $screening = Screening::where('cinema_id', $cinema->id)->where('movie_id', $movie->id)->first();
        $this->assertNotNull($screening, 'La séance saisie manuellement aurait dû être créée.');
        $this->assertSame($language->id, $screening->language_id);

        $time = ScreeningTime::where('screening_id', $screening->id)->first();
        $this->assertNotNull($time);
        $this->assertSame(3, $time->weekday);
        $this->assertSame('20:30', substr($time->time, 0, 5));
        $this->assertSame('https://booking.example.test/seance', $time->booking_url);
    }
}
