<?php

use App\Http\Controllers\Auth\StaticAuthenticatedSessionController;
use App\Http\Controllers\StratzController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [StaticAuthenticatedSessionController::class, 'create'])->name('login');
Route::post('/login', [StaticAuthenticatedSessionController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('login.store');

Route::options('/api/dltv-match', [StratzController::class, 'dltvExtensionOptions'])
    ->name('dltv-match.options');
Route::post('/api/dltv-match', [StratzController::class, 'roshDltvExtension'])
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->middleware('throttle:20,1')
    ->name('dltv-match.rosh');

Route::post('/logout', [StaticAuthenticatedSessionController::class, 'destroy'])
    ->middleware('static.auth')
    ->name('logout');

Route::middleware('static.auth')->group(function () {
    Route::get('/', [StratzController::class, 'index'])->name('home');
    Route::redirect('/dashboard', '/')->name('dashboard');

    Route::redirect('/stratz', '/')->name('stratz.index');
    Route::post('/stratz/league-matches', [StratzController::class, 'leagueMatches'])->name('stratz.league-matches');
    Route::post('/stratz/match', [StratzController::class, 'match'])->name('stratz.match');
    Route::post('/stratz/pro-players/search', [StratzController::class, 'searchProPlayers'])->name('stratz.pro-players.search');
    Route::post('/stratz/pro-players', [StratzController::class, 'proPlayers'])->name('stratz.pro-players');
    Route::get('/stratz/teams', [StratzController::class, 'teamRosters'])->name('stratz.teams.index');
    Route::post('/stratz/teams', [StratzController::class, 'storeTeamRoster'])->name('stratz.teams.store');
    Route::patch('/stratz/teams/{teamRoster}', [StratzController::class, 'updateTeamRoster'])->name('stratz.teams.update');
    Route::delete('/stratz/teams/{teamRoster}', [StratzController::class, 'destroyTeamRoster'])->name('stratz.teams.destroy');
    Route::post('/stratz/draft', [StratzController::class, 'draft'])->name('stratz.draft');
    Route::post('/stratz/rosh', [StratzController::class, 'rosh'])->name('stratz.rosh');
    Route::post('/stratz/rosh-heroes', [StratzController::class, 'roshHeroes'])->name('stratz.rosh-heroes');
    Route::post('/stratz/rosh-html', [StratzController::class, 'roshHtml'])->name('stratz.rosh-html');
    Route::post('/stratz/rosh-gist', [StratzController::class, 'roshGist'])->name('stratz.rosh-gist');
});
