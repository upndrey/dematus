<?php

namespace App\Console\Commands;

use App\Services\Liquipedia\LiquipediaService;
use App\Services\Stratz\TeamRosterRepository;
use Illuminate\Console\Command;
use RuntimeException;

class StratzCreateTeamRosterCommand extends Command
{
    protected $signature = 'app:stratz-team-roster:create
        {name : Saved team name}
        {carry : Carry player nickname}
        {mid : Mid player nickname}
        {offlane : Offlane player nickname}
        {support4 : Position 4 player nickname}
        {support5 : Position 5 player nickname}
        {--replace : Replace an existing saved team with the same name}';

    protected $description = 'Create a saved STRATZ roster from five Liquipedia player nicknames';

    /**
     * @var array<int, string>
     */
    private array $roleLabels = [
        1 => 'Carry',
        2 => 'Mid',
        3 => 'Offlane',
        4 => 'Support 4',
        5 => 'Support 5',
    ];

    public function __construct(
        protected LiquipediaService $liquipediaService,
        protected TeamRosterRepository $teamRosterRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $teamName = trim((string) $this->argument('name'));

        if ($teamName === '') {
            $this->error('Team name cannot be empty.');

            return self::FAILURE;
        }

        try {
            $players = $this->resolvePlayers();
            $existingTeam = $this->teamRosterRepository->findByName($teamName);

            if ($existingTeam !== null && ! $this->option('replace')) {
                $this->error("Saved team [{$teamName}] already exists. Re-run with --replace to update it.");

                return self::FAILURE;
            }

            $payload = [
                'name' => $teamName,
                'players' => array_values(array_map(
                    static fn (array $player): array => [
                        'steam_account_id' => $player['steam_account_id'],
                        'name' => $player['name'],
                        'pro_name' => $player['pro_name'],
                        'is_anonymous' => $player['is_anonymous'],
                        'is_stratz_public' => $player['is_stratz_public'],
                        'team_name' => data_get($player, 'team.name'),
                    ],
                    $players,
                )),
            ];

            $savedTeam = $existingTeam !== null
                ? $this->teamRosterRepository->update($existingTeam['slug'], $payload)
                : $this->teamRosterRepository->create($payload);

            $this->info(($existingTeam !== null ? 'Updated' : 'Created')." saved roster [{$savedTeam['name']}] ({$savedTeam['slug']}).");
            $this->newLine();
            $this->table(
                ['Role', 'Query', 'Resolved', 'Steam Account ID'],
                array_map(
                    fn (array $row): array => [
                        $row['role'],
                        $row['query'],
                        $row['resolved'],
                        (string) $row['steam_account_id'],
                    ],
                    $this->buildResolvedRows($players),
                ),
            );

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return list<array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int|null, name:string}|null
     * }>
     */
    private function resolvePlayers(): array
    {
        $players = [];

        foreach ($this->roleLabels as $position => $label) {
            $query = trim((string) $this->argument($this->argumentNameForPosition($position)));
            $players[] = $this->resolvePlayerFromNickname($query, $label);
        }

        return $players;
    }

