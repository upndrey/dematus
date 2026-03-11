<?php

namespace App\GraphQL\Queries;

use App\Enums\Stratz\Hero;
use App\Services\Stratz\StratzService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StratzQuery
{
    public function __construct(protected StratzService $stratzService) {}

    public function leagueMatches(mixed $_, array $args): array
    {
        $validator = Validator::make($args, [
            'leagueId' => ['required', 'integer', 'min:1'],
            'take' => ['nullable', 'integer', 'min:1', 'max:100'],
            'skip' => ['nullable', 'integer', 'min:0'],
        ]);
        $validated = $validator->validate();

        return $this->stratzService->getLeagueMatches(
            $validated['leagueId'],
            (int) ($validated['take'] ?? 20),
            (int) ($validated['skip'] ?? 0),
        );
    }

    public function match(mixed $_, array $args): array
    {
        $validator = Validator::make($args, [
            'matchId' => ['required', 'integer', 'min:1'],
        ]);
        $validated = $validator->validate();

        return $this->stratzService->getMatchById($validated['matchId']);
    }

    public function proPlayers(): array
    {
        return $this->stratzService->getProPlayers();
    }

    public function draft(mixed $_, array $args): array
    {
        $heroIds = array_map(static fn (Hero $hero): int => $hero->value, Hero::cases());

        $validator = Validator::make($args, [
            'input.matchId' => ['nullable', 'integer', 'min:1'],
            'input.gameMode' => ['nullable', 'integer', 'min:1'],
            'input.gameVersionId' => ['nullable', 'integer', 'min:1'],
            'input.bans' => ['nullable', 'array'],
            'input.bans.*' => ['integer', Rule::in($heroIds)],
            'input.players' => ['required', 'array', 'size:10'],
            'input.players.*.slot' => ['required', 'integer', 'between:0,9'],
            'input.players.*.heroId' => ['required', 'integer', Rule::in($heroIds)],
            'input.players.*.steamAccountId' => ['nullable', 'integer', 'min:1'],
        ]);
        $validated = $validator->validate();

        return $this->stratzService->getDraft($validated['input']);
    }
}
