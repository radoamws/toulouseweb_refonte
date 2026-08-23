<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class NewsCategory extends Model
{
    use HasSlug;

    protected $fillable = ['name', 'slug', 'legacy_id', 'legacy_code'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug')->doNotGenerateSlugsOnUpdate();
    }

    public function news(): HasMany
    {
        return $this->hasMany(News::class, 'category_id');
    }
}
