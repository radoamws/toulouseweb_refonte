<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Rôles d'administration (brief §12) et compte super-admin initial pour
 * accéder au panneau Filament (/admin). À exécuter une seule fois par
 * environnement : `php artisan db:seed --class=RolesAndAdminSeeder`.
 */
class RolesAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        collect(['super_admin', 'admin', 'editor', 'moderator'])
            ->each(fn (string $role) => Role::findOrCreate($role));

        $admin = User::firstOrCreate(
            ['email' => 'rado.rakotoarivelo@amws.space'],
            [
                'name' => 'Rado Rakotoarivelo',
                'password' => Hash::make('ChangeMe!ToulouseWeb2026'),
                'email_verified_at' => now(),
            ]
        );

        $admin->syncRoles(['super_admin']);
    }
}
