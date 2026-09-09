<?php

namespace App\Support;

use App\Mail\AdminNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifie l'équipe (jamais le visiteur) qu'un contenu public attend une
 * modération — contact, annonce, événement, fiche annuaire, actualité
 * (demande client, voir TECHNICAL_DOCUMENTATION.md §28). Destinataires
 * configurés dans `ADMIN_NOTIFICATION_EMAILS` (une ou plusieurs adresses,
 * voir config/services.php) — aucune adresse configurée = no-op silencieux
 * (pas d'exception "no recipients").
 *
 * Envoi SYNCHRONE (jamais `->queue()`) : ce projet n'a pas de worker de
 * file permanent, seulement un déclenchement WebCron pouvant aller jusqu'à
 * une fois par jour (§27) — une notification de modération mise en file
 * arriverait bien trop tard. Un échec d'envoi (SMTP down, etc.) est
 * journalisé mais ne doit JAMAIS faire échouer la soumission du visiteur
 * elle-même : la donnée est déjà enregistrée en base à ce stade, l'email
 * n'est qu'un confort de notification, pas la source de vérité.
 */
class AdminNotifier
{
    /** @param array<string, string> $lines */
    public static function send(string $heading, array $lines, ?string $actionUrl = null, ?string $actionText = null): void
    {
        $emails = config('services.admin_notifications.emails');

        if (empty($emails)) {
            return;
        }

        try {
            Mail::to($emails)->send(new AdminNotification($heading, $lines, $actionUrl, $actionText));
        } catch (Throwable $e) {
            Log::error('Échec de l\'envoi d\'une notification admin', [
                'heading' => $heading,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
