<?php

namespace App\Contracts;

/**
 * Implémenté par tout modèle dont une sauvegarde/suppression doit purger des
 * pages publiques du cache Cloudflare (voir App\Observers\CloudflarePurgeObserver
 * et App\Services\Cache\CloudflareCachePurger, TECHNICAL_DOCUMENTATION.md §17).
 */
interface HasCloudflarePurgeUrls
{
    /** @return array<int, string> URLs absolues à purger pour l'état ACTUEL du modèle. */
    public function cloudflarePurgeUrls(): array;
}
