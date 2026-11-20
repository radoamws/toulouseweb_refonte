<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Élargit `scraper_sources.type` pour accueillir le scraper d'actualités
 * (demande client, 20/11/2026 — voir
 * App\Services\Scraping\News\LeJournalToulousainDriver et
 * TECHNICAL_DOCUMENTATION.md §82).
 *
 * ⚠️ Bug réel trouvé et corrigé (20/11/2026, en lançant la suite de tests) :
 * une première version faisait `DB::statement("ALTER TABLE ... MODIFY type
 * ENUM(...)")`, syntaxe MySQL pure qui casse TOUTE la suite de tests
 * (sqlite en mémoire, voir phpunit.xml) — confirmé en plus que SQLite
 * applique réellement la contrainte CHECK générée par `$table->enum()`
 * (testé en direct : un INSERT avec une valeur hors liste lève bien une
 * violation de contrainte), impossible à MODIFIER via `ALTER TABLE` sur ce
 * driver (capacités volontairement limitées de SQLite — seuls
 * ADD/DROP/RENAME COLUMN sont supportés, jamais la modification d'une
 * contrainte CHECK existante). `$table->string('type')->change()` aurait
 * été la solution Laravel idiomatique, mais `doctrine/dbal` (requis par
 * `change()`) n'est pas installé dans ce projet.
 *
 * Solution portable retenue, SANS `change()` ni SQL spécifique à un driver :
 * ajoute une colonne `type_new` (string), copie les données, supprime
 * `type` (avec son CHECK), renomme `type_new` en `type` — uniquement des
 * opérations `addColumn`/`dropColumn`/`renameColumn`, supportées nativement
 * par MySQL ET SQLite sans dépendance supplémentaire. Ne renomme/recrée
 * JAMAIS la TABLE elle-même (contrairement à un rebuild complet) : la clé
 * étrangère `scraper_runs.source_id -> scraper_sources.id` n'est donc
 * jamais perturbée.
 *
 * Compromis assumé : `type` redevient une colonne `string` nullable au
 * niveau base (plus de contrainte NOT NULL/ENUM stricte) — la validation
 * des valeurs reste faite au niveau applicatif (voir le `Select` de chaque
 * `*ScraperSourcesSeeder`/admin), comme pour de nombreuses autres colonnes
 * de ce projet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraper_sources', function (Blueprint $table) {
            $table->string('type_new')->nullable()->after('type');
        });

        DB::statement('UPDATE scraper_sources SET type_new = type');

        Schema::table('scraper_sources', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('scraper_sources', function (Blueprint $table) {
            $table->renameColumn('type_new', 'type');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_sources', function (Blueprint $table) {
            $table->enum('type_old', ['agenda', 'cinema'])->nullable()->after('type');
        });

        DB::statement("UPDATE scraper_sources SET type_old = type WHERE type IN ('agenda', 'cinema')");

        Schema::table('scraper_sources', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('scraper_sources', function (Blueprint $table) {
            $table->renameColumn('type_old', 'type');
        });
    }
};
