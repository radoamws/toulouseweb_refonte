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
}
