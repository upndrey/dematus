<?php

namespace App\Http\Requests\Stratz;

use Illuminate\Foundation\Http\FormRequest;

class StoreStratzImportBatchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'match_ids' => ['required', 'array', 'min:1', 'max:500'],
            'match_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'tournament' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'match_ids.required' => 'Вставьте хотя бы один MatchID.',
            'match_ids.max' => 'В одном пакете можно импортировать не более 500 матчей.',
        ];
    }
}
