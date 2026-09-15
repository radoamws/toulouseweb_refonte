<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vues de page (demande client, 15/09/2026, voir TECHNICAL_DOCUMENTATION.md
 * §44) — distinct de `click_events` : un clic mesure une INTERACTION (lien
 * suivi, bouton "réserver"...), une vue de page mesure une VISITE, qu'elle
 * vienne d'un clic interne ou non (résultat Google/Bing, lien partagé,
 * favori...). Fusionner les deux dans une seule table aurait rendu le
 * libellé "Clics" du dashboard trompeur (une vue n'est pas un clic) — d'où
 * une table et des widgets séparés, réutilisant la même
 * App\Services\Stats\EntityLabelResolver pour les libellés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            // Nullable : une page sans entité précise (home, page d'accueil
            // d'une liste sans catégorie...) reste comptée globalement
            // (`totalCount()`) même sans figurer dans le détail par entité.
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('path', 500);
            $table->string('referrer', 500)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
