<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Complète le module "Paramètres du site" avec les réglages SEO/analytics
// globaux (page SEO globale) : le title/description/OG par entité est déjà
// administrable (seo_meta + SeoResolverService), il manquait les réglages
// qui n'appartiennent à aucune entité en particulier.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('google_analytics_id')->nullable()->after('youtube_url');
            $table->string('google_site_verification')->nullable()->after('google_analytics_id');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['google_analytics_id', 'google_site_verification']);
        });
    }
};
