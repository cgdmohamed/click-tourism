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
        'token' => env('POSTMARK_TOKEN'),
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
    |--------------------------------------------------------------------------
    | Lead capture integrations (PRD FR-INT-*)
    |--------------------------------------------------------------------------
    |
    | Credentials are per install and never committed. When a value is empty
    | the webhook endpoints answer 503 (verification) or skip signature
    | checking (local fixture tests only).
    |
    */

    /*
    | In-product assistant provider (ADR-0017). The key is a secret: environment only, never stored
    | in settings, returned by the API or logged. base_url exists for proxies and for tests; it is
    | deliberately not a database setting so an administrator cannot redirect the key elsewhere.
    */
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        // Page access token with leads retrieval permission (secret, env only).
        'access_token' => env('META_ACCESS_TOKEN'),
        'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    ],

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

];
