<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SEO générique par entité (fallback perso -> sinon généré, voir
// App\Services\Seo\SeoResolverService) et redirections 301 administrables.
// Remplace t_seo_entity/t_seo_groupe/t_entete et couvre le besoin de
// préservation SEO du brief (§13/§15) puisque l'ancien 301.json n'est pas
// exploitable (voir TECHNICAL_DOCUMENTATION.md §5).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();
            $table->morphs('seoable');
            $table->string('title')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('robots', 100)->nullable(); // ex: 'index,follow'
            $table->string('og_image')->nullable();
            $table->json('structured_data')->nullable(); // override Schema.org ponctuel
            $table->timestamps();
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path')->unique();
            $table->string('to_path');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedInteger('hits_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('seo_meta');
    }
};
