<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notification au VISITEUR (jamais à l'équipe — voir App\Mail\AdminNotification
 * pour l'équivalent interne, volontairement une classe séparée pour ne jamais
 * mélanger les deux destinataires par erreur) qu'un dépôt public a bien été
 * reçu ou qu'une modification a été prise en compte (espace client, voir
 * TECHNICAL_DOCUMENTATION.md). Même structure générique que
 * AdminNotification : le contenu varie via $heading/$lines/$actionUrl.
 *
 * Volontairement PAS `ShouldQueue` — même raison que AdminNotification (pas
 * de worker de file permanent, voir App\Support\SubmitterNotifier).
 */
class SubmitterNotification extends Mailable
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
            markdown: 'emails.submitter-notification',
            with: [
                'heading' => $this->heading,
                'lines' => $this->lines,
                'actionUrl' => $this->actionUrl,
                'actionText' => $this->actionText,
            ],
        );
    }
}
