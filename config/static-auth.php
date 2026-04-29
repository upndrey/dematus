<?php

return [
    'username' => env('STATIC_AUTH_USERNAME', 'admin'),
    'password_hash' => env('STATIC_AUTH_PASSWORD_HASH'),
    'session_key' => 'static_auth.authenticated',
];
