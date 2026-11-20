<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Table dédiée pour le broker de mot de passe oublié du guard `editor` (voir config/auth.php) — séparée de `password_reset_tokens` (comptes admin) par principe, même si une collision réelle serait improbable (clé = email). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_password_reset_tokens');
    }
};
