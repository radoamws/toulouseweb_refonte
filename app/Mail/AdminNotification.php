<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notification interne à l'équipe (jamais au visiteur) qu'un contenu public
 * attend une modération — contact, annonce, événement, fiche annuaire,
 * actualité (demande client, voir App\Support\AdminNotifier et
 * TECHNICAL_DOCUMENTATION.md §28).
 *
 * Une seule classe générique plutôt que 5 quasi identiques : le contenu
 * varie via `$heading`/`$lines`/`$actionUrl`, pas la structure d'envoi.
 * Volontairement PAS `ShouldQueue` — envoyée de façon SYNCHRONE (voir
 * AdminNotifier) : contrairement au reste de l'appli, qui peut s'appuyer
 * sur le WebCron quotidien (jusqu'à 24h de latence, §27), une notification
 * de modération doit arriver au plus vite.
 */
class AdminNotification extends Mailable
{
    use Queueable;

    /** @param array<string, string> $lines Paires libellé => valeur affichées dans l'email. */
    public function __construct(
        public string $heading,
        public array $lines,
        public ?string $actionUrl = null,
        public ?string $actionText = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.config('app.name').'] '.$this->heading);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.admin-notification',
            with: [
                'heading' => $this->heading,
                'lines' => $this->lines,
                'actionUrl' => $this->actionUrl,
                'actionText' => $this->actionText,
            ],
        );
    }
}
