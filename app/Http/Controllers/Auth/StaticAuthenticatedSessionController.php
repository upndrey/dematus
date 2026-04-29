<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaticLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaticAuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get(config('static-auth.session_key')) === true) {
            return redirect()->route('home');
        }

        return Inertia::render('auth/Login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(StaticLoginRequest $request): RedirectResponse
    {
        if ($request->session()->get(config('static-auth.session_key')) === true) {
            return redirect()->route('home');
        }

        $request->authenticate();

        $request->session()->regenerate();
        $request->session()->put(config('static-auth.session_key'), true);

        return redirect()->intended(route('home', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(config('static-auth.session_key'));
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
