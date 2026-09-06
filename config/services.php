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

];
