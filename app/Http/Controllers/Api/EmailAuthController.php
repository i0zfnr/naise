<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrevoTransactionalEmail;
use App\Services\WhatsAppNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailAuthController extends Controller
{
    public function store(Request $request, BrevoTransactionalEmail $email): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254'], 'purpose' => ['required', 'in:signin,signup']]);
        $address = strtolower(trim($data['email']));
        $exists = User::query()->whereRaw('LOWER(email) = ?', [$address])->exists();
        if ($data['purpose'] === 'signin' && ! $exists) {
            throw ValidationException::withMessages(['email' => 'No account uses this email. Please sign up first.']);
        }
        if ($data['purpose'] === 'signup' && $exists) {
            throw ValidationException::withMessages(['email' => 'This email already has an account. Please sign in.']);
        }
        $request->session()->forget('verified_registration');
        $challenge = Str::random(64);
        $code = (string) random_int(100000, 999999);
        DB::table('email_login_challenges')->where('expires_at', '<', now()->subDay())->delete();
        DB::table('email_login_challenges')->updateOrInsert(['email' => $address], [
            'id' => hash('sha256', $challenge),
            'email' => $address,
            'purpose' => $data['purpose'],
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'used_at' => null,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $email->sendLoginCode($address, $code)) {
            DB::table('email_login_challenges')->where('id', hash('sha256', $challenge))->delete();

            return response()->json(['message' => 'We could not send your verification code right now. Please try again later or contact the store.'], 503);
        }

        return response()->json(['message' => 'Check your inbox for your six-digit verification code.', 'challenge' => $challenge, 'expires_in' => 600, 'resend_after' => 60]);
    }

    public function verify(Request $request, WhatsAppNumber $numbers): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'size:64', 'alpha_num:ascii'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $verified = DB::transaction(function () use ($data): ?object {
            $challenge = DB::table('email_login_challenges')->where('id', hash('sha256', $data['challenge']))->lockForUpdate()->first();
            if (! $challenge || $challenge->used_at || now()->gte($challenge->expires_at) || $challenge->attempts >= 5) {
                return null;
            }
            DB::table('email_login_challenges')->where('id', $challenge->id)->update(['attempts' => $challenge->attempts + 1, 'updated_at' => now()]);
            if (! Hash::check($data['code'], $challenge->code_hash)) {
                return null;
            }
            DB::table('email_login_challenges')->where('email', $challenge->email)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);

            return $challenge;
        });
        if (! $verified) {
            return response()->json(['message' => 'This code is incorrect or has expired. Request a new code if needed.'], 422);
        }
        if ($verified->purpose === 'signup') {
            $request->session()->regenerate();
            $request->session()->put('verified_registration', ['email' => $verified->email, 'expires_at' => now()->addMinutes(10)->timestamp]);

            return response()->json(['message' => 'Email verified. Complete your sign-up.', 'needs_signup' => true]);
        }
        $user = User::query()->whereRaw('LOWER(email) = ?', [$verified->email])->first();
        if (! $user) {
            return response()->json(['message' => 'No account uses this email. Please sign up first.'], 422);
        }
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json(['message' => 'Signed in successfully.', 'needs_profile' => blank($user->name) || $numbers->normalize((string) $user->whatsapp_number) === null]);
    }

    public function register(Request $request, WhatsAppNumber $numbers): JsonResponse
    {
        $pending = $request->session()->get('verified_registration');
        if (! $pending || now()->timestamp >= $pending['expires_at']) {
            $request->session()->forget('verified_registration');

            return response()->json(['message' => 'Verify your email again before completing sign-up.'], 422);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'whatsapp_number' => ['required', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);
        $phone = $numbers->normalize($data['whatsapp_number']);
        if (! $phone) {
            throw ValidationException::withMessages(['whatsapp_number' => 'Enter a valid WhatsApp number, including your country code.']);
        }
        $user = DB::transaction(function () use ($pending, $data, $phone): User {
            if (User::query()->whereRaw('LOWER(email) = ?', [$pending['email']])->exists()) {
                throw ValidationException::withMessages(['email' => 'This email already has an account. Please sign in.']);
            }
            $user = User::query()->create([
                'email' => $pending['email'], 'name' => $data['name'], 'whatsapp_number' => $phone,
                'password' => $data['password'] ?? Str::random(48),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });
        $request->session()->forget('verified_registration');
        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json(['message' => 'Account created successfully.'], 201);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254'], 'password' => ['required', 'string', 'max:128']]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->email_verified_at) {
            return response()->json(['message' => 'Email or password is incorrect. You can also sign in with an email code.'], 422);
        }
        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json(['message' => 'Signed in successfully.']);
    }
}
