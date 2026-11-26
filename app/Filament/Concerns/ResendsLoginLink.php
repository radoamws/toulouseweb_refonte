<?php

namespace App\Filament\Concerns;

use App\Models\Editor;
use App\Support\SubmitterNotifier;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Bouton "Renvoyer un lien de connexion" (retour client, 26/11/2026 —
 * Innotech Diagnostics, un déposant ayant égaré ses identifiants), partagé
 * par les 4 ressources de dépôt public (Classified/Event/Listing/News),
 * toutes rattachées à App\Models\Editor de la même façon (`editor_id`,
 * `submitter_email`) — voir
 * App\Http\Controllers\Controller::notifySubmitterToManageTheirAccount()
 * pour l'équivalent envoyé automatiquement à la création d'un dépôt.
 *
 * Compte éditeur déjà inscrit (mot de passe choisi) -> lien de
 * RÉINITIALISATION du mot de passe (même mécanique que
 * EditorAuthController::sendResetLink()) ; compte jamais inscrit, ou
 * fiche jamais rattachée à un compte (anciens dépôts, voir
 * App\Console\Commands\Migration\MigrateHistoricalSubmittersToEditors) ->
 * lien d'INSCRIPTION, même mécanique que
 * App\Console\Commands\Migration\SendEditorInvitations.
 */
trait ResendsLoginLink
{
    protected static function resendLoginLinkAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resendLoginLink')
            ->label('Renvoyer un lien de connexion')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->visible(fn (Model $record) => filled($record->submitter_email))
            ->requiresConfirmation()
            ->modalHeading('Renvoyer un lien de connexion')
            ->modalDescription(fn (Model $record) => "Envoyer un email permettant de se reconnecter à {$record->submitter_email} ?")
            ->action(function (Model $record) {
                $editor = $record->editor ?? Editor::findOrInviteByEmail($record->submitter_email);

                if (! $record->editor_id) {
                    $record->update(['editor_id' => $editor->id]);
                }

                if ($editor->hasRegistered()) {
                    Password::broker('editors')->sendResetLink(['email' => $editor->email]);
                } else {
                    if (! $editor->invitation_token) {
                        $editor->update(['invitation_token' => Str::random(48)]);
                    }

                    SubmitterNotifier::send(
                        $editor->email,
                        'Gérez vos publications ToulouseWeb en ligne',
                        [],
                        route('editeur.inscription', $editor->invitation_token),
                        'Créer mon espace',
                    );
                }

                Notification::make()->title("Lien envoyé à {$editor->email}")->success()->send();
            });
    }
}
