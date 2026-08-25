<?php

namespace App\Console\Commands;

use App\Models\Classified;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * "Gestion des contenus expirés" / "gestion des événements passés" (brief
 * §13, SEO/GEO — priorité absolue). Fait passer `status` de `published` à
 * `expired` pour les événements et annonces dont la date est dépassée.
 *
 * IMPORTANT : ce n'est PAS ce qui protège le public/le SEO d'afficher du
 * contenu périmé — `Event::scopeUpcoming()`/le filtre `expires_at` de
 * `ClassifiedController` filtrent déjà par DATE directement (voir leurs
 * classes), indépendamment de la valeur de `status`. Cette commande sert
 * uniquement à garder `status` cohérent pour l'admin (filtres/affichage
 * dans EventResource/ClassifiedResource) — sans elle, un événement passé
 * restait affiché "Publié" indéfiniment dans l'admin, alors qu'il n'était
 * déjà plus visible publiquement (source de confusion, pas un bug public).
 *
 * `MigrateEvents.php` posait déjà `expired` une fois, au moment de la
 * migration — mais rien ne maintenait cet état à jour depuis (voir
 * TECHNICAL_DOCUMENTATION.md §13).
 */
class MarkExpiredContent extends Command
{
    protected $signature = 'content:mark-expired';

    protected $description = "Fait passer au statut 'expired' les événements et annonces publiés dont la date est dépassée";

    public function handle(): int
    {
        $expiredEvents = Event::where('status', 'published')
            ->where(function ($q) {
                $q->where('end_date', '<', now())
                    ->orWhere(function ($q2) {
                        $q2->whereNull('end_date')->where('start_date', '<', now()->startOfDay());
                    });
            })
            ->update(['status' => 'expired']);

        $expiredClassifieds = Classified::where('status', 'published')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $this->info("Événements passés en 'expired' : {$expiredEvents}. Annonces passées en 'expired' : {$expiredClassifieds}.");

        return self::SUCCESS;
    }
}
