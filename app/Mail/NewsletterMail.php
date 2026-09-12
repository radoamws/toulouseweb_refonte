<?php

namespace App\Mail;

use App\Models\Newsletter;
use App\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Envoi d'une campagne (App\Models\Newsletter) à UN abonné. `ShouldQueue`
 * (contrairement à AdminNotification, volontairement synchrone) : un envoi
 * à plusieurs centaines/milliers d'abonnés doit passer par la file
 * (`queue_connection=database`) — voir App\Services\Newsletter\NewsletterSender,
 * qui met chaque envoi en file plutôt que de boucler des appels SMTP
 * synchrones dans une requête HTTP ou une commande bloquante. La file est
 * vidée par le WebCron quotidien (`queue:work --stop-when-empty`, voir
 * App\Console\Commands\RunWebCron et TECHNICAL_DOCUMENTATION.md §27) — un
 * envoi de newsletter n'est pas urgent à la minute près, contrairement aux
 * notifications de modération.
 */
class NewsletterMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Newsletter $newsletter,
        public NewsletterSubscriber $subscriber,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->newsletter->subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.newsletter',
            with: [
                'newsletter' => $this->newsletter,
                'subscriber' => $this->subscriber,
                'unsubscribeUrl' => route('newsletter.unsubscribe', $this->subscriber->unsubscribe_token),
            ],
        );
    }
}
