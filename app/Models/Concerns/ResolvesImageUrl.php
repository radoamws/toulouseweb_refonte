<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Résout un chemin d'image stocké en base (colonne texte simple, ex.
 * `sliders.image`, `events.image`, `news.image`, `movies.poster`) vers une
 * URL réellement affichable. Nécessaire car Filament\Forms\FileUpload
 * stocke un chemin RELATIF au disque `public` (ex: "sliders/xxx.jpg"), pas
 * une URL — un `<img src="{{ $model->image }}">` brut serait résolu par le
 * navigateur relativement à la page courante et casserait l'affichage.
 * Les valeurs déjà en URL absolue (http://...) ou en chemin `/storage/...`
 * passent inchangées. Voir TECHNICAL_DOCUMENTATION.md §13.
 */
trait ResolvesImageUrl
{
    public static function resolveImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/') || str_starts_with($path, 'data:')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Comme `resolveImageUrl()`, mais avec chaque segment du chemin encodé —
     * nécessaire pour toute URL destinée à être lue TELLE QUELLE par un
     * client externe (balise `og:image`/`twitter:image`, JSON-LD `image`,
     * `<image:image>` de sitemap.xml) plutôt qu'affichée dans un attribut
     * HTML `<img src>` (qu'un navigateur ré-encode silencieusement).
     * ⚠️ Bug réel trouvé et corrigé (11/09/2026, audit SEO/GEO) : un chemin
     * legacy avec espace/accent (ex. "movies/as de la jungle.jpg") produit
     * une URL non résolue par un crawler externe, alors qu'un `<img>` classique
     * fonctionne quand même — voir App\Services\Seo\SeoResolverService et
     * App\Console\Commands\GenerateSitemap, qui utilisent tous deux cette
     * méthode plutôt que de dupliquer la logique d'encodage.
     */
    public static function resolveImageUrlEncoded(?string $path): ?string
    {
        $url = static::resolveImageUrl($path);

        return $url !== null ? self::encodeUrlPath($url) : null;
    }

    /** Idempotent (décode avant de ré-encoder) pour ne jamais double-encoder une URL déjà propre. */
    protected static function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $url; // pas une URL absolue exploitable — laissée telle quelle
        }

        $encodedPath = collect(explode('/', $parts['path']))
            ->map(fn (string $segment) => rawurlencode(rawurldecode($segment)))
            ->implode('/');

        $rebuilt = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$encodedPath;

        return isset($parts['query']) ? "{$rebuilt}?{$parts['query']}" : $rebuilt;
    }
}
