<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Gestion des comptes admin et de leurs rôles (brief §12/§18) — jusqu'ici
 * seulement possible via RolesAndAdminSeeder/tinker. Voir UserResource,
 * RoleResource et User::canAccessPanel() (générique : n'importe quel rôle
 * donne accès, pas une liste de noms figée).
 */
class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_user_without_any_role_cannot_access_panel(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessPanel(app(\Filament\Panel::class)));
    }

    public function test_user_with_a_custom_role_can_access_panel(): void
    {
        // Générique : un rôle qui n'est ni super_admin/admin/editor/moderator
        // (la liste en dur d'avant ce chantier) doit quand même donner accès.
        Role::findOrCreate('support');
        $user = User::factory()->create();
        $user->assignRole('support');

        $this->assertTrue($user->canAccessPanel(app(\Filament\Panel::class)));
    }

    public function test_admin_can_create_a_user_with_a_role_and_password_is_hashed(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $editorRole = Role::findOrCreate('editor');

        Livewire::test(\App\Filament\Resources\UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Nouvel Admin',
                'email' => 'nouvel-admin@example.test',
                'password' => 'un-mot-de-passe-solide',
                // Select lié par relationship() : la valeur attendue est la
                // clé du modèle (id), pas le nom affiché — piège classique.
                'roles' => [$editorRole->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'nouvel-admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('editor'));
        $this->assertTrue(Hash::check('un-mot-de-passe-solide', $user->password));
    }

    public function test_creating_a_user_without_any_role_is_rejected(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        Livewire::test(\App\Filament\Resources\UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Sans Rôle',
                'email' => 'sans-role@example.test',
                'password' => 'un-mot-de-passe-solide',
                'roles' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['roles']);

        $this->assertDatabaseMissing('users', ['email' => 'sans-role@example.test']);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->authenticatedAdmin();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('delete', $admin);
    }

    public function test_admin_can_create_a_new_role(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        Livewire::test(\App\Filament\Resources\RoleResource\Pages\CreateRole::class)
            ->fillForm(['name' => 'support'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('roles', ['name' => 'support']);
    }
}
