<?php

namespace App\Services\Telegram;

use App\Models\TelegramWatch;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TelegramClient
{
    public function call(string $method, array $payload = []): mixed
    {
        if (in_array($method, ['sendMessage', 'editMessageText'], true)) {
            $payload = $this->matchPresentation($payload);
        }
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

    public function matchPresentation(array $payload): array
    {
        $text = $payload['text'] ?? '';
        preg_match_all('/(?:BetBoom |матч(?:а|ем|у)? )(\d+)/u', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$matchId, $offset]) {
            $watch = TelegramWatch::query()->where('match_id', $matchId)->whereNotNull('tournament_id')->first();
            if ($watch) {
                $payload['entities'][] = ['type' => 'text_link', 'offset' => strlen(mb_convert_encoding(substr($text, 0, $offset), 'UTF-16LE', 'UTF-8')) / 2, 'length' => strlen($matchId), 'url' => 'https://betboom.ru/esport/dota-2/'.$watch->tournament_id.'/'.$matchId];
            }
        }
        foreach ($payload['reply_markup']['inline_keyboard'] ?? [] as $index => $row) {
            foreach ($row as $button) {
                if (preg_match('/^ignore:(\d+)$/', $button['callback_data'] ?? '', $match)) {
                    $watch = TelegramWatch::query()->where('match_id', $match[1])->whereNotNull('tournament_id')->first();
                    if ($watch) {
                        $payload['reply_markup']['inline_keyboard'][$index][] = ['text' => 'Игнорировать турнир', 'callback_data' => 'ignore_tournament:'.$watch->tournament_id];
                    }
                }
            }
        }
        $payload['link_preview_options'] = ['is_disabled' => true];

        return $payload;
    }

    public function send(string $chatId, string $text, array $keyboard = []): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => ['inline_keyboard' => $keyboard], 'link_preview_options' => ['is_disabled' => true]]);
    }
}
