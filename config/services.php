<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Purge du cache Cloudflare (demande client, voir
     * App\Services\Cache\CloudflareCachePurger et TECHNICAL_DOCUMENTATION.md
     * §17). `enabled` est une double sécurité en plus de zone_id/api_token
     * vides : les deux doivent être vrais pour qu'un appel HTTP sortant soit
     * tenté — jamais activé en local/dev/test (voir phpunit.xml).
     */
    'cloudflare' => [
        'enabled' => (bool) env('CLOUDFLARE_CACHE_PURGE_ENABLED', false),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    /*
     * Demande d'indexation automatique Google Search Console (demande
     * client, voir App\Services\Seo\GoogleIndexingService et
     * TECHNICAL_DOCUMENTATION.md §20). `enabled` : même double sécurité que
     * Cloudflare, jamais activé en local/dev/test (voir phpunit.xml).
     * `daily_quota` : l'API Indexing de Google impose 200 requêtes/jour par
     * défaut — configurable si un compte Cloud a obtenu un quota plus élevé.
     */
    'google_indexing' => [
        'enabled' => (bool) env('GOOGLE_INDEXING_ENABLED', false),
        'credentials_json_base64' => env('GOOGLE_INDEXING_CREDENTIALS_JSON_BASE64'),
        'daily_quota' => (int) env('GOOGLE_INDEXING_DAILY_QUOTA', 200),
    ],

    /*
     * Déclenchement HTTP du "scheduler" (WebCron Infomaniak — l'hébergement
     * mutualisé ne permet pas de crontab serveur, voir
     * App\Console\Commands\RunWebCron et TECHNICAL_DOCUMENTATION.md §27).
     * Jeton long et aléatoire, seule protection de la route
     * `/webcron/{token}` — jamais deviné, jamais réutilisé ailleurs.
     */
    'webcron' => [
        'secret' => env('WEBCRON_SECRET'),
    ],

    /*
     * Destinataires des notifications internes (nouveau contenu à modérer
     * depuis un formulaire public — contact, annonce, événement, fiche
     * annuaire, actualité). Demande client, voir App\Support\AdminNotifier
     * et TECHNICAL_DOCUMENTATION.md §28. Une ou plusieurs adresses séparées
     * par des virgules dans ADMIN_NOTIFICATION_EMAILS ; vide = aucune
     * notification envoyée (pas d'erreur, juste un no-op silencieux).
     */
    'admin_notifications' => [
        'emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ADMIN_NOTIFICATION_EMAILS', ''))
        ))),
    ],

    /*
     * Newsletter (demande client, 12/09/2026, voir
     * App\Services\Newsletter\NewsletterSender et
     * TECHNICAL_DOCUMENTATION.md §36).
     *
     * ⚠️ `sending_enabled` = false par défaut, ET DOIT LE RESTER tant que le
     * client n'a pas donné son "GO" explicite : demande verbatim du client
     * le 12/09/2026 — "N'envoie pas à tout le monde mais à moi seulement
     * d'abord pour validation. Je donnerai le GO pour publier à tous les
     * contacts seulement après ma validation." Tant que ce flag est à
     * false, `NewsletterResource` bloque l'action "Envoyer à tous les
     * abonnés" (le bouton "Envoyer un test" reste toujours disponible,
     * lui, car il ne cible jamais que `test_recipients`). Ne PAS passer à
     * true sans confirmation explicite du client.
     */
    'newsletter' => [
        'sending_enabled' => (bool) env('NEWSLETTER_SENDING_ENABLED', false),
        'test_recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NEWSLETTER_TEST_RECIPIENTS', 'rado.rakotoarivelo@amws.space'))
        ))),
    ],

];
