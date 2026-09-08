<?php

namespace App\Observers;

use App\Jobs\RegenerateSitemap;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer générique (un seul, partagé — voir son enregistrement dans
 * AppServiceProvider::boot()), déclenchant `App\Jobs\RegenerateSitemap`
 * après tout ajout/modif/suppression d'un des modèles réellement présents
 * dans `sitemap.xml` (voir `GenerateSitemap` pour la liste exacte : mêmes
 * modèles que `Category`/`Listing`/`EventCategory`/`Event`/`Cinema`/
 * `Movie`/`News`/`Classified`). Pas d'interface/contrat nécessaire ici
 * (contrairement à `CloudflarePurgeObserver`/`GoogleIndexingObserver`) :
 * TOUS les modèles observés déclenchent la même action indifférenciée
 * (une régénération complète, jamais incrémentale).
 *
 * Le job lui-même gère la déduplication (`ShouldBeUnique`) — inutile
 * d'accumuler un état ici comme le font les deux observers ci-dessus.
 */
class RegeneratesSitemapObserver
{
    public function saved(Model $model): void
    {
        RegenerateSitemap::dispatch();
    }

    public function deleted(Model $model): void
    {
        RegenerateSitemap::dispatch();
    }

    public function restored(Model $model): void
    {
        RegenerateSitemap::dispatch();
    }
}
