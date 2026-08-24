<?php

namespace App\Models;

use App\Models\Concerns\ResolvesImageUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Réglages globaux du site — une seule ligne (singleton), voir migration
 * `create_site_settings_table` et `App\Filament\Pages\SiteSettings`.
 * Alimente le JSON-LD Organization et les meta OG par défaut
 * (components/layouts/app.blade.php) ainsi que le pied de page.
 */
class SiteSetting extends Model
{
    use ResolvesImageUrl;

    protected $fillable = [
        'site_name', 'tagline', 'description', 'logo', 'default_og_image',
        'email', 'phone', 'address',
        'facebook_url', 'instagram_url', 'twitter_url', 'linkedin_url', 'youtube_url',
    ];

    /** Retourne (et crée si besoin) l'unique ligne de réglages, avec des valeurs par défaut cohérentes avec l'existant. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'site_name' => 'ToulouseWeb',
            'description' => "Portail local de Toulouse et sa région : actualités, agenda, cinéma, annuaire, annonces.",
        ]);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->logo));
    }

    protected function defaultOgImageUrl(): Attribute
    {
        return Attribute::get(fn () => static::resolveImageUrl($this->default_og_image));
    }

    /** Liens sociaux non vides, pour le JSON-LD Organization (`sameAs`) et l'affichage pied de page. */
    public function socialLinks(): array
    {
        return array_values(array_filter([
            $this->facebook_url,
            $this->instagram_url,
            $this->twitter_url,
            $this->linkedin_url,
            $this->youtube_url,
        ]));
    }
}
