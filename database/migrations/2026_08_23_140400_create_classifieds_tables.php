<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Annonces : catégories extensibles + workflow de modération strict
// (utilisateur -> pending -> validation admin -> published). Remplace
// t_annonce/t_annonce_category/t_annonce_categ (quasi inutilisées en legacy,
// non migrées telles quelles — voir TECHNICAL_DOCUMENTATION.md §3.2/§10).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classified_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('classified_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('classifieds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('classified_categories')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->decimal('price', 10, 2)->nullable();
            $table->string('location')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email')->nullable();
            $table->enum('status', ['pending', 'published', 'rejected', 'expired', 'archived'])->default('pending');
            $table->boolean('is_featured')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classifieds');
        Schema::dropIfExists('classified_categories');
    }
};
