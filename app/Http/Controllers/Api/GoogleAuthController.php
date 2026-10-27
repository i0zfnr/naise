<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503, 'Google sign-in is not configured yet. Add your Google OAuth client ID and secret to the local environment.');

        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect('/')->withErrors(['google' => 'Google sign-in could not be completed. Please try again.']);
        }

        $verifiedEmail = (bool) ($googleUser->getRaw()['verified_email'] ?? false);

        if (! $verifiedEmail || ! filter_var($googleUser->getEmail(), FILTER_VALIDATE_EMAIL)) {
            return redirect('/')->withErrors(['google' => 'Use a Google account with a verified email address.']);
        }

        $user = User::query()->where('google_id', $googleUser->getId())->orWhere('email', $googleUser->getEmail())->first();

        if ($user) {
            $user->forceFill(['google_id' => $googleUser->getId(), 'name' => $googleUser->getName() ?: $user->name, 'avatar_url' => $googleUser->getAvatar() ?: $user->avatar_url, 'email_verified_at' => $user->email_verified_at ?: now()])->save();
        } else {
            $user = User::query()->create(['google_id' => $googleUser->getId(), 'name' => $googleUser->getName() ?: 'Naise member', 'email' => $googleUser->getEmail(), 'email_verified_at' => now(), 'password' => Hash::make(str()->random(48)), 'avatar_url' => $googleUser->getAvatar()]);
        }

        Auth::login($user, true);
        request()->session()->regenerate();

        return redirect('/')->with('profile', true);
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    }
}
