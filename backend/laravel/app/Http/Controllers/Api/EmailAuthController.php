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

class EmailAuthController extends Controller
{
    public function store(Request $request, BrevoTransactionalEmail $email): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $address = strtolower(trim($data['email']));
        $challenge = Str::random(64);
        $code = (string) random_int(100000, 999999);
        DB::table('email_login_challenges')->where('expires_at', '<', now()->subDay())->delete();
        DB::table('email_login_challenges')->updateOrInsert(['email' => $address], [
            'id' => hash('sha256', $challenge),
            'email' => $address,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'used_at' => null,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $email->sendLoginCode($address, $code)) {
            DB::table('email_login_challenges')->where('id', hash('sha256', $challenge))->delete();

            return response()->json(['message' => 'We could not send your sign-in code right now. Please try again later or contact the store.'], 503);
        }

        return response()->json(['message' => 'Check your inbox for your six-digit sign-in code.', 'challenge' => $challenge, 'expires_in' => 600, 'resend_after' => 60]);
    }

    public function verify(Request $request, WhatsAppNumber $numbers): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'size:64', 'alpha_num:ascii'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $user = DB::transaction(function () use ($data): ?User {
            $challenge = DB::table('email_login_challenges')->where('id', hash('sha256', $data['challenge']))->lockForUpdate()->first();
            if (! $challenge || $challenge->used_at || now()->gte($challenge->expires_at) || $challenge->attempts >= 5) {
                return null;
            }
            DB::table('email_login_challenges')->where('id', $challenge->id)->update(['attempts' => $challenge->attempts + 1, 'updated_at' => now()]);
            if (! Hash::check($data['code'], $challenge->code_hash)) {
                return null;
            }
            DB::table('email_login_challenges')->where('email', $challenge->email)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
            $user = User::query()->firstOrCreate(['email' => $challenge->email], ['name' => '', 'password' => Str::random(48)]);
            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });
        if (! $user) {
            return response()->json(['message' => 'This code is incorrect or has expired. Request a new code if needed.'], 422);
        }
        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json(['message' => 'Signed in successfully.', 'needs_profile' => blank($user->name) || $numbers->normalize((string) $user->whatsapp_number) === null]);
    }
}
