<?php

namespace App\Console\Commands\Migration;

use App\Models\Classified;
use App\Models\Editor;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Comptes éditeur, point 1 (demande client, 17/11/2026, "Grande
 * modification") : *"migrer tous les mails/contacts ayant fait de la
 * publication depuis la refonte du site vers la liste des utilisateurs avec
 * un rôle éditeur"*. Remplace le modèle "un lien par fiche" (étape 4 de la
 * demande précédente, `espace-client:backfill-historique`) par "un compte
 * par personne" : un seul `Editor` par email distinct, rattaché à TOUTES ses
 * publications des 4 entités (voir TECHNICAL_DOCUMENTATION.md).
 *
 * N'envoie AUCUN email — volontairement séparé de l'envoi des invitations
 * (demande client, point 5 : *"préparer un newsletter... pour qu'on leur
 * envoi leur lien"*), voir `editeurs:envoyer-invitations`.
 *
 * Dry-run par défaut (`--commit` pour écrire en base), même convention que
 * les autres commandes `migrate:*`/`content:*`/`espace-client:*` de ce
 * projet. Idempotent : un email déjà migré (compte `Editor` existant) n'est
 * jamais dupliqué (`firstOrCreate`), et une publication déjà rattachée à un
 * éditeur n'est jamais retraitée.
 */
class MigrateHistoricalSubmittersToEditors extends Command
{
    protected $signature = 'editeurs:migrer-historique {--commit : Écrit réellement en base (sinon, dry-run : affiche seulement les effectifs)}';

    protected $description = "Crée un compte éditeur par déposant historique distinct et y rattache toutes ses publications";

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $log = new MigrationLog('editeurs-migration-historique');

        $classifieds = Classified::whereNull('editor_id')->whereNotNull('contact_email')->get();
        $events = Event::whereNull('editor_id')->whereNotNull('submitter_email')->get();
        $news = News::whereNull('editor_id')->whereNotNull('submitter_email')->get();
        $listings = Listing::whereNull('editor_id')->whereNotNull('submitter_email')->get();

        $emails = collect()
            ->merge($classifieds->pluck('contact_email'))
            ->merge($events->pluck('submitter_email'))
            ->merge($news->pluck('submitter_email'))
            ->merge($listings->pluck('submitter_email'))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        $this->info("Emails distincts concernés : {$emails->count()}");
        $this->info("Annonces à rattacher : {$classifieds->count()}");
        $this->info("Événements à rattacher : {$events->count()}");
        $this->info("Actualités à rattacher : {$news->count()}");
        $this->info("Fiches annuaire à rattacher : {$listings->count()}");

        if (! $commit) {
            $this->warn('Dry-run — relancer avec --commit pour créer les comptes et rattacher les publications.');

            return self::SUCCESS;
        }

        $editorsByEmail = $emails->mapWithKeys(function (string $email) use ($log) {
            $editor = Editor::firstOrCreate(
                ['email' => $email],
                ['invitation_token' => Str::random(48)],
            );

            if ($editor->wasRecentlyCreated) {
                $log->created("Editor #{$editor->id} créé pour {$email}");
            } else {
                $log->skipped("Editor #{$editor->id} déjà existant pour {$email} — réutilisé");
            }

            return [$email => $editor];
        });

        foreach ($classifieds as $classified) {
            $editor = $editorsByEmail[mb_strtolower(trim($classified->contact_email))];
            $classified->update(['editor_id' => $editor->id]);
            $log->updated("Classified #{$classified->id} \"{$classified->title}\" -> Editor #{$editor->id}");
        }

        foreach ($events as $event) {
            $editor = $editorsByEmail[mb_strtolower(trim($event->submitter_email))];
            $event->update(['editor_id' => $editor->id]);
            $log->updated("Event #{$event->id} \"{$event->title}\" -> Editor #{$editor->id}");
        }

        foreach ($news as $article) {
            $editor = $editorsByEmail[mb_strtolower(trim($article->submitter_email))];
            $article->update(['editor_id' => $editor->id]);
            $log->updated("News #{$article->id} \"{$article->title}\" -> Editor #{$editor->id}");
        }

        foreach ($listings as $listing) {
            $editor = $editorsByEmail[mb_strtolower(trim($listing->submitter_email))];
            $listing->update(['editor_id' => $editor->id]);
            $log->updated("Listing #{$listing->id} \"{$listing->title}\" -> Editor #{$editor->id}");
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
