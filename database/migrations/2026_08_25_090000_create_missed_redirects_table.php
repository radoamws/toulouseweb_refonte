<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journalise les URLs qui atterrissent en 404 faute de redirection (brief
 * §15, cron `redirects:audit`) — sans ce journal, "repérer les 404
 * fréquentes" est impossible : il n'existait jusqu'ici aucune trace de ce
 * qui échoue réellement, seulement de ce qui redirige avec succès
 * (`redirects.hits_count`). Voir Controller::redirectOrAbort() et
 * RedirectFallbackController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missed_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->unsignedInteger('hits_count')->default(1);
            // nullable : MySQL en mode strict refuse deux colonnes timestamp
            // NOT NULL sans valeur par défaut sur la même table ; toujours
            // renseignées en pratique par MissedRedirect::record().
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missed_redirects');
    }
};
