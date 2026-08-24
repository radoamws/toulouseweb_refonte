<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module "Paramètres du site" (brief §13, TODO historique dans
 * components/layouts/app.blade.php) : une seule ligne, administrable via
 * Filament\Pages\Page (pas un Resource — il n'y a rien à lister), alimente
 * le JSON-LD Organization, les meta OG par défaut et le pied de page. Pas de
 * table legacy équivalente (`t_entete` est un tout autre système : des
 * overrides SEO par page d'annuaire, déjà couvert par `seo_meta`) — feature
 * entièrement nouvelle, pas une migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('ToulouseWeb');
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('default_og_image')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
