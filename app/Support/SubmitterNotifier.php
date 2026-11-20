<?php

namespace App\Support;

use App\Mail\SubmitterNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifie le VISITEUR qui a déposé un contenu public (annonce, événement...)
 * — équivalent de App\Support\AdminNotifier mais vers le déposant plutôt que
 * vers l'équipe (espace client, voir TECHNICAL_DOCUMENTATION.md).
 *
 * Envoi SYNCHRONE (jamais `->queue()`), même raison que AdminNotifier : pas
 * de worker de file permanent sur cet hébergement. Un échec d'envoi est
 * journalisé mais ne doit JAMAIS faire échouer la soumission du visiteur —
 * la donnée est déjà enregistrée en base, l'email n'est qu'un confort.
 */
class SubmitterNotifier
{
    /** @param array<string, string> $lines */
    public static function send(string $email, string $heading, array $lines, ?string $actionUrl = null, ?string $actionText = null): void
    {
        try {
            Mail::to($email)->send(new SubmitterNotification($heading, $lines, $actionUrl, $actionText));
        } catch (Throwable $e) {
            Log::error('Échec de l\'envoi d\'une notification au déposant', [
                'email' => $email,
                'heading' => $heading,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
