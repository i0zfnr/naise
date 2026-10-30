<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrevoTransactionalEmail;
use App\Services\WhatsAppNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return redirect('/')->with('profile', true)->withErrors(['google' => 'Google sign-in is temporarily unavailable. Please contact the store or try again later.']);
        }

        return Socialite::driver('google')->setScopes(['openid', 'profile', 'email'])->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(BrevoTransactionalEmail $email, WhatsAppNumber $numbers): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect('/')->with('profile', true)->withErrors(['google' => 'Google sign-in could not be completed. Please try again.']);
        }

        $verifiedEmail = filter_var($googleUser->getRaw()['email_verified'] ?? $googleUser->getRaw()['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $googleEmail = strtolower(trim((string) $googleUser->getEmail()));
        $googleId = (string) $googleUser->getId();

        if (! $verifiedEmail || $googleId === '' || ! filter_var($googleEmail, FILTER_VALIDATE_EMAIL)) {
            return redirect('/')->with('profile', true)->withErrors(['google' => 'Use a Google account with a verified email address.']);
        }

        $user = User::query()->where('google_id', $googleId)->first();
        $emailAccount = User::query()->where('email', $googleEmail)->first();
        if (($user && $emailAccount && $user->id !== $emailAccount->id) || ($emailAccount?->google_id && $emailAccount->google_id !== $googleId)) {
            return redirect('/')->with('profile', true)->withErrors(['google' => 'This email is linked to a different Google account. Please contact the store.']);
        }
        $user ??= $emailAccount;
        $newAccount = $user === null;

        if ($user) {
            $user->forceFill(['google_id' => $googleId, 'email' => $googleEmail, 'name' => $googleUser->getName() ?: $user->name, 'avatar_url' => $googleUser->getAvatar() ?: ($googleUser->getRaw()['picture'] ?? $user->avatar_url), 'email_verified_at' => $user->email_verified_at ?: now()])->save();
        } else {
            $user = User::query()->create(['google_id' => $googleId, 'name' => $googleUser->getName() ?: 'Naise member', 'email' => $googleEmail, 'email_verified_at' => now(), 'password' => Hash::make(str()->random(48)), 'avatar_url' => $googleUser->getAvatar()]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, true);
        request()->session()->regenerate();
        $email->sendSignInNotice($user, $newAccount);

        return redirect('/')->with('profile', true)->with('needs_phone', $numbers->normalize((string) $user->whatsapp_number) === null);
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    }
}
