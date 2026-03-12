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

    'stratz' => [
        'endpoint' => env('STRATZ_ENDPOINT', 'https://api.stratz.com/graphql'),
        'token' => env('STRATZ_TOKEN'),
        'timeout' => (int) env('STRATZ_TIMEOUT', 20),
    ],

    'google_sheets' => [
        'spreadsheet_url' => env('GOOGLE_SHEETS_ROSH_SPREADSHEET_URL'),
        'service_account_credentials' => env('GOOGLE_SHEETS_SERVICE_ACCOUNT_CREDENTIALS'),
        'timeout' => (int) env('GOOGLE_SHEETS_TIMEOUT', 20),
    ],

];
