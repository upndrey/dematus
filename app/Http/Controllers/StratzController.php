<?php

namespace App\Http\Controllers;

use App\Enums\Stratz\Hero;
use App\Exceptions\ExternalHttpRequestException;
use App\Http\Requests\Stratz\FetchDltvExtensionRoshRequest;
use App\Http\Requests\Stratz\FetchDraftRequest;
use App\Http\Requests\Stratz\FetchLeagueMatchesRequest;
use App\Http\Requests\Stratz\FetchMatchRequest;
use App\Http\Requests\Stratz\FetchProPlayersRequest;
use App\Http\Requests\Stratz\FetchRoshHeroesRequest;
use App\Http\Requests\Stratz\FetchRoshHtmlRequest;
use App\Http\Requests\Stratz\FetchRoshRequest;
use App\Http\Requests\Stratz\SearchProPlayersRequest;
use App\Http\Requests\Stratz\StoreTeamRosterRequest;
use App\Http\Requests\Stratz\UpdateTeamRosterRequest;
use App\Models\DraftSnapshot;
use App\Services\Dltv\DltvExtensionPayloadParser;
use App\Services\Dltv\DltvGistHtmlFetcher;
use App\Services\Dltv\DltvMatchHtmlParser;
use App\Services\DraftSnapshots\DraftSnapshotService;
use App\Services\GoogleSheets\RoshSheetService;
use App\Services\Liquipedia\LiquipediaService;
use App\Services\Stratz\StratzDataWindowCollector;
use App\Services\Stratz\StratzService;
use App\Services\Stratz\TeamRosterRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class StratzController
{
    public function index(TeamRosterRepository $teamRosterRepository): Response
    {
        $heroes = array_map(
            fn (Hero $hero) => [
                'id' => $hero->value,
                'name' => $hero->name,
                'title' => $hero->title(),
                'image' => asset("images/heroes/icons/{$hero->value}.png"),
            ],
            Hero::cases(),
        );

        return Inertia::render('Stratz', [
            'heroes' => $heroes,
            'savedTeams' => $teamRosterRepository->all(),
        ]);
    }

    public function leagueMatches(FetchLeagueMatchesRequest $request, StratzService $stratzService): JsonResponse|RedirectResponse
    {
        try {
            $matches = $stratzService->getLeagueMatches(
                $request->validated('league_id'),
                $request->validated('take', 20),
                $request->validated('skip', 0),
            );

            return $this->respond($request, 'league_matches', $matches);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function match(FetchMatchRequest $request, StratzService $stratzService): JsonResponse|RedirectResponse
    {
        try {
            $match = $stratzService->getMatchById($request->validated('match_id'));

            return $this->respond($request, 'match', $match);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function proPlayers(FetchProPlayersRequest $request, LiquipediaService $liquipediaService): JsonResponse|RedirectResponse
    {
        try {
            $proPlayers = $liquipediaService->getProPlayers();

            return $this->respond($request, 'pro_players', $proPlayers);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function searchProPlayers(
        SearchProPlayersRequest $request,
        LiquipediaService $liquipediaService,
    ): JsonResponse|RedirectResponse {
        try {
            $proPlayers = $liquipediaService->searchProPlayers(
                $request->searchQuery(),
                $request->resultLimit(),
            );

            return $this->respond($request, 'pro_players_search', $proPlayers);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function draft(FetchDraftRequest $request, StratzService $stratzService): JsonResponse|RedirectResponse
    {
        try {
            $draft = $stratzService->getDraftFromMatchId($request->integer('match_id'));

            return $this->respond($request, 'draft', $draft);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function rosh(
        FetchRoshRequest $request,
        StratzService $stratzService,
        RoshSheetService $roshSheetService,
        DraftSnapshotService $draftSnapshotService,
    ): JsonResponse|RedirectResponse {
        try {
            $rosh = $stratzService->getRoshFromMatchId($request->integer('match_id'));

            if ($roshSheetService->isConfigured()) {
                $rosh['google_sheets'] = $roshSheetService->syncMatchOdds(
                    $request->integer('match_id'),
                    (array) data_get($rosh, 'formatted', []),
                );
            }

            $rosh = $this->storeDraftSnapshot(
                $draftSnapshotService,
                'match_id',
                ['match_id' => $request->integer('match_id')],
                $rosh,
                ['match_id' => $request->integer('match_id')],
            );

            return $this->respond($request, 'rosh', $rosh);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function roshHeroes(
        FetchRoshHeroesRequest $request,
        StratzService $stratzService,
        RoshSheetService $roshSheetService,
        DraftSnapshotService $draftSnapshotService,
    ): JsonResponse|RedirectResponse {
        try {
            $payload = $request->validated();
            $rosh = $stratzService->getRoshFromHeroes($payload);

            if ($roshSheetService->isConfigured()) {
                $rosh['google_sheets'] = $roshSheetService->appendLiveOdds(
                    (array) data_get($rosh, 'formatted', []),
                );
            }

            $rosh = $this->storeDraftSnapshot($draftSnapshotService, 'manual', $payload, $rosh);

            return $this->respond($request, 'rosh', $rosh);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function roshHtml(
        FetchRoshHtmlRequest $request,
        DltvMatchHtmlParser $dltvMatchHtmlParser,
        StratzService $stratzService,
        RoshSheetService $roshSheetService,
        DraftSnapshotService $draftSnapshotService,
    ): JsonResponse|RedirectResponse {
        try {
            $payload = $dltvMatchHtmlParser->parse($request->validated('html'));
            $rosh = $stratzService->getRoshFromHeroes($payload);
            $rosh['parsed_html'] = $payload;

            if ($roshSheetService->isConfigured()) {
                $rosh['google_sheets'] = $roshSheetService->appendLiveOdds(
                    (array) data_get($rosh, 'formatted', []),
                );
            }

            $rosh = $this->storeDraftSnapshot($draftSnapshotService, 'live_dltv', $payload, $rosh);

            return $this->respond($request, 'rosh', $rosh);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function roshGist(
        Request $request,
        DltvGistHtmlFetcher $dltvGistHtmlFetcher,
        DltvMatchHtmlParser $dltvMatchHtmlParser,
        StratzService $stratzService,
        RoshSheetService $roshSheetService,
        DraftSnapshotService $draftSnapshotService,
    ): JsonResponse|RedirectResponse {
        try {
            $payload = $dltvMatchHtmlParser->parse($dltvGistHtmlFetcher->fetch());
            $rosh = $stratzService->getRoshFromHeroes($payload);
            $rosh['parsed_html'] = $payload;
            $rosh['source'] = [
                'type' => 'gist',
                'url' => (string) config('services.dltv.gist_url'),
            ];

            if ($roshSheetService->isConfigured()) {
                $rosh['google_sheets'] = $roshSheetService->appendLiveOdds(
                    (array) data_get($rosh, 'formatted', []),
                );
            }

            $rosh = $this->storeDraftSnapshot(
                $draftSnapshotService,
                'live_dltv',
                $payload,
                $rosh,
                ['dltv_url' => (string) config('services.dltv.gist_url')],
            );

            return $this->respond($request, 'rosh', $rosh);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function roshDltvExtension(
        FetchDltvExtensionRoshRequest $request,
        DltvExtensionPayloadParser $dltvExtensionPayloadParser,
        StratzService $stratzService,
        RoshSheetService $roshSheetService,
        DraftSnapshotService $draftSnapshotService,
    ): JsonResponse {
        try {
            $extensionPayload = $request->validated();
            $payload = $dltvExtensionPayloadParser->parse($extensionPayload);
            $rosh = $stratzService->getRoshFromHeroes($payload);
            $rosh['parsed_extension_payload'] = $extensionPayload;
            $rosh['parsed_extension_rosh_payload'] = $payload;
            $rosh['source'] = [
                'type' => 'dltv-browser-extension',
                'page_url' => $request->validated('page_url'),
                'captured_at' => $request->validated('captured_at'),
            ];

            if ($roshSheetService->isConfigured()) {
                $rosh['google_sheets'] = $roshSheetService->appendLiveOdds(
                    (array) data_get($rosh, 'formatted', []),
                );
            }

            $rosh = $this->storeDraftSnapshot(
                $draftSnapshotService,
                'live_dltv',
                $extensionPayload,
                $rosh,
                [
                    'captured_at' => $request->validated('captured_at'),
                    'dltv_url' => $request->validated('page_url'),
                ],
            );

            return $this->extensionResponse([
                'type' => 'rosh',
                'data' => $rosh,
            ]);
        } catch (Throwable $throwable) {
            return $this->extensionResponse($this->errorPayload($throwable), 422);
        }
    }

    public function dltvExtensionOptions(): JsonResponse
    {
        return $this->extensionResponse([], 204);
    }

    public function teamRosters(Request $request, TeamRosterRepository $teamRosterRepository): JsonResponse|RedirectResponse
    {
        try {
            return $this->respond($request, 'team_rosters', $teamRosterRepository->all());
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function storeTeamRoster(
        StoreTeamRosterRequest $request,
        TeamRosterRepository $teamRosterRepository,
    ): JsonResponse|RedirectResponse {
        try {
            $teamRoster = $teamRosterRepository->create($request->validated());

            return $this->respond($request, 'team_roster', $teamRoster);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function updateTeamRoster(
        UpdateTeamRosterRequest $request,
        string $teamRoster,
        TeamRosterRepository $teamRosterRepository,
    ): JsonResponse|RedirectResponse {
        try {
            $updatedTeamRoster = $teamRosterRepository->update($teamRoster, $request->validated());

            return $this->respond($request, 'team_roster', $updatedTeamRoster);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function destroyTeamRoster(
        Request $request,
        string $teamRoster,
        TeamRosterRepository $teamRosterRepository,
    ): JsonResponse|RedirectResponse {
        try {
            $teamRosterRepository->delete($teamRoster);

            return $this->respond($request, 'team_roster_deleted', [
                'slug' => $teamRoster,
            ]);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function snapshots(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $snapshots = DraftSnapshot::query()
                ->withCount('stratzDataBuckets')
                ->latest('captured_at')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (DraftSnapshot $snapshot): array => $this->snapshotSummary($snapshot))
                ->all();

            return $this->respond($request, 'draft_snapshots', $snapshots);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function snapshot(Request $request, DraftSnapshot $draftSnapshot): JsonResponse|RedirectResponse
    {
        try {
            return $this->respond($request, 'draft_snapshot', $this->snapshotPayload($draftSnapshot));
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function collectSnapshotStratzWindows(
        Request $request,
        DraftSnapshot $draftSnapshot,
        StratzDataWindowCollector $collector,
    ): JsonResponse|RedirectResponse {
        try {
            if (! $this->hasCompleteCollectedStratzWindows($draftSnapshot)) {
                $collector->collect($draftSnapshot);
                $draftSnapshot->refresh();
            }

            return $this->respond($request, 'draft_snapshot', $this->snapshotPayload($draftSnapshot));
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function exportSnapshotDataset(
        Request $request,
        RoshSheetService $roshSheetService,
    ): JsonResponse|RedirectResponse {
        try {
            $snapshots = DraftSnapshot::query()
                ->with('stratzDataBuckets')
                ->latest('captured_at')
                ->latest('id')
                ->get();

            return $this->respond(
                $request,
                'snapshot_dataset_export',
                $roshSheetService->syncSnapshotDataset($snapshots),
            );
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    private function respond(Request $request, string $type, array $data): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'type' => $type,
                'data' => $data,
            ]);
        }

        return back()->with('stratz_result', [
            'type' => $type,
            'data' => $data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $draftPayload
     * @param  array<string, mixed>  $rosh
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function storeDraftSnapshot(
        DraftSnapshotService $draftSnapshotService,
        string $source,
        array $draftPayload,
        array $rosh,
        array $metadata = [],
    ): array {
        $draftSnapshot = $draftSnapshotService->store($source, $draftPayload, $rosh, $metadata);
        $rosh = $draftSnapshotService->withoutInternalPayload($rosh);
        $rosh['draft_snapshot_id'] = $draftSnapshot->id;

        return $rosh;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSummary(DraftSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'source' => $snapshot->source,
            'captured_at' => $snapshot->captured_at?->toISOString(),
            'match_id' => $snapshot->match_id,
            'tournament' => $snapshot->tournament,
            'radiant_team' => $snapshot->radiant_team,
            'dire_team' => $snapshot->dire_team,
            'radiant_heroes_count' => count($snapshot->radiant_heroes ?? []),
            'dire_heroes_count' => count($snapshot->dire_heroes ?? []),
            'winner' => data_get($snapshot->result_payload, 'winner'),
            'model_version' => $snapshot->model_version,
            'sheet_title' => data_get($snapshot->sheet_payload, 'sheet_title'),
            'sheet_row' => data_get($snapshot->sheet_payload, 'row'),
            'has_draft_payload' => $snapshot->draft_payload !== null && $snapshot->draft_payload !== [],
            'has_stratz_payload' => $snapshot->stratz_payload !== null && $snapshot->stratz_payload !== [],
            'has_feature_payload' => $snapshot->feature_payload !== null && $snapshot->feature_payload !== [],
            'has_sheet_payload' => $snapshot->sheet_payload !== null && $snapshot->sheet_payload !== [],
            'has_result_payload' => $snapshot->result_payload !== null && $snapshot->result_payload !== [],
            'stratz_data_buckets_count' => (int) ($snapshot->stratz_data_buckets_count ?? $snapshot->stratzDataBuckets()->count()),
            'stratz_data_bucket_matrix' => $this->snapshotBucketMatrix($snapshot, false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotPayload(DraftSnapshot $snapshot): array
    {
        return [
            ...$this->snapshotSummary($snapshot),
            'dltv_url' => $snapshot->dltv_url,
            'bookmaker_odds_radiant' => $snapshot->bookmaker_odds_radiant,
            'bookmaker_odds_dire' => $snapshot->bookmaker_odds_dire,
            'radiant_heroes' => $snapshot->radiant_heroes,
            'dire_heroes' => $snapshot->dire_heroes,
            'draft_payload' => $snapshot->draft_payload,
            'stratz_payload' => $snapshot->stratz_payload,
            'feature_payload' => $snapshot->feature_payload,
            'sheet_payload' => $snapshot->sheet_payload,
            'book_payload' => $snapshot->book_payload,
            'result_payload' => $snapshot->result_payload,
            'stratz_data_bucket_matrix' => $this->snapshotBucketMatrix($snapshot),
            'stratz_data_buckets' => $snapshot->stratzDataBuckets()
                ->latest('id')
                ->get()
                ->map(fn ($bucket): array => [
                    'id' => $bucket->id,
                    'bucket_key' => $bucket->bucket_key,
                    'data_type' => $bucket->data_type,
                    'window_weeks' => $bucket->window_weeks,
                    'anchor_week' => $bucket->anchor_week,
                    'week_timestamps' => $bucket->week_timestamps,
                    'bracket_basic_id' => $bucket->bracket_basic_id,
                    'hero_ids' => $bucket->hero_ids,
                    'raw_payload' => $bucket->raw_payload,
                    'normalized_payload' => $bucket->normalized_payload,
                    'payload_hash' => $bucket->payload_hash,
                    'model_version' => $bucket->model_version,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotBucketMatrix(DraftSnapshot $snapshot, bool $includeItems = true): array
    {
        $buckets = $snapshot->stratzDataBuckets()->get([
            'data_type',
            'window_weeks',
            'bucket_key',
            ...($includeItems ? ['raw_payload', 'normalized_payload'] : []),
            'updated_at',
        ]);

        return [
            'complete_4_8_12' => $this->hasCompleteCollectedStratzWindows($snapshot, $buckets),
            'items' => $includeItems ? $buckets
                ->sortBy([
                    ['data_type', 'asc'],
                    ['window_weeks', 'asc'],
                ])
                ->map(fn ($bucket): array => [
                    'data_type' => $bucket->data_type,
                    'window_weeks' => $bucket->window_weeks,
                    'source' => str_starts_with($bucket->bucket_key, 'stratz_window_collector:')
                        ? 'collector'
                        : 'baseline',
                    'optimized' => (bool) data_get($bucket->normalized_payload, 'optimized_collection', false),
                    'raw_size_bytes' => strlen((string) json_encode($bucket->raw_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'updated_at' => $bucket->updated_at?->toISOString(),
                ])
                ->values()
                ->all() : [],
        ];
    }

    private function hasCompleteCollectedStratzWindows(DraftSnapshot $snapshot, mixed $buckets = null): bool
    {
        $buckets ??= $snapshot->stratzDataBuckets()->get([
            'data_type',
            'window_weeks',
            'bucket_key',
        ]);

        return collect(['heroes_meta_positions', 'hero_stats_by_time_bracket', 'synergy'])
            ->every(fn (string $dataType): bool => collect([4, 8, 12])
                ->every(fn (int $windowWeeks): bool => $buckets->contains(
                    fn ($bucket): bool => $bucket->data_type === $dataType
                        && $bucket->window_weeks === $windowWeeks
                        && str_starts_with($bucket->bucket_key, 'stratz_window_collector:'),
                )));
    }

    private function respondWithError(Request $request, Throwable $throwable): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($this->errorPayload($throwable), 422);
        }

        if ($throwable instanceof ExternalHttpRequestException) {
            return back()->withInput()->with('stratz_error', $throwable->context());
        }

        return back()->withInput()->with('stratz_error', $throwable->getMessage());
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(Throwable $throwable): array
    {
        if ($throwable instanceof ExternalHttpRequestException) {
            return [
                'error' => $throwable->getMessage(),
                'external_response' => $throwable->context(),
            ];
        }

        return [
            'error' => $throwable->getMessage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extensionResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->withHeaders([
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Source, X-DLTV-Parser-Token, Authorization',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            ]);
    }
}
