<?php

namespace App\Http\Controllers;

use App\Enums\Stratz\Hero;
use App\Http\Requests\Stratz\FetchDraftRequest;
use App\Http\Requests\Stratz\FetchLeagueMatchesRequest;
use App\Http\Requests\Stratz\FetchMatchRequest;
use App\Http\Requests\Stratz\FetchProPlayersRequest;
use App\Services\Stratz\StratzService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class StratzController
{
    public function index(): Response
    {
        $heroes = array_map(
            fn (Hero $hero) => [
                'id' => $hero->value,
                'name' => $hero->name,
                'title' => $hero->title(),
            ],
            Hero::cases(),
        );

        return Inertia::render('Stratz', [
            'heroes' => $heroes,
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

    public function proPlayers(FetchProPlayersRequest $request, StratzService $stratzService): JsonResponse|RedirectResponse
    {
        try {
            $proPlayers = $stratzService->getProPlayers();

            return $this->respond($request, 'pro_players', $proPlayers);
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
            return response()->json([
                'error' => $throwable->getMessage(),
            ], 422);
        }

        return back()->withInput()->with('stratz_error', $throwable->getMessage());
    }
}
