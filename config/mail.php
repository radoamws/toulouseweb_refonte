<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        /*
         * Newsletters uniquement (App\Mail\NewsletterMail::mailer('brevo'),
         * voir TECHNICAL_DOCUMENTATION.md §37/§39/§40) — jamais les
         * notifications admin/transactionnelles, qui restent sur 'smtp'
         * (boîte contact@toulouseweb.com d'Infomaniak). Constaté en
         * pratique le 14/09/2026 : un email de type newsletter (mise en
         * page, liste à puces, bouton d'action) envoyé depuis cette boîte
         * mutualisée classique n'arrivait JAMAIS à destination, alors
         * qu'une notification transactionnelle simple envoyée depuis la
         * même boîte arrive normalement — l'hébergement mutualisé
         * filtre/retient silencieusement ce qui ressemble à un envoi de
         * masse/marketing. Le SPF de `toulouseweb.com` autorise déjà
         * `spf.sendinblue.com` : Brevo (ex-Sendinblue) servait déjà à ça
         * historiquement.
         *
         * Transport 'brevo-api' (App\Mail\Transport\BrevoApiTransport,
         * enregistré dans AppServiceProvider::boot()) plutôt que le relais
         * SMTP Brevo essayé en premier (§37/§39) : l'API répond
         * immédiatement par une erreur explicite en cas de clé invalide ou
         * de domaine/expéditeur non vérifié, alors que le relais SMTP
         * acceptait silencieusement des envois qui n'arrivaient ensuite
         * jamais à destination — bien plus facile à diagnostiquer.
         */
        'brevo' => [
            'transport' => 'brevo-api',
            'key' => env('BREVO_API_KEY'),
            'sender_email' => env('BREVO_SENDER_EMAIL', env('MAIL_FROM_ADDRESS')),
            'sender_name' => env('BREVO_SENDER_NAME', env('MAIL_FROM_NAME')),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
