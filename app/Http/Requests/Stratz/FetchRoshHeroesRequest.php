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

        return [
            'radiant_team' => ['required', 'string', 'max:120'],
            'dire_team' => ['required', 'string', 'max:120'],
            'radiant_heroes' => ['required', 'array', 'size:5'],
            'radiant_heroes.*' => ['required', 'integer', Rule::in($heroIds)],
            'dire_heroes' => ['required', 'array', 'size:5'],
            'dire_heroes.*' => ['required', 'integer', Rule::in($heroIds)],
        ];
    }

    public function messages(): array
    {
        return [
            'radiant_team.required' => 'Укажите название команды Radiant.',
            'dire_team.required' => 'Укажите название команды Dire.',
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
        });
    }
}
