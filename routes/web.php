<?php

use App\Http\Controllers\StratzController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [StratzController::class, 'index'])->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';

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
