<?php

namespace Tests\Feature;

use App\Filament\Resources\AmenityResource\Pages\CreateAmenity;
use App\Filament\Resources\AmenityResource\Pages\EditAmenity;
use App\Filament\Resources\AmenityResource\Pages\ListAmenities;
use App\Models\Amenity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Deux demandes client sur le comportement de sauvegarde admin, appliquées
 * à TOUTES les pages Create/Edit via App\Filament\Concerns\RedirectsToIndexAfterSave
 * (voir son docblock) :
 * 1. Retour sur le listing après sauvegarde (ni Create ni Edit ne le
 *    faisaient par défaut côté Filament — vérifié dans le code source
 *    vendor avant de conclure que c'était bien manquant).
 * 2. Indicateur de chargement pendant la sauvegarde — déjà natif à Filament
 *    (`wire:loading` sur le bouton d'action Livewire), rien à coder.
 *
 * AmenityResource sert de cas représentatif (le trait étant partagé, un
 * test par ressource serait redondant — voir aussi AdminPanelSmokeTest qui
 * vérifie que les 20 ressources continuent de fonctionner avec le trait).
 */
class AdminSaveBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_create_redirects_to_index_after_save(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        Livewire::test(CreateAmenity::class)
            ->fillForm(['name' => 'Wifi gratuit'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ListAmenities::getUrl());

        $this->assertDatabaseHas('amenities', ['name' => 'Wifi gratuit']);
    }

    public function test_edit_redirects_to_index_after_save(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $amenity = Amenity::create(['name' => 'Parking']);

        Livewire::test(EditAmenity::class, ['record' => $amenity->getRouteKey()])
            ->fillForm(['name' => 'Parking gratuit'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ListAmenities::getUrl());

        $this->assertSame('Parking gratuit', $amenity->fresh()->name);
    }

    /**
     * Gestion des icônes en file upload/glisser-déposer (demande client,
     * "comme dans la gestion des sliders") — voir docblock d'AmenityResource
     * (et Category/EventCategoryResource, même changement).
     */
    public function test_amenity_icon_accepts_a_file_upload(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        $file = UploadedFile::fake()->image('wifi.png');

        Livewire::test(CreateAmenity::class)
            ->fillForm(['name' => 'Wifi', 'icon' => $file])
            ->call('create')
            ->assertHasNoFormErrors();

        $amenity = Amenity::where('name', 'Wifi')->firstOrFail();
        $this->assertNotNull($amenity->icon);
        $this->assertStringStartsWith('amenities/', $amenity->icon);
        $this->assertNotNull($amenity->icon_url);
    }
}
