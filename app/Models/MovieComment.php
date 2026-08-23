<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieComment extends Model
{
    protected $fillable = ['movie_id', 'author_name', 'body', 'rating', 'status', 'legacy_id'];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
