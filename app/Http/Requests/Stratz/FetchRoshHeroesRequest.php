<?php

namespace App\Http\Requests\Stratz;

use App\Enums\Stratz\Hero;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FetchRoshHeroesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $heroIds = array_map(
            static fn (Hero $hero): int => $hero->value,
            Hero::cases(),
        );
        $shouldConsiderPlayers = $this->boolean('consider_players');

        return [
            'radiant_team' => ['required', 'string', 'max:120'],
            'dire_team' => ['required', 'string', 'max:120'],
            'consider_players' => ['boolean'],
            'radiant_heroes' => ['required', 'array', 'size:5'],
            'radiant_heroes.*' => ['required', 'integer', Rule::in($heroIds)],
            'dire_heroes' => ['required', 'array', 'size:5'],
            'dire_heroes.*' => ['required', 'integer', Rule::in($heroIds)],
            'radiant_players' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'required_if:consider_players,true',
                'array',
                'size:5',
            ],
            'radiant_players.*' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'array:steam_account_id,name,pro_name,is_anonymous,is_stratz_public,team_name',
            ],
            'radiant_players.*.steam_account_id' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'integer',
                'min:1',
            ],
            'radiant_players.*.name' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'string',
                'max:120',
            ],
            'radiant_players.*.pro_name' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'string',
                'max:120',
            ],
            'radiant_players.*.is_anonymous' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'boolean',
            ],
            'radiant_players.*.is_stratz_public' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'boolean',
            ],
            'radiant_players.*.team_name' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'string',
                'max:120',
            ],
            'dire_players' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'required_if:consider_players,true',
                'array',
                'size:5',
            ],
            'dire_players.*' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'array:steam_account_id,name,pro_name,is_anonymous,is_stratz_public,team_name',
            ],
            'dire_players.*.steam_account_id' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'integer',
                'min:1',
            ],
            'dire_players.*.name' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'string',
                'max:120',
            ],
            'dire_players.*.pro_name' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'string',
                'max:120',
            ],
            'dire_players.*.is_anonymous' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'boolean',
            ],
            'dire_players.*.is_stratz_public' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'boolean',
            ],
            'dire_players.*.team_name' => [
                Rule::excludeIf(! $shouldConsiderPlayers),
                'nullable',
                'string',
                'max:120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'radiant_team.required' => 'Укажите название команды Radiant.',
            'dire_team.required' => 'Укажите название команды Dire.',
            'consider_players.boolean' => 'Флаг учета про-игроков должен быть boolean-значением.',
            'radiant_heroes.required' => 'Заполните всех героев Radiant.',
            'radiant_heroes.size' => 'Для Radiant нужно выбрать ровно 5 героев.',
            'radiant_heroes.*.required' => 'Выберите героя для каждого слота Radiant.',
            'radiant_heroes.*.integer' => 'Герой Radiant должен быть передан как корректный hero id.',
            'radiant_heroes.*.in' => 'Один из героев Radiant отсутствует в списке STRATZ.',
            'dire_heroes.required' => 'Заполните всех героев Dire.',
            'dire_heroes.size' => 'Для Dire нужно выбрать ровно 5 героев.',
            'dire_heroes.*.required' => 'Выберите героя для каждого слота Dire.',
            'dire_heroes.*.integer' => 'Герой Dire должен быть передан как корректный hero id.',
            'dire_heroes.*.in' => 'Один из героев Dire отсутствует в списке STRATZ.',
            'radiant_players.required_if' => 'Для режима с про-игроками нужно передать 5 слотов Radiant.',
            'radiant_players.size' => 'Для режима с про-игроками Radiant должен содержать ровно 5 слотов.',
            'dire_players.required_if' => 'Для режима с про-игроками нужно передать 5 слотов Dire.',
            'dire_players.size' => 'Для режима с про-игроками Dire должен содержать ровно 5 слотов.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'radiant_team' => is_string($this->input('radiant_team'))
                ? trim((string) $this->string('radiant_team'))
                : $this->input('radiant_team'),
            'dire_team' => is_string($this->input('dire_team'))
                ? trim((string) $this->string('dire_team'))
                : $this->input('dire_team'),
            'consider_players' => $this->boolean('consider_players'),
            'radiant_players' => $this->normalizePlayers((array) $this->input('radiant_players', [])),
            'dire_players' => $this->normalizePlayers((array) $this->input('dire_players', [])),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $radiantHeroes = array_map('intval', (array) $this->input('radiant_heroes', []));
            $direHeroes = array_map('intval', (array) $this->input('dire_heroes', []));
            $allHeroes = array_merge($radiantHeroes, $direHeroes);

            if (count($allHeroes) === 10 && count(array_unique($allHeroes)) !== 10) {
                $validator->errors()->add(
                    'dire_heroes',
                    'В одном драфте не должно быть повторяющихся героев.',
                );
            }

            if (! $this->boolean('consider_players')) {
                return;
            }

            $this->validatePlayerSlots($validator, 'radiant_players', 'Radiant');
            $this->validatePlayerSlots($validator, 'dire_players', 'Dire');
        });
    }

    /**
     * @param  list<mixed>  $players
     * @return list<array<string, mixed>|null>
     */
    private function normalizePlayers(array $players): array
    {
        return array_map(function (mixed $player): ?array {
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
        }, $players);
    }

    private function validatePlayerSlots(Validator $validator, string $key, string $side): void
    {
        foreach ((array) $this->input($key, []) as $index => $player) {
            if ($player === null) {
                continue;
            }

            if (! is_array($player)) {
                $validator->errors()->add(
                    "{$key}.{$index}",
                    "Слот {$side} #".($index + 1).' должен содержать данные про-игрока из Liquipedia.',
                );

                continue;
            }

            if (! is_numeric(data_get($player, 'steam_account_id'))) {
                $validator->errors()->add(
                    "{$key}.{$index}.steam_account_id",
                    "Для слота {$side} #".($index + 1).' нужно выбрать игрока из списка Liquipedia.',
                );
            }
        }
    }
}
