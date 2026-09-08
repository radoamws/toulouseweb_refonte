<?php

namespace App\Services\Seo;

use App\Models\SiteSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Résout les métadonnées SEO effectives d'une entité : valeur personnalisée
 * en admin (table seo_meta) si renseignée, sinon génération automatique
 * intelligente à partir des attributs du modèle. Remplace le mécanisme
 * legacy (t_seo_entity pré-rempli en masse sans fallback réel) — voir
 * TECHNICAL_DOCUMENTATION.md §5 et §9, et brief §13 ("SEO title personnalisé
 * -> sinon génération automatique").
 */
class SeoResolverService
{
    public function resolve(Model $model): array
    {
        /** @var \App\Models\SeoMeta|null $seo */
        $seo = method_exists($model, 'seoMeta') ? $model->seoMeta : null;

        $title = $seo?->title ?: $this->generateTitle($model);
        $description = $seo?->description ?: $this->generateDescription($model);

        // ⚠️ Bug réel trouvé et corrigé (08/09/2026, audit SEO final,
        // TECHNICAL_DOCUMENTATION.md §24) : troncature SANS `preserveWords`
        // ET sans marqueur de coupure (`$end` forcé à '') — coupait en plein
        // milieu d'un mot, sans aucune indication visuelle qu'il manque du
        // texte (constaté en direct sur la home : "...Commerces à" au lieu
        // de "...Commerces à Toulouse", "...la Vill" au lieu de "...la
        // Ville"). `preserveWords: true` recule la coupure au dernier mot
        // entier, `'…'` signale clairement une troncature.
        return [
            'title' => Str::limit($title, 60, '…', preserveWords: true),
            'description' => Str::limit($description ?? '', 160, '…', preserveWords: true),
            'canonical_url' => $seo?->canonical_url ?: $this->generateCanonical($model),
            'robots' => $seo?->robots ?: 'index,follow',
            'og_image' => $seo?->og_image ?: $this->generateImage($model),
            'structured_data' => $seo?->structured_data,
        ];
    }

    protected function generateTitle(Model $model): string
    {
        $siteName = SiteSetting::current()->site_name;
        $name = $this->firstAttribute($model, ['title', 'name']) ?? $siteName;

        return "{$name} — Toulouse | {$siteName}";
    }

    protected function generateDescription(Model $model): ?string
    {
        $text = $this->firstAttribute($model, [
            'short_description', 'excerpt', 'description', 'synopsis', 'body',
        ]);

        if (! $text) {
            $siteName = SiteSetting::current()->site_name;

            return "Découvrez {$this->firstAttribute($model, ['title', 'name'])} sur {$siteName}, le portail de Toulouse et sa région.";
        }

        return trim(strip_tags($text));
    }

    protected function generateCanonical(Model $model): ?string
    {
        if (method_exists($model, 'publicUrl')) {
            return $model->publicUrl();
        }

        return null;
    }

    protected function generateImage(Model $model): ?string
    {
        return $this->firstAttribute($model, ['og_image', 'image', 'poster', 'logo']);
    }

    protected function firstAttribute(Model $model, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! empty($model->{$key})) {
                return (string) $model->{$key};
            }
        }

        return null;
    }
}
