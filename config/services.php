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
        'team_rosters_path' => env('STRATZ_TEAM_ROSTERS_PATH', resource_path('data/stratz-teams.json')),
    ],

    'liquipedia' => [
        'endpoint' => env('LIQUIPEDIA_ENDPOINT', 'https://liquipedia.net/dota2/api.php'),
        'timeout' => (int) env('LIQUIPEDIA_TIMEOUT', 15),
        'user_agent' => env('LIQUIPEDIA_USER_AGENT', 'dematus-liquipedia/1.0 (contact: local-tool)'),
        'search_cache_seconds' => (int) env('LIQUIPEDIA_SEARCH_CACHE_SECONDS', 21600),
        'page_cache_seconds' => (int) env('LIQUIPEDIA_PAGE_CACHE_SECONDS', 86400),
    ],

    'opendota' => [
        'pro_players_endpoint' => env('OPENDOTA_PRO_PLAYERS_ENDPOINT', 'https://api.opendota.com/api/proPlayers'),
        'timeout' => (int) env('OPENDOTA_TIMEOUT', 10),
        'pro_players_cache_seconds' => (int) env('OPENDOTA_PRO_PLAYERS_CACHE_SECONDS', 900),
    ],

    'google_sheets' => [
        'spreadsheet_url' => env('GOOGLE_SHEETS_ROSH_SPREADSHEET_URL'),
        'service_account_credentials' => env('GOOGLE_SHEETS_SERVICE_ACCOUNT_CREDENTIALS'),
        'timeout' => (int) env('GOOGLE_SHEETS_TIMEOUT', 20),
    ],

    'dltv' => [
        'gist_url' => env('DLTV_GIST_URL'),
        'extension_token' => env('DLTV_EXTENSION_TOKEN'),
        'timeout' => (int) env('DLTV_TIMEOUT', 20),
    ],

];
