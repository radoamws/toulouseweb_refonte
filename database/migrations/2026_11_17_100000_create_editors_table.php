<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes "éditeur" (demande client, 17/11/2026) : permettent aux déposants
 * publics (annonces/agenda/actualités/annuaire) de se connecter pour gérer
 * leurs propres publications, sans jamais pouvoir accéder à l'administration
 * Filament — voir TECHNICAL_DOCUMENTATION.md.
 *
 * ⚠️ Décision d'architecture volontaire : table ET guard Laravel SÉPARÉS de
 * `users` (voir config/auth.php, guard `editor`), PAS un rôle Spatie de plus
 * sur `App\Models\User`. Raison trouvée dans le code existant :
 * `User::canAccessPanel()` autorise l'accès à l'admin Filament à TOUT compte
 * ayant au moins un rôle Spatie (volontairement générique, voir son propre
 * docblock) — un rôle "éditeur" sur `users` aurait donc, par construction,
 * donné accès à l'admin à des centaines de déposants publics. La séparation
 * complète évite ce piège une fois pour toutes, plutôt que d'ajouter une
 * exception fragile dans `canAccessPanel()` qu'un futur rôle pourrait
 * recasser. Bénéfice secondaire : la liste "Utilisateurs" de l'admin
 * (UserResource, réservée au staff) ne se retrouve pas noyée sous les
 * comptes publics.
 *
 * `password` nullable : un compte est créé "en attente" (par la commande de
 * migration historique, voir §étape B) via `invitation_token`, SANS mot de
 * passe — tant qu'il est NULL, le compte ne peut pas se connecter (seul le
 * lien d'invitation y donne accès, voir EditorAuthController::invitation()).
 * Une fois le mot de passe choisi, `invitation_token` est mis à NULL (lien
 * à usage unique, même principe que pour la ré-émission d'un lien oublié).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('invitation_token')->nullable()->unique();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editors');
    }
};
