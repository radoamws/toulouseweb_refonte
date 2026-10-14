<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\Trackable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ligne de transport Tisséo (brief roadmap "contenu", 14/10/2026). Voir
 * App\Console\Commands\ImportTisseoGtfs — régénérée entièrement à chaque
 * import, le slug est calculé explicitement (pas Spatie\Sluggable : des noms
 * de ligne comme "13" seuls ne feraient pas un slug utile/unique, voir
 * ImportTisseoGtfs::slugFor()).
 */
class TransportLine extends Model
{
    use HasSeoMeta, Trackable;

    protected $fillable = [
        'gtfs_route_id', 'name', 'long_name', 'slug', 'type', 'color',
        'stops', 'first_departure', 'last_departure', 'frequency_minutes',
        'trip_count', 'is_active',
    ];

    protected $casts = [
        'stops' => 'array',
        'is_active' => 'boolean',
    ];

    public const TYPE_LABELS = [
        'metro' => 'Métro',
        'tram' => 'Tramway',
        'teleo' => 'Téléphérique',
        'bus' => 'Bus',
    ];

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? 'Ligne';
    }

    public function publicUrl(): string
    {
        return route('transports.show', $this->slug);
    }

    /**
     * Les horaires GTFS peuvent dépasser 24h pour un trajet après minuit
     * (convention standard, ex. "24:40:00" pour 00h40 le lendemain) — on ne
     * stocke jamais la version "corrigée" (perdrait l'info "service de
     * nuit"), seulement affichée proprement ici.
     */
    public static function formatTime(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $raw));

        return sprintf('%02d:%02d', $hours % 24, $minutes);
    }
}
