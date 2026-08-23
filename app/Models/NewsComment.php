<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsComment extends Model
{
    protected $fillable = ['news_id', 'author_name', 'body', 'status', 'legacy_id'];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