    /**
     * @param  list<array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int|null, name:string}|null
     * }>  $players
     * @return list<array{role:string, query:string, resolved:string, steam_account_id:int}>
     */
    private function buildResolvedRows(array $players): array
    {
        $rows = [];

        foreach ($players as $index => $player) {
            $position = $index + 1;
            $rows[] = [
                'role' => $this->roleLabels[$position],
                'query' => trim((string) $this->argument($this->argumentNameForPosition($position))),
                'resolved' => $this->displayName($player),
                'steam_account_id' => $player['steam_account_id'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int|null, name:string}|null
     * }
     */
    private function resolvePlayerFromNickname(string $query, string $roleLabel): array
    {
        if ($query === '') {
            throw new RuntimeException("Nickname for {$roleLabel} cannot be empty.");
        }

        $candidates = $this->liquipediaService->searchProPlayers($query, 8);

        if ($candidates === []) {
            throw new RuntimeException("Liquipedia did not find a pro player for {$roleLabel} query [{$query}].");
        }

        $rankedCandidates = [];

        foreach ($candidates as $index => $candidate) {
            $rankedCandidates[] = [
                'score' => $this->scoreCandidate($candidate, $query),
                'index' => $index,
                'player' => $candidate,
            ];
        }

        usort($rankedCandidates, static function (array $left, array $right): int {
            return [
                $right['score'],
                $left['index'],
            ] <=> [
                $left['score'],
                $right['index'],
            ];
        });

        $bestCandidate = $rankedCandidates[0] ?? null;

        if ($bestCandidate === null || $bestCandidate['score'] <= 0) {
            throw new RuntimeException("Liquipedia candidates for {$roleLabel} query [{$query}] were too weak to resolve automatically.");
        }

        $nextCandidate = $rankedCandidates[1] ?? null;

        if ($nextCandidate !== null && $nextCandidate['score'] === $bestCandidate['score']) {
            $players = [
                $this->displayName($bestCandidate['player']),
                $this->displayName($nextCandidate['player']),
            ];

            throw new RuntimeException(
                "Liquipedia query [{$query}] for {$roleLabel} is ambiguous. Top candidates: ".implode(', ', $players).'.',
            );
        }

        return $bestCandidate['player'];
    }

    /**
     * @param  array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int|null, name:string}|null
     * }  $player
     */
    private function scoreCandidate(array $player, string $query): int
    {
        $normalizedQuery = $this->normalizeQuery($query);
        $bestScore = 0;

        $bestScore = max($bestScore, $this->scoreTextMatch($this->normalizeQuery((string) ($player['pro_name'] ?? '')), $normalizedQuery, 1200, 900, 650, 420));
        $bestScore = max($bestScore, $this->scoreTextMatch($this->normalizeQuery($player['name']), $normalizedQuery, 1050, 820, 600, 380));

        foreach ($player['aliases'] as $alias) {
            $bestScore = max(
                $bestScore,
                $this->scoreTextMatch($this->normalizeQuery($alias), $normalizedQuery, 1100, 860, 620, 400),
            );
        }

        return $bestScore;
    }

    private function scoreTextMatch(
        string $haystack,
        string $query,
        int $exactScore,
        int $prefixScore,
        int $wordPrefixScore,
        int $containsScore,
    ): int {
        if ($haystack === '' || $query === '') {
            return 0;
        }

        if ($haystack === $query) {
            return $exactScore;
        }

        if (str_starts_with($haystack, $query)) {
            return $prefixScore;
        }

        foreach (preg_split('/\s+/', $haystack) ?: [] as $token) {
            if ($token !== '' && str_starts_with($token, $query)) {
                return $wordPrefixScore;
            }
        }

        if (str_contains($haystack, $query)) {
            return $containsScore;
        }

        return 0;
    }

    private function argumentNameForPosition(int $position): string
    {
        return match ($position) {
            1 => 'carry',
            2 => 'mid',
            3 => 'offlane',
            4 => 'support4',
            5 => 'support5',
            default => throw new RuntimeException("Unsupported roster position [{$position}]."),
        };
    }

    /**
     * @param  array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int|null, name:string}|null
     * }  $player
     */
    private function displayName(array $player): string
    {
        return trim((string) ($player['pro_name'] ?? '')) !== ''
            ? (string) $player['pro_name']
            : $player['name'];
    }

    private function normalizeQuery(string $value): string
    {
        $normalizedValue = function_exists('mb_strtolower')
            ? mb_strtolower($value)
            : strtolower($value);

        $normalizedValue = preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($normalizedValue)) ?? $normalizedValue;
        $normalizedValue = preg_replace('/\s+/', ' ', $normalizedValue) ?? $normalizedValue;

        return trim($normalizedValue);
    }
}
