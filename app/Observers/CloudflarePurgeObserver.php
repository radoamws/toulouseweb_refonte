<?php

namespace App\Observers;

use App\Contracts\HasCloudflarePurgeUrls;
use App\Services\Cache\CloudflareCachePurger;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer générique (un seul, partagé par tous les modèles concernés — voir
 * son enregistrement dans AppServiceProvider::boot()) plutôt qu'un observer
 * par modèle : chaque modèle expose juste ses URLs via
 * App\Contracts\HasCloudflarePurgeUrls, cet observer ne fait qu'accumuler.
 *
 * Ne purge PAS immédiatement (pas d'appel HTTP ici) : accumule dans
 * CloudflareCachePurger::queue(), qui n'envoie réellement les requêtes
 * qu'une seule fois à la fin du processus (`app()->terminating()`, voir
 * AppServiceProvider). Sans ce découplage, un scraping en lot (`scrape:cinema`/
 * `scrape:events`, des centaines de `Movie`/`Event` sauvegardés dans une
 * seule exécution) déclencherait autant d'appels HTTP à l'API Cloudflare
 * qu'il y a de lignes touchées — largement au-dessus des quotas de purge
 * (de l'ordre de quelques centaines à quelques milliers d'appels par jour
 * selon le plan Cloudflare). Le découplage ramène ça à une poignée d'appels
 * groupés (par lots de 30 URLs) par processus, quel que soit le nombre de
 * modèles sauvegardés à l'intérieur.
 */
class CloudflarePurgeObserver
{
    public function saved(Model $model): void
    {
        if (! $model instanceof HasCloudflarePurgeUrls) {
            return;
        }

        $purger = app(CloudflareCachePurger::class);
        $purger->queue($model->cloudflarePurgeUrls());

        // Un changement de slug rend l'ANCIENNE URL périmée (elle affichera
        // un 404 dès maintenant côté appli) mais Cloudflare continuerait à
        // servir la version en cache jusqu'à expiration du TTL sans ce
        // second passage.
        //
        // ⚠️ `getOriginal('slug')` NE CONVIENT PAS ici : au moment où l'event
        // "saved" se déclenche, `performUpdate()` a déjà appelé `syncChanges()`
        // — qui alimente `$previous` (voir `getPrevious()`) — mais
        // `syncOriginal()` (qui écraserait `$original` avec la NOUVELLE valeur)
        // n'est appelé qu'ensuite dans `finishSave()`. Sur le papier
        // `getOriginal()` semble donc encore correct ici, mais en pratique
        // (vérifié en tinker) il retourne déjà la valeur À JOUR — `getPrevious()`
        // est l'accesseur prévu par Eloquent pour exactement ce besoin.
        // `wasChanged('slug')` est déjà `false` sur une simple création (aucun
        // appel à `syncChanges()` dans `performInsert()`) : pas besoin d'un
        // garde `wasRecentlyCreated` en plus.
        $previousSlug = $model->getPrevious()['slug'] ?? null;
        if ($model->wasChanged('slug') && $previousSlug) {
            $currentSlug = $model->slug;
            $model->slug = $previousSlug;
            $purger->queue($model->cloudflarePurgeUrls());
            $model->slug = $currentSlug;
        }
    }

    public function deleted(Model $model): void
    {
        $this->queueCurrent($model);
    }

    public function restored(Model $model): void
    {
        $this->queueCurrent($model);
    }

    protected function queueCurrent(Model $model): void
    {
        if (! $model instanceof HasCloudflarePurgeUrls) {
            return;
        }

        app(CloudflareCachePurger::class)->queue($model->cloudflarePurgeUrls());
    }
}
