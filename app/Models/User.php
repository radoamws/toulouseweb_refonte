<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Seuls les comptes disposant d'au moins un rôle (Spatie permission)
     * peuvent accéder à l'administration — voir brief §12/§18.
     *
     * Générique (n'importe quel rôle) plutôt qu'une liste figée de noms :
     * depuis que les rôles sont administrables (`RoleResource`), une liste
     * en dur aurait été un piège — créer un nouveau rôle depuis l'admin et
     * l'assigner à un utilisateur n'aurait silencieusement donné accès à
     * rien tant que cette liste n'aurait pas aussi été mise à jour en code.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->exists && $this->roles()->exists();
    }
}
