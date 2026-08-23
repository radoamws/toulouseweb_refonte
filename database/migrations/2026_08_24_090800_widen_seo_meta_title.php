<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Découvert en migrant t_seo_entity (Phase 5) : les anciens titres SEO
// (souvent du remplissage de mots-clés façon 2010-2015, voir audit §5)
// dépassent régulièrement 255 caractères — legacy se_title est en
// varchar(500).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->string('title', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
    }
};
