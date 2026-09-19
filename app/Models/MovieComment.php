<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avis sur un film — soumis anonymement côté public (pas de compte visiteur
 * sur ce site, voir App\Http\Controllers\ClassifiedController pour le même
 * choix sur les annonces), toujours créé en `status = 'pending'`
 * (App\Http\Controllers\CinemaController::storeComment()) : jamais publié
 * automatiquement, seul un admin (MovieCommentResource) le valide/refuse.
 * `author_email` n'est jamais affiché publiquement (contact modération
 * uniquement).
 */
class MovieComment extends Model
{
    protected $fillable = ['movie_id', 'author_name', 'author_email', 'body', 'rating', 'status', 'legacy_id'];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
