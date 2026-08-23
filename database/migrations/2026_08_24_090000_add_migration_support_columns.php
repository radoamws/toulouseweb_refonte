<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Colonnes découvertes nécessaires en implémentant la Phase 5 (migration) :
// - t_news_cat.id est un varchar(4) (codes "UNE", "ATEL"...), incompatible
//   avec news_categories.legacy_id (unsignedBigInteger) -> on garde le code
//   d'origine à part pour retrouver la bonne catégorie depuis t_news.type.
// - classified_categories n'avait pas de legacy_id (oubli lors du schéma
//   initial) -> nécessaire pour un import idempotent de t_annonce_category.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_categories', function (Blueprint $table) {
            $table->string('legacy_code', 10)->nullable()->unique()->after('legacy_id');
        });

        Schema::table('classified_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('news_categories', function (Blueprint $table) {
            $table->dropColumn('legacy_code');
        });

        Schema::table('classified_categories', function (Blueprint $table) {
            $table->dropColumn('legacy_id');
        });
    }
};
