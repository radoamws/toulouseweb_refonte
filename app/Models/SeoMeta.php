<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Métadonnées SEO personnalisées d'une entité (fallback géré par
 * App\Services\Seo\SeoResolverService — jamais de pré-remplissage en masse
 * comme le t_seo_entity legacy, voir TECHNICAL_DOCUMENTATION.md §5/§9).
 */
class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $fillable = [
        'seoable_type', 'seoable_id', 'title', 'description', 'canonical_url',
        'robots', 'og_image', 'structured_data',
    ];

    protected $casts = ['structured_data' => 'array'];

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
