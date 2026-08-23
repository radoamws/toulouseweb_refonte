<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Redirection 301/302 administrable, alimentée par la migration (ancien
 * schéma d'URL legacy) et par l'admin (brief §15). Voir
 * App\Http\Middleware\HandleLegacyRedirects.
 */
class Redirect extends Model
{
    protected $fillable = ['from_path', 'to_path', 'status_code', 'hits_count', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
