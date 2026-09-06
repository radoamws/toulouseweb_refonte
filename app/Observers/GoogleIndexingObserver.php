<?php

namespace App\Observers;

use App\Contracts\HasGoogleIndexingUrl;
use App\Services\Seo\GoogleIndexingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer générique (un seul, partagé — voir son enregistrement dans
 * AppServiceProvider::boot()), même principe que
 * App\Observers\CloudflarePurgeObserver mais pour la demande d'indexation
 * Google (voir TECHNICAL_DOCUMENTATION.md §20) : chaque modèle expose son
 * URL canonique + sa visibilité via App\Contracts\HasGoogleIndexingUrl, cet
 * observer se contente d'accumuler dans GoogleIndexingService.
 *
 * `URL_UPDATED` quand le contenu est ACTUELLEMENT visible publiquement,
 * `URL_DELETED` sinon (brouillon, dépublié, expiré, ou réellement supprimé)
 * — y compris pour un contenu qui n'a jamais été publié (une notification
 * "supprimé" pour une URL que Google n'a de toute façon jamais indexée est
 * sans effet réel, plus simple que de suivre l'état de visibilité PRÉCÉDENT
 * pour ne l'envoyer qu'aux transitions réelles publié -> dépublié).
 */
class GoogleIndexingObserver
{
    public function saved(Model $model): void
    {
        if (! $model instanceof HasGoogleIndexingUrl) {
            return;
        }

        app(GoogleIndexingService::class)->queue(
            $model->publicUrl(),
            $model->isPubliclyVisible() ? 'URL_UPDATED' : 'URL_DELETED'
        );
    }

    public function deleted(Model $model): void
    {
        if (! $model instanceof HasGoogleIndexingUrl) {
            return;
        }

        app(GoogleIndexingService::class)->queue($model->publicUrl(), 'URL_DELETED');
    }
}
