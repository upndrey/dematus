<?php

namespace App\Http\Requests\Stratz;

use Illuminate\Foundation\Http\FormRequest;

class FetchRoshHtmlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'html' => ['required', 'string', 'min:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $html = $this->input('html');

        if (is_string($html)) {
            $this->merge([
                'html' => trim($html),
            ]);
        }
    }
}
