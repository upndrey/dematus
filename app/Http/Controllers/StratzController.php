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
use App\Services\Dltv\DltvExtensionPayloadParser;
use App\Services\Dltv\DltvGistHtmlFetcher;
use App\Services\Dltv\DltvMatchHtmlParser;
use App\Services\GoogleSheets\RoshSheetService;
use App\Services\Liquipedia\LiquipediaService;
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
    ): JsonResponse|RedirectResponse {
        try {
            $rosh = $stratzService->getRoshFromMatchId($request->integer('match_id'));

            if ($roshSheetService->isConfigured()) {
                $rosh['google_sheets'] = $roshSheetService->syncMatchOdds(
                    $request->integer('match_id'),
                    (array) data_get($rosh, 'formatted', []),
                );
            }

            return $this->respond($request, 'rosh', $rosh);
        } catch (Throwable $throwable) {
            return $this->respondWithError($request, $throwable);
        }
    }

    public function roshHeroes(
        FetchRoshHeroesRequest $request,
        StratzService $stratzService,
        RoshSheetService $roshSheetService,
    ): JsonResponse|RedirectResponse {
        try {
            $rosh = $stratzService->getRoshFromHeroes($request->validated());

            if ($roshSheetService->isConfigured()) {
                $rosh['google_sheets'] = $roshSheetService->appendLiveOdds(
                    (array) data_get($rosh, 'formatted', []),
                );
            }

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
    ): JsonResponse {
        try {
            $payload = $dltvExtensionPayloadParser->parse($request->validated());
            $rosh = $stratzService->getRoshFromHeroes($payload);
            $rosh['parsed_extension_payload'] = $request->validated();
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
