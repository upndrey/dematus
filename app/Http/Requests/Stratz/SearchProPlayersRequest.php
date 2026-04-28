<?php

namespace App\Http\Requests\Stratz;

use Illuminate\Foundation\Http\FormRequest;

class SearchProPlayersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:80'],
            'take' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'query.required' => 'Укажите никнейм про-игрока для поиска.',
            'query.min' => 'Никнейм должен содержать минимум 2 символа.',
            'query.max' => 'Никнейм не должен быть длиннее 80 символов.',
            'take.integer' => 'Лимит результатов должен быть целым числом.',
            'take.min' => 'Лимит результатов должен быть не меньше 1.',
            'take.max' => 'За один запрос можно получить не больше 10 кандидатов.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $query = $this->input('query');

        $this->merge([
            'query' => is_string($query)
                ? trim($query)
                : $query,
        ]);
    }

    public function searchQuery(): string
    {
        return (string) $this->validated('query');
    }

    public function resultLimit(): int
    {
        return (int) $this->validated('take', 5);
    }
}
