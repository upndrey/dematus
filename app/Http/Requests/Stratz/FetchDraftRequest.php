<?php

namespace App\Http\Requests\Stratz;

use App\Enums\Stratz\Hero;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FetchDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $heroIds = array_map(static fn (Hero $hero): int => $hero->value, Hero::cases());

        return [
            'match_id' => ['nullable', 'integer', 'min:1'],
            'game_mode' => ['nullable', 'integer', 'min:1'],
            'game_version_id' => ['nullable', 'integer', 'min:1'],
            'bans' => ['nullable', 'string'],
            'hero_ids' => ['required', 'array', 'size:10'],
            'hero_ids.*' => ['required', 'integer', Rule::in($heroIds)],
            'player_ids' => ['required', 'array', 'size:10'],
            'player_ids.*' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'hero_ids.required' => 'Нужно выбрать 10 героев.',
            'hero_ids.size' => 'Должно быть выбрано ровно 10 героев.',
            'hero_ids.*.required' => 'Выберите героя в каждом из 10 слотов.',
            'hero_ids.*.in' => 'Выбранный герой отсутствует в enum героев.',
            'player_ids.required' => 'Нужно передать 10 полей player id.',
            'player_ids.size' => 'Должно быть 10 полей player id.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $heroIds = array_map(static fn (Hero $hero): int => $hero->value, Hero::cases());

            $bans = $this->parseBans();

            if ($bans === null) {
                $validator->errors()->add('bans', 'Поле bans должно быть списком id через запятую: например, 1,2,3.');

                return;
            }

            foreach ($bans as $heroId) {
                if (! in_array($heroId, $heroIds, true)) {
                    $validator->errors()->add('bans', "Hero id {$heroId} не найден в enum героев.");
                }
            }
        });
    }

    public function toDraftRequest(): array
    {
        $request = [];

        if ($this->filled('match_id')) {
            $request['matchId'] = $this->integer('match_id');
        }

        if ($this->filled('game_mode')) {
            $request['gameMode'] = $this->integer('game_mode');
        }

        if ($this->filled('game_version_id')) {
            $request['gameVersionId'] = $this->integer('game_version_id');
        }

        $bans = $this->parseBans();

        if (is_array($bans) && $bans !== []) {
            $request['bans'] = $bans;
        }

        $heroIds = array_values($this->input('hero_ids', []));
        $playerIds = array_values($this->input('player_ids', []));

        $request['players'] = [];

        for ($slot = 0; $slot < 10; $slot++) {
            $player = [
                'slot' => $slot,
                'heroId' => (int) $heroIds[$slot],
            ];

            if (isset($playerIds[$slot]) && $playerIds[$slot] !== null && $playerIds[$slot] !== '') {
                $player['steamAccountId'] = (int) $playerIds[$slot];
            }

            $request['players'][] = $player;
        }

        return $request;
    }

    protected function parseBans(): ?array
    {
        if (! $this->filled('bans')) {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', (string) $this->input('bans'))), static fn (string $value): bool => $value !== '');

        $bans = [];

        foreach ($parts as $part) {
            if (! ctype_digit($part)) {
                return null;
            }

            $bans[] = (int) $part;
        }

        return $bans;
    }
}
