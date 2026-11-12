<?php

namespace App\Observers;

use App\Models\Classified;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Model;

/**
 * Abonne automatiquement l'auteur d'un dépôt public (annuaire, annonce,
 * événement, actualité) à la newsletter dès que sa fiche est VALIDÉE
 * (passage à "published") — demande client, 12/11/2026 : "ceux qui ont
 * fait des dépositions d'annonces, annuaires, événements et actualités et
 * qui ont été validés à partir de cette refonte, les inclure
 * automatiquement dans les newsletters".
 *
 * ⚠️ "à partir de cette refonte" exclut tout contenu importé du legacy
 * (`legacy_id` renseigné) — ces fiches n'ont jamais été "déposées" par
 * quelqu'un sur le nouveau site, les y abonner serait hors sujet (les
 * anciens contacts newsletter eux-mêmes sont importés séparément, voir
 * App\Console\Commands\Migration\ImportLegacyNewsletterContacts).
 *
 * Pour Event spécifiquement : seuls les événements `source =
 * 'user_submitted'` comptent — ni les événements scrapés (plusieurs par
 * jour, aucune adresse email de toute façon) ni les événements importés du
 * legacy (`source = 'manual'`), voir docblock de EventController::store().
 *
 * Même détection "vient de passer à published" que
 * App\Observers\NewsletterDraftObserver (created() + updated() séparés) —
 * voir son docblock pour le piège `wasChanged()`/`wasRecentlyCreated` déjà
 * documenté là-bas, non reproduit ici.
 *
 * Utilise NewsletterSubscriber::subscribeEmail() (déjà utilisé par le
 * formulaire d'accueil et le formulaire de contact) : ne réinscrit jamais
 * de force quelqu'un qui s'était désabonné entre-temps, voir son docblock.
 */
class SubmitterSubscriptionObserver
{
    public function created(Model $model): void
    {
        $this->maybeSubscribe($model, $model->status === 'published');
    }

    public function updated(Model $model): void
    {
        $this->maybeSubscribe($model, $model->status === 'published' && $model->wasChanged('status'));
    }

    protected function maybeSubscribe(Model $model, bool $justPublished): void
    {
        if (! $justPublished || $model->legacy_id !== null) {
            return;
        }

        [$email, $source] = match (true) {
            $model instanceof Listing => [$model->email, 'listing_submission'],
            $model instanceof News => [$model->submitter_email, 'news_submission'],
            $model instanceof Classified => [$model->contact_email, 'classified_submission'],
            $model instanceof Event => $model->source === 'user_submitted'
                ? [$model->submitter_email, 'event_submission']
                : [null, null],
            default => [null, null],
        };

        if (! $email) {
            return;
        }

        NewsletterSubscriber::subscribeEmail($email, null, $source);
    }
}
