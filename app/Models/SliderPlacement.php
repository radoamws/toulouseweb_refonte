<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SliderPlacement extends Model
{
    protected $fillable = ['slider_id', 'page'];

    public function slider(): BelongsTo
    {
        return $this->belongsTo(Slider::class);
    }
}
