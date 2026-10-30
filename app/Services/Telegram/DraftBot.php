<?php

namespace App\Services\Telegram;

use App\Jobs\CalculateTelegramDraft;
use App\Models\TelegramDraft;
use App\Models\TelegramDraftDelivery;
use App\Models\TelegramSubscriber;
use App\Models\TelegramWatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class DraftBot
{
    public function __construct(private TelegramClient $telegram, private BetBoomDraftSource $source) {}

    public function authorized(string $chatId, string $userId, string $username = ''): bool
    {
        return ($userId !== '' && $userId === config('telegram.owner_id'))
            || ($username !== '' && in_array(strtolower($username), config('telegram.allowed_usernames', []), true))
            || in_array($chatId, config('telegram.allowed_chats'), true)
            || Cache::get('telegram:allowed:'.$chatId, false);
    }

    public function update(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);

            return;
        }
        $message = $update['message'] ?? [];
        $chatId = (string) data_get($message, 'chat.id', '');
        $userId = (string) data_get($message, 'from.id', '');
        if (! $this->authorized($chatId, $userId, (string) data_get($message, 'from.username', ''))) {
            return;
        }
        $text = trim($message['text'] ?? '');
        if (preg_match('~^/auto_off(?:@\w+)?$~', $text)) {
            TelegramSubscriber::query()->where('chat_id', $chatId)->update(['active' => false]);
            $this->telegram->send($chatId, 'Автоматические матчи отключены. /start — включить снова.');

            return;
        }
        if (preg_match('~^/allow(?:@\w+)?$~', $text) && $userId === config('telegram.owner_id')) {
            Cache::forever('telegram:allowed:'.$chatId, true);
            $this->telegram->send($chatId, 'Участники этого чата могут подтверждать драфты. Пришлите /watch и ссылку на матч BetBoom.');

            return;
        }
        if (preg_match('~^/stop(?:@\w+)?$~', $text)) {
            TelegramSubscriber::query()->where('chat_id', $chatId)->update(['active' => false]);
            TelegramWatch::query()->where('chat_id', $chatId)->update(['active' => false]);
            TelegramDraft::query()->where('chat_id', $chatId)->where('status', 'draft')->whereDoesntHave('deliveries')->update(['status' => 'stopped', 'message_hash' => null]);
            $this->telegram->send($chatId, 'Наблюдение за матчами остановлено.');

            return;
        }
        if (preg_match('~^/(start|help)(?:@\w+)?(?:\s.*)?$~', $text)) {
            if (preg_match('~^/start(?:@\w+)?(?:\s.*)?$~', $text)) {
                TelegramSubscriber::query()->updateOrCreate(['chat_id' => $chatId], ['active' => true]);
                $this->telegram->send($chatId, 'Автопоиск включён. Предупрежу за 10 минут до матча и пришлю составы. /auto_off — отключить. Первое подтверждение запускает общий расчёт для всех.');
            }
            $this->telegram->send($chatId, "Пришли ссылку на матч Dota 2 с BetBoom или /watch ссылка. Я дождусь выбора героев и пришлю составы.\n\nНажми на игрока, чтобы изменить позицию. После проверки нажми «Отправить в dematus». Расчёт сохранится в таблицу.\n\nРоли — предварительные, по статистике игроков. /stop останавливает наблюдение. В группе владелец может разрешить подтверждение всем командой /allow.");

            return;
        }
        try {
            $matchId = $this->source->matchId($text);
            $other = TelegramDraft::query()->where('match_id', $matchId)->where('chat_id', '!=', $chatId)->exists();
            if ($other) {
                throw new InvalidArgumentException('Этот матч уже обрабатывается в другом чате. Продолжите подтверждение там.');
            }
            TelegramDraft::query()->where('match_id', $matchId)->where('chat_id', $chatId)->where('status', 'stopped')->update(['status' => 'draft', 'message_hash' => null]);
            TelegramWatch::query()->updateOrCreate(['chat_id' => $chatId, 'match_id' => $matchId], ['active' => true, 'expires_at' => now()->addHours(12)]);
            $this->telegram->send($chatId, 'Наблюдаю за матчем '.$matchId.'. Когда герои будут привязаны к игрокам, пришлю составы для проверки. Если у матча нет трансляции драфта, получить составы не удастся.');
        } catch (InvalidArgumentException $error) {
            $this->telegram->send($chatId, $error->getMessage());
        }
    }

    public function ingest(string $chatId, array $payload): ?TelegramDraft
    {
        if (($payload['event'] ?? '') !== 'draft' || ! ctype_digit((string) ($payload['matchId'] ?? '')) || (int) ($payload['map'] ?? 0) < 1) {
            return null;
        }
        $players = $this->source->players($payload);
        $fingerprint = hash('sha256', json_encode(array_map(fn (array $p): array => array_diff_key($p, ['position' => true]), $players), JSON_THROW_ON_ERROR));
        $lock = Cache::lock('telegram:draft:'.$payload['matchId'].':'.$payload['map'], 90);

        return $lock->block(5, function () use ($chatId, $payload, $players, $fingerprint): ?TelegramDraft {
            $draft = TelegramDraft::query()->where('match_id', $payload['matchId'])->where('map_number', $payload['map'])->first();
            if ($draft && $draft->chat_id !== $chatId) {
                if (! TelegramWatch::query()->where('chat_id', $chatId)->where('match_id', $draft->match_id)->where('active', true)->exists()) {
                    return null;
                }
                TelegramDraftDelivery::query()->firstOrCreate(['telegram_draft_id' => $draft->id, 'chat_id' => $chatId]);
            }
            $seenAt = Carbon::createFromTimestampMs($payload['receivedAt']);
            if ($draft && $seenAt->lessThan($draft->seen_at)) {
                return $draft;
            }
            TelegramDraft::query()->where('match_id', $payload['matchId'])->where('map_number', '<', $payload['map'])->where('status', 'draft')->update(['status' => 'expired', 'message_hash' => null]);
            if (! $draft && ! $this->source->complete($players)) {
                return null;
            }
            if (! $draft) {
                $draft = TelegramDraft::query()->create(['chat_id' => $chatId, 'match_id' => $payload['matchId'], 'map_number' => $payload['map'], 'fingerprint' => $fingerprint, 'players' => $players, 'source_payload' => $payload, 'seen_at' => $seenAt, 'status' => 'draft', 'revision' => 1]);
            } else {
                $changed = $draft->fingerprint !== $fingerprint;
                if ($changed && in_array($draft->status, ['calculating', 'done', 'failed'], true) && ! $draft->source_changed) {
                    $draft->update(['source_changed' => true, 'message_hash' => null]);
                    $this->telegram->send($chatId, 'Состав карты '.$draft->map_number.' изменился после отправки. Расчёт мог использовать прежних героев; проверьте его вручную.');
                }
                $values = ['seen_at' => $seenAt, 'source_payload' => $payload];
                if ($changed && in_array($draft->status, ['draft', 'queued'], true)) {
                    $values += ['fingerprint' => $fingerprint, 'players' => $players, 'revision' => $draft->revision + 1, 'status' => 'draft', 'confirmed_at' => null, 'confirmed_by' => null, 'message_hash' => null];
                }
                $draft->update($values);
                if (! $changed && $draft->message_id && $draft->message_hash && ! $draft->deliveries()->whereNull('message_hash')->exists()) {
                    return $draft;
                }
            }
            $this->publish($draft);

            return $draft;
        });
    }

    public function deliver(TelegramDraft $draft): void
    {
        Cache::lock('telegram:draft:'.$draft->match_id.':'.$draft->map_number, 90)->block(5, function () use ($draft): void {
            $this->publish($draft->fresh());
        });
    }

    public function publish(TelegramDraft $draft, ?int $selected = null): void
    {
        $labels = [1 => 'Керри', 2 => 'Мид', 3 => 'Офлейн', 4 => 'Поддержка', 5 => 'Полная поддержка'];
        $lines = ['Карта '.$draft->map_number.' · BetBoom '.$draft->match_id];
        foreach (['radiant' => 'Radiant', 'dire' => 'Dire'] as $side => $label) {
            $team = array_filter($draft->players, fn (array $p): bool => $p['side'] === $side);
            $lines[] = "\n".($team ? reset($team)['team'] : $label).' ('.$label.')';
            uasort($team, fn (array $a, array $b): int => ($a['position'] ?? 9) <=> ($b['position'] ?? 9));
            foreach ($team as $player) {
                $position = $player['position'];
                $lines[] = ($position ? $position.'. '.$labels[$position] : 'Позиция не выбрана').': '.$player['name'].' — '.$player['hero'];
            }
        }
        $lines[] = "\n".match ($draft->status) {
            'queued', 'calculating' => 'Рассчитываю и сохраняю в таблицу…',
            'done' => 'Сохранено в таблицу. Строка '.data_get($draft->sheet_result, 'row', '').'.',
            'failed' => 'Не удалось завершить расчёт. Можно повторить отправку.',
            'stopped' => 'Наблюдение остановлено.',
            'expired' => 'Началась следующая карта. Это подтверждение устарело.',
            default => 'Проверь позиции: предложены по статистике игроков, могут отличаться от текущей карты.',
        };
        if ($draft->source_changed) {
            $lines[] = 'Состав изменился после подтверждения. Этот расчёт относится к ранее подтверждённым героям.';
        }
        $keyboard = [];
        $prefix = 'd:'.$draft->id.':'.$draft->revision.':';
        if ($draft->status === 'draft') {
            if ($selected !== null && isset($draft->players[$selected])) {
                $lines[] = 'Выбери позицию для '.$draft->players[$selected]['name'].'. Занятая позиция поменяется местами с текущей.';
                $keyboard[] = array_map(fn (int $p): array => ['text' => (string) $p, 'callback_data' => $prefix.'set:'.$selected.':'.$p], range(1, 5));
                $keyboard[] = [['text' => 'Назад', 'callback_data' => $prefix.'back']];
            } else {
                foreach (array_chunk(array_keys($draft->players), 2) as $indexes) {
                    $keyboard[] = array_map(fn (int $index): array => ['text' => $draft->players[$index]['name'].' · '.($draft->players[$index]['position'] ?? 'не выбрано'), 'callback_data' => $prefix.'edit:'.$index], $indexes);
                }
                $keyboard[] = [['text' => 'Отправить в dematus', 'callback_data' => $prefix.'confirm']];
            }
        } elseif ($draft->status === 'failed' && ! $draft->source_changed) {
            $keyboard[] = [['text' => 'Повторить сохранение', 'callback_data' => $prefix.'retry']];
        }
        $text = implode("\n", $lines);
        $hash = hash('sha256', json_encode([$text, $keyboard], JSON_THROW_ON_ERROR));
        $recipients = [['record' => $draft, 'chat_id' => $draft->chat_id]];
        foreach ($draft->deliveries as $delivery) {
            $recipients[] = ['record' => $delivery, 'chat_id' => $delivery->chat_id];
        }
        $failed = false;
        foreach ($recipients as $recipient) {
            $record = $recipient['record'];
            if ($record->message_id && $record->message_hash === $hash) {
                continue;
            }
            try {
                if ($record->message_id) {
                    $this->telegram->call('editMessageText', ['chat_id' => $recipient['chat_id'], 'message_id' => $record->message_id, 'text' => $text, 'reply_markup' => ['inline_keyboard' => $keyboard]]);
                } else {
                    $message = $this->telegram->send($recipient['chat_id'], $text, $keyboard);
                    $record->update(['message_id' => (string) $message['message_id']]);
                }
                $record->update(['message_hash' => $hash]);
            } catch (\Throwable $error) {
                $failed = true;
                if (count($recipients) === 1) {
                    throw $error;
                }
            }
        }
        if ($failed) {
            $draft->update(['message_hash' => null]);
        }
    }

    public function callback(array $callback): void
    {
        $chatId = (string) data_get($callback, 'message.chat.id', '');
        $userId = (string) data_get($callback, 'from.id', '');
        $answer = 'Нет доступа.';
        if ($this->authorized($chatId, $userId, (string) data_get($callback, 'from.username', ''))) {
            $parts = explode(':', $callback['data'] ?? '');
            if (count($parts) >= 4 && $parts[0] === 'd') {
                $draft = TelegramDraft::query()->find((int) $parts[1]);
                $delivery = $draft?->deliveries()->where('chat_id', $chatId)->where('message_id', (string) data_get($callback, 'message.message_id'))->first();
                if ($draft && (($draft->chat_id === $chatId && $draft->message_id === (string) data_get($callback, 'message.message_id')) || $delivery)) {
                    $answer = Cache::lock('telegram:draft:'.$draft->match_id.':'.$draft->map_number, 90)->block(5, function () use ($draft, $parts, $userId): string {
                        $draft->refresh();
                        if ($draft->revision !== (int) $parts[2]) {
                            return 'Состав изменился. Используй новые кнопки.';
                        }
                        $action = $parts[3];
                        if ($action === 'retry' && $draft->status === 'failed' && ! $draft->source_changed) {
                            $this->enqueue($draft, ['error' => null]);
                            $this->publish($draft);

                            return 'Повторяю сохранение подтверждённого состава.';
                        }
                        if ($draft->status !== 'draft') {
                            return 'Этот состав уже отправлен или устарел.';
                        }
                        if ($draft->seen_at->lt(now()->subSeconds(config('telegram.freshness_seconds')))) {
                            return 'Обновления задерживаются. Дождись восстановления связи.';
                        }
                        $index = filter_var($parts[4] ?? null, FILTER_VALIDATE_INT);
                        if ($action === 'edit' && $index !== false && isset($draft->players[$index])) {
                            $this->publish($draft, $index);

                            return 'Выбери позицию.';
                        }
                        if ($action === 'set' && $index !== false && isset($draft->players[$index])) {
                            $position = (int) ($parts[5] ?? 0);
                            if (! in_array($position, [1, 2, 3, 4, 5], true)) {
                                return 'Неизвестная позиция.';
                            }
                            $players = $draft->players;
                            $previous = $players[$index]['position'];
                            foreach ($players as $i => &$player) {
                                if ($i !== $index && $player['side'] === $players[$index]['side'] && $player['position'] === $position) {
                                    $player['position'] = $previous;
                                }
                            }
                            unset($player);
                            $players[$index]['position'] = $position;
                            $draft->update(['players' => $players, 'revision' => $draft->revision + 1, 'message_hash' => null]);
                        } elseif ($action === 'confirm') {
                            if (! $this->source->complete($draft->players, true)) {
                                return 'Укажи пять разных позиций в каждой команде.';
                            }
                            $this->enqueue($draft, ['confirmed_at' => now(), 'confirmed_by' => $userId]);
                        }
                        $this->publish($draft);

                        return $action === 'confirm' ? 'Состав отправлен в расчёт.' : 'Готово.';
                    });
                }
            }
        }
        $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => $answer]);
    }

    private function enqueue(TelegramDraft $draft, array $values): void
    {
        $draft->getConnection()->transaction(function () use ($draft, $values): void {
            $draft->update($values + ['status' => 'queued', 'message_hash' => null]);
            CalculateTelegramDraft::dispatch($draft->id, $draft->revision)->onConnection('telegram')->onQueue('telegram');
        });
    }
}
