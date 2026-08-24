<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Planification (cron unique côté serveur : `* * * * * php artisan schedule:run`,
// voir TECHNICAL_DOCUMENTATION.md §11). Traite la file d'attente sur un
// hébergement mutualisé sans worker permanent (brief §7 décision d'architecture).
Schedule::command('queue:work --stop-when-empty')->everyMinute()->withoutOverlapping();
Schedule::command('sitemap:generate')->daily();
