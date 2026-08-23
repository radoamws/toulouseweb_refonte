<?php

namespace App\Models\Concerns;

use App\Models\SeoMeta;
use App\Services\Seo\SeoResolverService;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Donne à un modèle une entrée SEO personnalisable avec repli automatique
 * (valeur perso -> sinon génération intelligente), voir
 * App\Services\Seo\SeoResolverService et TECHNICAL_DOCUMENTATION.md §9/§13.
 */
trait HasSeoMeta
{
    public function seoMeta(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /**
     * Résout le titre/description/canonical/OG/structured data effectifs
     * pour cette entité : valeur admin si renseignée, sinon générée.
     */
    public function resolveSeo(): array
    {
        return app(SeoResolverService::class)->resolve($this);
    }
}
