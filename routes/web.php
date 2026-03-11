<?php

use App\Http\Controllers\StratzController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';

Route::get('/stratz', [StratzController::class, 'index'])->name('stratz.index');
Route::post('/stratz/league-matches', [StratzController::class, 'leagueMatches'])->name('stratz.league-matches');
Route::post('/stratz/match', [StratzController::class, 'match'])->name('stratz.match');
Route::post('/stratz/pro-players', [StratzController::class, 'proPlayers'])->name('stratz.pro-players');
Route::post('/stratz/draft', [StratzController::class, 'draft'])->name('stratz.draft');
