<?php

namespace App\Models;

use App\Support\SubmitterNotifier;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Compte public permettant à un déposant (annonces/agenda/actualités/
 * annuaire) de gérer ses propres publications — voir
 * TECHNICAL_DOCUMENTATION.md et le docblock de la migration
 * `create_editors_table` pour la décision de séparation complète d'avec
 * `App\Models\User` (admin Filament).
 *
 * N'implémente PAS `Filament\Models\Contracts\FilamentUser` — un compte
 * éditeur ne doit jamais pouvoir se connecter à `/admin`, quel que soit son
 * état (même avec `password` renseigné).
 */
class Editor extends Authenticatable implements CanResetPasswordContract
{
    use Notifiable, CanResetPassword;

    protected $fillable = [
        'name', 'email', 'password', 'invitation_token',
    ];

    protected $hidden = [
        'password', 'remember_token', 'invitation_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /** Un compte créé par la migration historique (étape B) tant qu'il n'a pas encore choisi de mot de passe. */
    public function hasRegistered(): bool
    {
        return $this->password !== null;
    }

    /**
     * Trouve ou crée le compte éditeur correspondant à un email de dépôt
     * anonyme (demande client, 17/11/2026 : *"après qu'un nouveau client a
     * publié une entité... [lui envoyer] le lien pour créer son compte...
     * qu'il administre toutes ses publications"*) — voir les 4
     * `*Controller::store()` qui l'appellent. Même normalisation (casse)
     * que `MigrateHistoricalSubmittersToEditors`, pour ne jamais créer un
     * second compte pour la même adresse à cause d'une variation de casse.
     */
    public static function findOrInviteByEmail(string $email): self
    {
        return static::firstOrCreate(
            ['email' => mb_strtolower(trim($email))],
            ['invitation_token' => Str::random(48)],
        );
    }

    /**
     * Remplace la notification native Laravel (`Illuminate\Auth\Notifications\ResetPassword`,
     * jamais utilisée ailleurs dans ce projet — voir App\Mail\SubmitterNotification)
     * par le même Mailable générique que le reste des emails déposant, pour
     * un rendu cohérent. `Password::broker('editors')` reste responsable de
     * la génération/validation du jeton (sécurité éprouvée de Laravel) —
     * seul le canal d'envoi change.
     */
    public function sendPasswordResetNotification($token): void
    {
        SubmitterNotifier::send(
            $this->email,
            'Réinitialisation de votre mot de passe ToulouseWeb',
            [],
            route('editeur.mot-de-passe.reinitialiser', ['token' => $token, 'email' => $this->email]),
            'Choisir un nouveau mot de passe',
        );
    }

    public function classifieds(): HasMany
    {
        return $this->hasMany(Classified::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function news(): HasMany
    {
        return $this->hasMany(News::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
