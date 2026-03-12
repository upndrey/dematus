<?php

namespace App\Http\Requests\Stratz;

use Illuminate\Foundation\Http\FormRequest;

class FetchRoshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'match_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'match_id.required' => 'Укажите match id для ROSH.',
            'match_id.integer' => 'Match id должен быть целым числом.',
            'match_id.min' => 'Match id должен быть больше нуля.',
        ];
    }
}
