<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cinéma : reprise directe du modèle legacy (le mieux normalisé de l'ancien
// schéma), assaini avec FK strictes. Remplace t_cine/t_cine_film/
// t_cine_projection/t_cine_proj_heures/t_cine_proj_types/t_cine_type_projection/
// t_cine_lang/t_cine_comment. Les tables _bkp legacy ne sont pas reprises
// (données mortes, voir TECHNICAL_DOCUMENTATION.md §3.2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // VF, VOSTF, VF, Inconnue...
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('screening_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Normale, 3D, IMAX...
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('cinemas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('external_url')->nullable(); // référence source scraping
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('director')->nullable();
            $table->text('cast')->nullable();
            $table->string('genres')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->text('synopsis')->nullable();
            $table->string('poster')->nullable();
            $table->string('distributor')->nullable();
            $table->date('release_date')->nullable();
            $table->string('external_ref')->nullable()->index(); // dédup scraper
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cinema_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->boolean('preview')->default(false); // avant-première
            $table->boolean('staff_pick')->default(false); // "coup de coeur"
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['cinema_id', 'movie_id', 'language_id']);
        });

        Schema::create('screening_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screening_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->time('time');
            $table->string('booking_url')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();

            // Pas d'index composite ici : `day` est remplacé par `weekday`
            // dans 2026_08_24_090400_adjust_cinema_schedule_columns.php, qui
            // pose son propre index — un index sur une colonne aussitôt
            // supprimée casse la reconstruction de table SQLite (tests).
        });

        Schema::create('screening_screening_type', function (Blueprint $table) {
            $table->foreignId('screening_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screening_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['screening_id', 'screening_type_id']);
        });

        Schema::create('movie_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->string('author_name')->nullable();
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->enum('status', ['pending', 'published', 'rejected'])->default('pending');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_comments');
        Schema::dropIfExists('screening_screening_type');
        Schema::dropIfExists('screening_times');
        Schema::dropIfExists('screenings');
        Schema::dropIfExists('movies');
        Schema::dropIfExists('cinemas');
        Schema::dropIfExists('screening_types');
        Schema::dropIfExists('languages');
    }
};
