<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nettoyage des données legacy avant import — encodage, dates invalides,
 * slugs. Voir TECHNICAL_DOCUMENTATION.md §3.1/§10 (audit de la base
 * toulouseweb_old : mojibake, dates 0000-00-00, etc.).
 */
class LegacyCleaner
{
    protected const INVALID_DATES = [
        '0000-00-00', '0000-00-00 00:00:00',
        '0001-01-01', '0001-01-01 00:00:00',
    ];

    /**
     * Trim + null si vide + correction heuristique du mojibake (texte
     * mal décodé, ex. "sp�cialiste"). Ne peut pas récupérer les octets
     * perdus — se contente d'éviter de propager le caractère de
     * remplacement Unicode brut.
     */
    public static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || in_array($value, ['?', '-', 'N/A', 'n/a'], true)) {
            return null;
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            if ($converted !== false) {
                $value = $converted;
            }
        }

        return str_replace("\u{FFFD}", '', $value);
    }

    public static function date(null|string|\DateTimeInterface $value): ?string
    {
        if (! $value) {
            return null;
        }
        if (is_string($value) && in_array($value, self::INVALID_DATES, true)) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Réutilise le slug legacy tel quel (déjà slug-like) plutôt que de le
     * régénérer depuis le titre — préserve la continuité SEO (brief §15).
     * Ne dédoublonne QUE si `$excludeId` ne correspond pas à la ligne qui
     * détient déjà ce slug (sert à l'idempotence : sur un ré-import, la
     * ligne existante ne doit pas se voir attribuer un suffixe -2 à cause
     * d'elle-même).
     */
    public static function preserveSlug(?string $legacySlug, string $fallbackTitle, string $table, ?int $excludeId = null): string
    {
        $base = self::text($legacySlug) ? Str::slug($legacySlug) : Str::slug($fallbackTitle);
        if ($base === '') {
            $base = Str::random(8);
        }

        $slug = $base;
        $i = 2;
        while (
            DB::table($table)->where('slug', $slug)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
