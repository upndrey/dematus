<?php

namespace App\Http\Requests\Stratz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class FetchDltvExtensionRoshRequest extends FormRequest
{
    public function authorize(): bool
    {
        $configuredToken = config('services.dltv.extension_token');

        if (! is_string($configuredToken) || $configuredToken === '') {
            return false;
        }

        $providedToken = $this->bearerToken()
            ?? $this->header('X-DLTV-Parser-Token')
            ?? $this->query('token');

        return is_string($providedToken) && hash_equals($configuredToken, $providedToken);
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'in:dltv'],
            'page_url' => ['required', 'url', 'max:2048'],
            'captured_at' => ['nullable', 'date'],
            'match' => ['required', 'array'],
            'match.team_1' => ['nullable', 'string', 'max:120'],
            'match.team_2' => ['nullable', 'string', 'max:120'],
            'match.radiant_team' => ['nullable', 'string', 'max:120'],
            'match.dire_team' => ['nullable', 'string', 'max:120'],
            'match.map_number' => ['nullable', 'integer', 'min:1'],
            'match.game_time' => ['nullable', 'string', 'max:20'],
            'match.score' => ['nullable', 'string', 'max:20'],
            'players' => ['required', 'array', 'size:10'],
            'players.*' => ['required', 'array'],
            'players.*.team_index' => ['required', 'integer', 'in:1,2'],
            'players.*.player_name' => ['nullable', 'string', 'max:120'],
            'players.*.player_url' => ['nullable', 'url', 'max:2048'],
            'players.*.hero_name' => ['required', 'string', 'max:120'],
            'players.*.hero_image_url' => ['nullable', 'url', 'max:2048'],
            'players.*.role_id' => ['required', 'integer', 'between:1,5'],
            'players.*.role_name' => ['nullable', 'string', 'max:40'],
            'players.*.level' => ['nullable', 'integer', 'min:1', 'max:30'],
            'teams' => ['required', 'array', 'size:2'],
            'teams.*' => ['required', 'array'],
            'teams.*.team_index' => ['required', 'integer', 'in:1,2'],
            'teams.*.team_name' => ['nullable', 'string', 'max:120'],
            'teams.*.players' => ['required', 'array', 'size:5'],
            'meta' => ['nullable', 'array'],
            'meta.players_count' => ['nullable', 'integer', 'min:0'],
            'meta.heroes_count' => ['nullable', 'integer', 'min:0'],
            'meta.roles_count' => ['nullable', 'integer', 'min:0'],
            'meta.warnings' => ['nullable', 'array'],
            'meta.warnings.*' => ['string', 'max:500'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw ValidationException::withMessages([
            'token' => 'The DLTV extension token is invalid or not configured.',
        ]);
    }
}
