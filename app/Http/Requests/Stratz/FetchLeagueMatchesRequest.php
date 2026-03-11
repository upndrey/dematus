<?php

namespace App\Http\Requests\Stratz;

use Illuminate\Foundation\Http\FormRequest;

class FetchLeagueMatchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'league_id' => ['required', 'integer', 'min:1'],
            'take' => ['nullable', 'integer', 'min:1', 'max:100'],
            'skip' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'league_id.required' => 'Укажите league id.',
            'league_id.integer' => 'League id должен быть целым числом.',
        ];
    }
}
