<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une URL demandée qui n'a résolu ni à une route réelle ni à une
 * `Redirect` active — journal utilisé par `redirects:audit` (brief §15)
 * pour repérer les 404 fréquentes candidates à une redirection.
 */
class MissedRedirect extends Model
{
    protected $fillable = ['path', 'hits_count', 'first_seen_at', 'last_seen_at'];

    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public static function record(string $path): void
    {
        $missed = static::firstOrNew(['path' => $path]);
        $missed->hits_count = ($missed->exists ? $missed->hits_count : 0) + 1;
        $missed->first_seen_at ??= now();
        $missed->last_seen_at = now();
        $missed->save();
    }
}
