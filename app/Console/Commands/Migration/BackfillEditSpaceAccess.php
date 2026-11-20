<?php

namespace App\Console\Commands\Migration;

use App\Models\Classified;
use App\Models\Event;
use App\Models\News;
use App\Support\SubmitterNotifier;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Espace client, étape 4 (demande client, 16/11/2026) — campagne d'emailing
 * aux déposants dont le dépôt existe déjà mais n'a jamais reçu de lien
 * d'espace client (créé AVANT le déploiement des étapes 1/2/3, voir
 * TECHNICAL_DOCUMENTATION.md §74/§75 — `SubmitterNotifier` n'envoie le lien
 * qu'à la création, jamais rétroactivement).
 *
 * Dry-run PAR DÉFAUT (`--send` requis pour écrire en base et envoyer les
 * emails) — même convention que les autres commandes `migrate:*`/`content:*`
 * de ce projet (voir DedupeDuplicateEvents). Volontairement un geste MANUEL
 * déclenché depuis l'admin/SSH après revue des effectifs affichés, jamais
 * auto-exécuté par le WebCron ni une migration : contrairement au digest
 * quotidien (§71), il s'agit ici d'un envoi UNIQUE à des centaines de
 * destinataires existants, pas d'une notification au fil de l'eau — un
 * mauvais réglage ici n'est pas rattrapable (on ne "redésabonne" pas un
 * email déjà envoyé). Idempotent : relancer sans `--send` après un premier
 * passage avec `--send` ne retrouve plus aucune ligne à traiter (edit_token
 * déjà renseigné).
 *
 * - `Classified` : seule entité sans `submitter_email` historique — utilise
 *   `contact_email` comme identité du déposant (c'est l'email qu'il a
 *   lui-même saisi à la soumission, voir ClassifiedController::store()) et
 *   le copie dans `submitter_email` au passage, pour les mêmes raisons
 *   d'immutabilité que pour les nouveaux dépôts (§74).
 * - `Event`/`News` : `submitter_email` déjà fiable (collecté depuis le
 *   12/11/2026) — seul `edit_token` manque pour les dépôts créés entre
 *   cette date et le déploiement de l'espace client.
 * - `Listing` : VOLONTAIREMENT EXCLU — aucun email de déposant fiable pour
 *   les ~2978 fiches historiques migrées du legacy (champ `email` métier,
 *   pas forcément celui du déposant, voir docblock de ListingController) ;
 *   les fiches créées depuis le déploiement de l'étape 3 ont déjà leur
 *   `edit_token` généré à la création, rien à rattraper ici pour elles non
 *   plus.
 */
class BackfillEditSpaceAccess extends Command
{
    protected $signature = 'espace-client:backfill-historique {--send : Écrit en base et envoie réellement les emails (sinon, dry-run : affiche seulement les effectifs)}';

    protected $description = "Génère un lien d'espace client pour les dépôts existants qui n'en ont jamais reçu, et le leur envoie par email";

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $log = new MigrationLog('espace-client-backfill-historique');

        $classifieds = Classified::whereNull('submitter_email')->whereNotNull('contact_email')->whereNull('edit_token')->get();
        $events = Event::whereNotNull('submitter_email')->whereNull('edit_token')->get();
        $news = News::whereNotNull('submitter_email')->whereNull('edit_token')->get();

        $this->info("Annonces éligibles : {$classifieds->count()}");
        $this->info("Événements éligibles : {$events->count()}");
        $this->info("Actualités éligibles : {$news->count()}");
        $this->comment('Fiches annuaire : volontairement exclues (aucun email de déposant fiable pour les fiches historiques, voir docblock de la commande).');

        if (! $send) {
            $this->warn("Dry-run — relancer avec --send pour générer les liens et envoyer les emails.");

            return self::SUCCESS;
        }

        foreach ($classifieds as $classified) {
            $this->grantAndNotify($classified, $classified->contact_email, [
                'submitter_email' => $classified->contact_email,
                'edit_token' => Str::random(48),
            ], 'annonces.editSpace', 'Gérer mon annonce', 'Votre annonce ToulouseWeb peut désormais être modifiée en ligne', $log);
        }

        foreach ($events as $event) {
            $this->grantAndNotify($event, $event->submitter_email, [
                'edit_token' => Str::random(48),
            ], 'agenda.editSpace', 'Gérer mon événement', 'Votre événement ToulouseWeb peut désormais être modifié en ligne', $log);
        }

        foreach ($news as $article) {
            $this->grantAndNotify($article, $article->submitter_email, [
                'edit_token' => Str::random(48),
            ], 'actualites.editSpace', 'Gérer ma proposition', 'Votre actualité ToulouseWeb peut désormais être modifiée en ligne', $log);
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }

    protected function grantAndNotify(Model $record, string $email, array $attributes, string $routeName, string $actionText, string $heading, MigrationLog $log): void
    {
        $record->update($attributes);

        SubmitterNotifier::send(
            $email,
            $heading,
            ['Titre' => $record->title],
            route($routeName, $record->edit_token),
            $actionText,
        );

        $log->updated(sprintf('%s #%d "%s" -> lien envoyé à %s', $record::class, $record->id, $record->title, $email));
    }
}
