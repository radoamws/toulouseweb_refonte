<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Meta keywords (demande client, 26/11/2026) — personnalisable en admin,
// sinon généré automatiquement par SeoResolverService::generateKeywords().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->string('keywords', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }
};
