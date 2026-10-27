<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class DemoAuthController extends Controller
{
    public function show(): View
    {
        abort_unless(app()->environment('local'), 404);

        return view('demo-login');
    }

    public function login(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($credentials['email']))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors(['email' => 'The demo customer credentials were not recognized.'])->onlyInput('email');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/')->with('profile', true);
    }
}
