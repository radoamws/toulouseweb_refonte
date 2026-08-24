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

// Scraping cinéma (brief §7/§9) — fréquence quotidienne : les nouveaux
// films sortent en général le mercredi, une vérification quotidienne
// suffit largement et reste légère pour l'API distante.
Schedule::command('scrape:cinema')->dailyAt('05:00')->withoutOverlapping();
