<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Découvert en migrant t_agendas (Phase 5) : les URLs de réservation/billeterie
// legacy incluent souvent de longs paramètres UTM/tracking dépassant 255
// caractères.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('booking_url', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('booking_url')->nullable()->change();
        });
    }
};
