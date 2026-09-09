<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Défense en profondeur au-delà des règles Laravel `image`/`mimes:...`
 * (qui inspectent déjà le contenu réel, pas seulement l'extension déclarée
 * par le client) — demande client explicite : *"ne pas uploader que des
 * fichiers images, et vérifier que ce ne sont pas des faux images (hack
 * avec extension d'image)"* (voir ClassifiedController::store(),
 * EventController::store() et TECHNICAL_DOCUMENTATION.md §28).
 *
 * Vérifie que `getimagesize()` réussit ET que le type détecté fait partie
 * des formats autorisés — rejette un fichier qui aurait une extension/MIME
 * déclarée d'image mais un contenu non décodable comme tel (un exécutable
 * renommé en `.jpg` échoue déjà à la règle `image` de Laravel dans
 * l'immense majorité des cas, mais ce contrôle explicite documente
 * l'intention et protège aussi contre un contournement de la détection
 * MIME par entête falsifié).
 *
 * Ne suffit PAS à lui seul contre un fichier "polyglotte" (image valide en
 * tête + charge utile ajoutée après les données décodées) — voir
 * App\Services\Uploads\ImageSanitizer, qui ré-encode l'image après cette
 * validation pour purger tout ce qui n'est pas un pixel réellement décodé.
 */
class GenuineImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail("Le fichier envoyé n'est pas valide.");

            return;
        }

        $info = @getimagesize($value->getRealPath());

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $fail("Le fichier envoyé n'est pas une image valide (formats acceptés : JPEG, PNG, WEBP).");
        }
    }
}
