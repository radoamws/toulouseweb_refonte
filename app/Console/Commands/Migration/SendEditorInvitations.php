<?php

namespace App\Console\Commands\Migration;

use App\Models\Editor;
use App\Services\Migration\MigrationLog;
use App\Support\SubmitterNotifier;
use Illuminate\Console\Command;

/**
 * Comptes éditeur, point 5 (demande client, 17/11/2026) : *"préparer un
 * newsletter avec tous les utilisateurs pour qu'on leur envoi leur lien
 * respectif pour s'inscrire"*. Précision de vocabulaire volontaire : il ne
 * s'agit PAS de la fonctionnalité "Newsletter" existante de l'admin
 * (App\Models\Newsletter / NewsletterSender), qui envoie un contenu
 * IDENTIQUE à toute la liste d'abonnés — ici, chaque destinataire reçoit un
 * lien PERSONNEL (`invitation_token`), ce qui exige un envoi individuel. Même
 * mécanique que `App\Support\SubmitterNotifier`, déjà utilisé pour les
 * confirmations de dépôt — pas de nouvelle classe mail.
 *
 * N'envoie qu'aux comptes PAS ENCORE inscrits (`password` NULL) — un éditeur
 * qui a déjà choisi son mot de passe n'a plus besoin (ni intérêt sécurité)
 * à recevoir un lien d'inscription. Dry-run par défaut (`--send`), même
 * convention que les autres commandes `migrate:*`/`espace-client:*`.
 */
class SendEditorInvitations extends Command
{
    protected $signature = 'editeurs:envoyer-invitations {--send : Envoie réellement les emails (sinon, dry-run : affiche seulement l\'effectif)}';

    protected $description = "Envoie à chaque compte éditeur pas encore inscrit son lien personnel de création de mot de passe";

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $log = new MigrationLog('editeurs-envoi-invitations');

        $editors = Editor::whereNull('password')->whereNotNull('invitation_token')->get();

        $this->info("Comptes éditeur à inviter : {$editors->count()}");

        if (! $send) {
            $this->warn('Dry-run — relancer avec --send pour envoyer réellement les emails.');

            return self::SUCCESS;
        }

        foreach ($editors as $editor) {
            SubmitterNotifier::send(
                $editor->email,
                'Gérez vos publications ToulouseWeb en ligne',
                [],
                route('editeur.inscription', $editor->invitation_token),
                'Créer mon espace',
            );
            $log->updated("Invitation envoyée à Editor #{$editor->id} ({$editor->email})");
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
