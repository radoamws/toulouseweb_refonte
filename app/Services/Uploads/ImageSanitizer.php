<?php

namespace App\Services\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sanitisation des images uploadées depuis un formulaire PUBLIC (demande
 * client, voir App\Rules\GenuineImage, ClassifiedController::store(),
 * EventController::store() et TECHNICAL_DOCUMENTATION.md §28).
 *
 * `GenuineImage` (règle de validation) vérifie déjà que le fichier est
 * décodable comme une vraie image — mais ça ne suffit pas contre un
 * fichier "polyglotte" : un contenu qui COMMENCE par des données image
 * valides (donc `getimagesize()`/`imagecreatefromXXX()` réussissent) mais
 * contient une charge utile ajoutée après les données décodées (ou dans
 * des métadonnées EXIF) — GD ne la lit jamais, mais un autre programme qui
 * lirait le fichier brut (ou un serveur mal configuré qui l'exécuterait)
 * le pourrait. La seule protection réellement fiable : RE-ENCODER l'image
 * entièrement à partir des pixels décodés par GD — le fichier de sortie ne
 * contient plus que ce que GD a compris comme des pixels, tout le reste
 * (charge utile, métadonnées) est purgé par construction, quel que soit ce
 * que contenait l'original.
 *
 * Volontairement pas de dépendance externe (Intervention Image...) — GD
 * est déjà une extension PHP embarquée, présente à la fois en local et
 * sur le serveur de production (vérifié, voir TECHNICAL_DOCUMENTATION.md
 * §26/§27).
 */
class ImageSanitizer
{
    private const JPEG_QUALITY = 85;

    private const PNG_COMPRESSION = 6;

    private const WEBP_QUALITY = 85;

    /**
     * Ré-encode le fichier envoyé et retourne le chemin d'un fichier
     * temporaire "propre" — à la charge de l'appelant de le supprimer une
     * fois utilisé. Lève une exception si le fichier n'est, en réalité, pas
     * une image décodable malgré une validation `GenuineImage` déjà passée
     * (défense en profondeur : ne fait jamais confiance à un seul contrôle).
     */
    public static function sanitizeToTempFile(UploadedFile $file): string
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            throw new RuntimeException("Le fichier envoyé n'est pas une image valide.");
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file->getRealPath()),
            default => false,
        };

        if ($source === false) {
            throw new RuntimeException("Le fichier envoyé n'est pas une image valide.");
        }

        // Préserve la transparence (PNG/WEBP) lors de la ré-encodage plutôt
        // que de la remplacer par un fond noir/opaque par défaut de GD.
        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $extension = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'sanitized_img_').'.'.$extension;

        $written = match ($info[2]) {
            IMAGETYPE_JPEG => imagejpeg($source, $tempPath, self::JPEG_QUALITY),
            IMAGETYPE_PNG => imagepng($source, $tempPath, self::PNG_COMPRESSION),
            IMAGETYPE_WEBP => imagewebp($source, $tempPath, self::WEBP_QUALITY),
        };

        imagedestroy($source);

        if (! $written) {
            throw new RuntimeException("Impossible de traiter l'image envoyée.");
        }

        return $tempPath;
    }

    /**
     * Pour les modèles à colonne `image` texte simple (Event, News — pas de
     * Spatie MediaLibrary) : sanitise puis stocke sur le disque public,
     * retourne le chemin relatif à écrire dans la colonne (même convention
     * que Filament\Forms\Components\FileUpload::make('image')->directory(...)
     * utilisé côté admin pour ces mêmes modèles).
     */
    public static function sanitizeAndStore(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $tempPath = self::sanitizeToTempFile($file);

        try {
            $relativePath = trim($directory, '/').'/'.Str::random(40).'.'.pathinfo($tempPath, PATHINFO_EXTENSION);
            Storage::disk($disk)->put($relativePath, file_get_contents($tempPath));

            return $relativePath;
        } finally {
            @unlink($tempPath);
        }
    }
}
