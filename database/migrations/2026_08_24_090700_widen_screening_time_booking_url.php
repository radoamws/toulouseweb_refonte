<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Découvert en migrant t_cine_proj_heures (Phase 5) : les liens de
// réservation billeterie (veocinemas, etc.) dépassent régulièrement 255
// caractères.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_times', function (Blueprint $table) {
            $table->string('booking_url', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('screening_times', function (Blueprint $table) {
            $table->string('booking_url')->nullable()->change();
        });
    }
};
