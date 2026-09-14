<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Transport Symfony Mailer personnalisé pour l'API transactionnelle Brevo
 * (`POST /v3/smtp/email`) — demande client, 14/09/2026 (voir
 * TECHNICAL_DOCUMENTATION.md §39/§40). Utilisé UNIQUEMENT par le mailer
 * `brevo` (voir config/mail.php), lui-même utilisé UNIQUEMENT par
 * App\Mail\NewsletterMail — jamais par les notifications admin.
 *
 * Préféré au relais SMTP Brevo (essayé en premier, voir §37/§39) : plus
 * simple à diagnostiquer (l'API répond immédiatement par un code HTTP et un
 * message d'erreur explicite en cas de clé invalide/domaine non vérifié,
 * alors que le relais SMTP acceptait silencieusement des envois qui
 * n'arrivaient ensuite jamais à destination).
 *
 * Modelé sur `Illuminate\Mail\Transport\ResendTransport` (fourni par
 * Laravel) — même structure (extraction de l'Email Symfony, conversion en
 * payload API, `TransportException` en cas d'échec), adapté au format de
 * requête Brevo.
 */
class BrevoApiTransport extends AbstractTransport
{
    public function __construct(
        protected string $apiKey,
        protected ?string $senderEmail = null,
        protected ?string $senderName = null,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();
        $sender = $envelope->getSender();

        $payload = array_filter([
            'sender' => array_filter([
                // L'expéditeur DOIT être vérifié dans le compte Brevo (domaine
                // + adresse) — voir TECHNICAL_DOCUMENTATION.md §39, sinon
                // l'API répond 400/401 (contrairement au relais SMTP, qui
                // acceptait silencieusement).
                'email' => $this->senderEmail ?: $sender->getAddress(),
                'name' => $this->senderName ?: ($sender->getName() ?: null),
            ]),
            'to' => $this->addressesToArray($this->getRecipients($email, $envelope)),
            'subject' => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody(),
            'textContent' => $email->getTextBody(),
        ]);

        $replyTo = $email->getReplyTo();
        if (! empty($replyTo)) {
            $payload['replyTo'] = $this->addressesToArray($replyTo)[0] ?? null;
        }

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new TransportException(
                "Requête à l'API Brevo refusée (HTTP {$response->status()}) : {$response->body()}",
                $response->status()
            );
        }
    }

    /** @param Address[] $addresses */
    protected function addressesToArray(array $addresses): array
    {
        return array_values(array_map(
            fn (Address $address) => array_filter([
                'email' => $address->getAddress(),
                'name' => $address->getName() ?: null,
            ]),
            $addresses,
        ));
    }

    /** @return Address[] */
    protected function getRecipients(Email $email, Envelope $envelope): array
    {
        return array_filter($envelope->getRecipients(), function (Address $address) use ($email) {
            return in_array($address, array_merge($email->getCc(), $email->getBcc()), true) === false;
        });
    }

    public function __toString(): string
    {
        return 'brevo-api';
    }
}
