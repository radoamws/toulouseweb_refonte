<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Découvert en migrant t_seo_entity (Phase 5) : legacy se_descr est un TEXT
// (non borné), certaines descriptions dépassent largement 500 caractères.
// On préserve la donnée brute en `text` — l'affichage réel respecte déjà
// une limite de 160 caractères au rendu (App\Services\Seo\SeoResolverService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->change();
        });
    }
};
