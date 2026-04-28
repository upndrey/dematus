<?php

namespace App\Http\Requests\Stratz;

use App\Services\Stratz\TeamRosterRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTeamRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'players' => ['required', 'array', 'size:5'],
            'players.*' => ['nullable', 'array:steam_account_id,name,pro_name,is_anonymous,is_stratz_public,team_name'],
            'players.*.steam_account_id' => ['nullable', 'integer', 'min:1'],
            'players.*.name' => ['nullable', 'string', 'max:120'],
            'players.*.pro_name' => ['nullable', 'string', 'max:120'],
            'players.*.is_anonymous' => ['nullable', 'boolean'],
            'players.*.is_stratz_public' => ['nullable', 'boolean'],
            'players.*.team_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название команды.',
            'players.required' => 'Передайте список игроков команды.',
            'players.size' => 'Команда должна содержать ровно 5 слотов игроков.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim((string) $this->input('name')) : $this->input('name'),
            'players' => $this->normalizePlayers((array) $this->input('players', [])),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var TeamRosterRepository $repository */
            $repository = app(TeamRosterRepository::class);

            if (is_string($this->input('name')) && $repository->existsWithName((string) $this->input('name'))) {
                $validator->errors()->add('name', 'Команда с таким названием уже сохранена.');
            }

            $this->validatePlayerSlots($validator);
        });
    }

    /**
     * @param  list<mixed>  $players
     * @return list<array<string, mixed>|null>
     */
    private function normalizePlayers(array $players): array
    {
        $normalizedPlayers = array_map(function (mixed $player): ?array {
            if (! is_array($player)) {
                return null;
            }

            return [
                'steam_account_id' => $player['steam_account_id'] ?? null,
                'name' => isset($player['name']) && is_string($player['name']) ? trim($player['name']) : null,
                'pro_name' => isset($player['pro_name']) && is_string($player['pro_name']) ? trim($player['pro_name']) : null,
                'is_anonymous' => array_key_exists('is_anonymous', $player)
                    ? filter_var($player['is_anonymous'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                    : null,
                'is_stratz_public' => array_key_exists('is_stratz_public', $player)
                    ? filter_var($player['is_stratz_public'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                    : null,
                'team_name' => isset($player['team_name']) && is_string($player['team_name']) ? trim($player['team_name']) : null,
            ];
        }, array_slice($players, 0, 5));

        return array_pad($normalizedPlayers, 5, null);
    }

    private function validatePlayerSlots(Validator $validator): void
    {
        foreach ((array) $this->input('players', []) as $index => $player) {
            if ($player === null) {
                continue;
            }

            if (! is_array($player)) {
                $validator->errors()->add(
                    "players.{$index}",
                    'Каждый слот команды должен содержать либо пустое значение, либо игрока из Liquipedia.',
                );

                continue;
            }

            if (! is_numeric(data_get($player, 'steam_account_id'))) {
                $validator->errors()->add(
                    "players.{$index}.steam_account_id",
                    'Для заполненного слота нужно выбрать игрока из списка Liquipedia.',
                );
            }
        }
    }
}
