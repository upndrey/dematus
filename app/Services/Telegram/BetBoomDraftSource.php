<?php

namespace App\Services\Telegram;

use App\Enums\Stratz\Hero;
use InvalidArgumentException;

class BetBoomDraftSource
{
    public function matchId(string $text): string
    {
        if (! preg_match('~https://(?:www\\.)?betboom\\.ru/(?:esport|sport)/dota-2/\\d+/(\\d+)(?:[/?#\\s]|$)~i', $text, $match)) {
            throw new InvalidArgumentException('Пришли ссылку на матч Dota 2 с сайта betboom.ru.');
        }

        return $match[1];
    }

    public function players(array $payload): array
    {
        $heroes = [];
        foreach (Hero::cases() as $hero) {
            $heroes[$this->normalize($hero->title())] = $hero;
            $heroes[$this->normalize($hero->name)] = $hero;
        }
        $players = [];
        foreach ($payload['players'] ?? [] as $player) {
            $hero = $heroes[$this->normalize($player['hero'] ?? '')] ?? null;
            if (! $hero || ! in_array($player['side'] ?? '', ['radiant', 'dire'], true)) {
                continue;
            }
            $position = match ($player['role'] ?? '') {
                'SAFE_LANER' => 1, 'MID_LANER' => 2, 'OFF_LANER' => 3, 'SOFT_SUPPORT' => 4, 'HARD_SUPPORT' => 5, default => null,
            };
            $players[] = ['id' => (string) $player['playerId'], 'name' => mb_substr($player['playerName'] ?? $player['playerId'], 0, 60), 'side' => $player['side'], 'team' => mb_substr($player['teamName'] ?? $player['teamId'], 0, 100), 'hero_id' => $hero->value, 'hero' => $hero->title(), 'position' => $position];
        }
        usort($players, fn (array $a, array $b): int => [$a['side'], $a['id']] <=> [$b['side'], $b['id']]);
        $duplicatePositions = [];
        foreach ($players as $player) {
            if ($player['position'] !== null && count(array_filter($players, fn (array $other): bool => $other['side'] === $player['side'] && $other['position'] === $player['position'])) > 1) {
                $duplicatePositions[$player['side']][$player['position']] = true;
            }
        }
        foreach ($players as &$player) {
            if (isset($duplicatePositions[$player['side']][$player['position']])) {
                $player['position'] = null;
            }
        }

        return $players;
    }

    public function complete(array $players, bool $requirePositions = false): bool
    {
        if (count($players) !== 10 || count(array_unique(array_column($players, 'hero_id'))) !== 10 || count(array_unique(array_column($players, 'id'))) !== 10) {
            return false;
        }
        foreach (['radiant', 'dire'] as $side) {
            $team = array_values(array_filter($players, fn (array $p): bool => $p['side'] === $side));
            if (count($team) !== 5) {
                return false;
            }
            $positions = array_column($team, 'position');
            sort($positions);
            if ($requirePositions && $positions !== [1, 2, 3, 4, 5]) {
                return false;
            }
        }

        return true;
    }

    public function calculationPayload(array $players): array
    {
        if (! $this->complete($players, true)) {
            throw new InvalidArgumentException('Для каждой команды нужны позиции от 1 до 5 без повторов.');
        }
        $payload = [];
        foreach (['radiant', 'dire'] as $side) {
            $team = array_values(array_filter($players, fn (array $p): bool => $p['side'] === $side));
            usort($team, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
            $payload[$side.'_team'] = $team[0]['team'];
            $payload[$side.'_heroes'] = array_column($team, 'hero_id');
        }

        return $payload;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }
}
