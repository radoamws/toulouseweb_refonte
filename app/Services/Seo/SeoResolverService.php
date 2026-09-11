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
        //
        // ⚠️ Deuxième bug réel trouvé et corrigé (11/09/2026, audit SEO/GEO) :
        // cette troncature s'appliquait INCONDITIONNELLEMENT, y compris à un
        // `seo_meta.title`/`description` déjà rédigé à la main et déjà sous
        // les recommandations Google (~70/~160 caractères) — constaté en
        // direct sur la home : le titre migré (69 caractères, déjà correct)
        // perdait "Toulouse" une fois retaillé à 60. `limitIfNeeded()` ne
        // tronque désormais que ce qui dépasse réellement un plafond
        // raisonnable, personnalisé ou auto-généré.
        return [
            'title' => $this->limitIfNeeded($title, 70),
            'description' => $this->limitIfNeeded($description ?? '', 165),
            'canonical_url' => $seo?->canonical_url ?: $this->generateCanonical($model),
            'robots' => $seo?->robots ?: 'index,follow',
            'og_image' => $seo?->og_image ?: $this->generateImage($model),
            'structured_data' => $seo?->structured_data,
        ];
    }

    protected function limitIfNeeded(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? Str::limit($text, $max, '…', preserveWords: true) : $text;
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

    /**
     * ⚠️ Bug réel trouvé et corrigé (11/09/2026, audit SEO/GEO) : retournait
     * le chemin brut stocké en base (ex. "news/Laloum & Consuelo.jpg",
     * "movies/as de la jungle.jpg") sans jamais le résoudre en URL absolue
     * ni l'encoder — un `<img src>` classique fonctionne quand même (un
     * navigateur ré-encode silencieusement les espaces d'un attribut src),
     * mais une balise `og:image`/`twitter:image`/JSON-LD `image` contient la
     * valeur BRUTE qu'un crawler externe (réseaux sociaux, Google, moteurs
     * de réponse IA) va chercher telle quelle — vérifié en direct : cette
     * URL non encodée répond en échec de connexion, la même une fois
     * encodée répond 200. Portée mesurée : 231/233 actualités publiées et
     * la quasi-totalité des films avaient un chemin ainsi cassé. Voir
     * App\Models\Concerns\ResolvesImageUrl::resolveImageUrlEncoded(),
     * réutilisée aussi par App\Console\Commands\GenerateSitemap.
     */
    protected function generateImage(Model $model): ?string
    {
        $value = null;
        foreach (['og_image', 'image', 'poster', 'logo'] as $key) {
            if (! empty($model->{$key})) {
                $value = (string) $model->{$key};

                break;
            }
        }

        if ($value === null) {
            return null;
        }

        return method_exists($model, 'resolveImageUrlEncoded')
            ? $model::resolveImageUrlEncoded($value)
            : $value;
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
