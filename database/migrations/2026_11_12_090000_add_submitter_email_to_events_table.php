<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Email du visiteur qui dépose un événement public (demande client,
// 12/11/2026 : abonner automatiquement à la newsletter les auteurs de
// dépôts validés — voir App\Observers\SubmitterSubscriptionObserver). Le
// formulaire public (EventController::store()) n'avait jamais collecté cet
// email jusqu'ici, contrairement à News (`submitter_email`) et Classified
// (`contact_email`) — nullable : jamais renseigné pour les événements
// scrapés ou importés du legacy (`source` != 'user_submitted').
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('submitter_email')->nullable()->after('booking_url');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('submitter_email');
        });
    }
};
