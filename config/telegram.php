<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'owner_id' => (string) env('TELEGRAM_OWNER_ID', ''),
    'allowed_usernames' => array_values(array_filter(array_map(
        fn (string $username): string => strtolower(ltrim(trim($username), '@')),
        explode(',', (string) env('TELEGRAM_ALLOWED_USERNAMES', '')),
    ))),
    'allowed_chats' => array_filter(explode(',', (string) env('TELEGRAM_ALLOWED_CHATS', ''))),
    'node_binary' => env('TELEGRAM_NODE_BINARY', 'node'),
    'proxy' => env('TELEGRAM_PROXY'),
    'freshness_seconds' => 60,
];
