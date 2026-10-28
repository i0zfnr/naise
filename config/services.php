<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'naise' => [
        'catalog_url' => env('NAISE_CATALOG_URL'),
        'admin_email' => env('NAISE_ADMIN_EMAIL'),
        'admin_password_hash' => env('NAISE_ADMIN_PASSWORD_HASH'),
        'store_passcode_hash' => env('NAISE_STORE_PASSCODE_HASH'),
        'points_per_free_drink' => (int) env('NAISE_POINTS_PER_FREE_DRINK', 100),
        'points_per_ringgit' => (int) env('NAISE_POINTS_PER_RINGGIT', 1),
        'payment_webhook_secret' => env('NAISE_PAYMENT_WEBHOOK_SECRET'),
        'payment_provider' => env('NAISE_PAYMENT_PROVIDER', 'acquirer'),
        'payment_webhook_tolerance_seconds' => (int) env('NAISE_PAYMENT_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'sender_email' => env('BREVO_SENDER_EMAIL'),
        'sender_name' => env('BREVO_SENDER_NAME', env('APP_NAME', 'Naise Coffee')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
