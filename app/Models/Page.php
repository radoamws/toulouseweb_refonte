<?php

namespace App\Models;

use App\Contracts\HasCloudflarePurgeUrls;
use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** Page statique administrable (accueil, contact, pages vitrine...). */
class Page extends Model implements HasCloudflarePurgeUrls
{
    use HasSlug, HasSeoMeta;

    protected $fillable = ['key', 'title', 'slug', 'content', 'template', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('title')->saveSlugsTo('slug')->doNotGenerateSlugsOnUpdate();
    }

    /**
     * Seules les 3 clés effectivement lues par un contrôleur public sont
     * mappées (`HomeController`, `ContactController`, `ListingController` —
     * voir TECHNICAL_DOCUMENTATION.md §17) : les nombreuses pages
     * `seo-menu-{slug}` créées par `migrate:seo` (métadonnées SEO migrées du
     * legacy, une par ligne `t_seo`) ne sont actuellement rattachées à
     * aucune route rendue — les purger n'aurait pas de page cible réelle.
     */
    public function cloudflarePurgeUrls(): array
    {
        $urlsByKey = [
            'home' => route('home'),
            'contact' => route('contact.show'),
            'seo-menu-annuaire' => route('annuaire.index'),
        ];

        return array_filter([$urlsByKey[$this->key] ?? null]);
    }
}
