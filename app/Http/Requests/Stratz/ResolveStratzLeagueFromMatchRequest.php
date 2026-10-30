<?php

namespace App\Http\Requests\Stratz;

use Illuminate\Foundation\Http\FormRequest;

class ResolveStratzLeagueFromMatchRequest extends FormRequest
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
        return ['match_id' => ['required', 'integer', 'min:1']];
    }
}
