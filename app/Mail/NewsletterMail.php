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
 *
 * ⚠️ Le mailer 'brevo' (voir config/mail.php) N'EST PAS choisi ici, dans le
 * constructeur — bug réel trouvé le 14/09/2026 (TECHNICAL_DOCUMENTATION.md
 * §40) : `Illuminate\Mail\Mailer::sendMailable()`/`sendNow()`/`queue()`
 * appellent TOUJOURS `$mailable->mailer($this->name)` (où `$this->name` est
 * le mailer AYANT INITIÉ l'appel `Mail::to()`, ex. 'smtp' par défaut) AVANT
 * d'invoquer `Mailable::send()`/`queue()` — ce qui écrase silencieusement
 * tout choix de mailer fait ici, dans le constructeur, quel qu'il soit.
 * Un `$this->mailer('brevo')` ici n'a donc AUCUN EFFET tant que l'appel
 * passe par `Mail::to(...)->send(...)` (le cas normal). Le mailer correct
 * ('brevo') doit être choisi en amont, à l'appel :
 * `Mail::mailer('brevo')->to($email)->sendNow(...)`/`->queue(...)` — voir
 * App\Services\Newsletter\NewsletterSender, seul point qui envoie
 * réellement cette classe.
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
