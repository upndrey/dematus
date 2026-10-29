<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TelegramClient
{
    public function call(string $method, array $payload = []): mixed
    {
        $token = (string) config('telegram.token');
        if ($token === '') {
            throw new RuntimeException('Telegram token is not configured.');
        }
        try {
            $request = Http::connectTimeout(5)->timeout($method === 'getUpdates' ? 40 : 10);
            if (config('telegram.proxy')) {
                $request = $request->withOptions(['proxy' => config('telegram.proxy')]);
            }
            $response = $request->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (Throwable) {
            throw new RuntimeException('Telegram connection failed.');
        }
        $body = $response->json();
        if (! ($body['ok'] ?? false)) {
            if ($method === 'answerCallbackQuery' && (str_contains($body['description'] ?? '', 'query is too old') || str_contains($body['description'] ?? '', 'query ID is invalid'))) {
                return true;
            }
            if ($method === 'editMessageText' && str_contains($body['description'] ?? '', 'message is not modified')) {
                return true;
            }
            throw new RuntimeException('Telegram request failed: '.($body['error_code'] ?? $response->status()));
        }

        return $body['result'];
    }

    public function send(string $chatId, string $text, array $keyboard = []): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => ['inline_keyboard' => $keyboard], 'link_preview_options' => ['is_disabled' => true]]);
    }
}
