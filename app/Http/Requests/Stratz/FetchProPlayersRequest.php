<?php

namespace App\Http\Requests\Stratz;

use Illuminate\Foundation\Http\FormRequest;

class FetchProPlayersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
