<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class BrevoTransactionalEmail
{
    public function sendLoginCode(string $address, string $code): bool
    {
        $apiKey = (string) config('services.brevo.api_key');
        $sender = (string) config('services.brevo.sender_email');
        if ($apiKey === '' || ! filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        try {
            return Http::acceptJson()->withHeaders(['api-key' => $apiKey])->connectTimeout(3)->timeout(8)->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => ['email' => $sender, 'name' => config('services.brevo.sender_name', 'Naise Coffee')],
                'to' => [['email' => $address]],
                'subject' => 'Your Naise Coffee sign-in code',
                'textContent' => "Your Naise Coffee sign-in code is {$code}. It expires in 10 minutes. Do not share this code. If you did not request it, ignore this email.",
                'tags' => ['naise-email-signin'],
            ])->successful();
        } catch (Throwable $exception) {
            Log::warning('Naise sign-in code delivery failed.', ['exception' => $exception::class]);

            return false;
        }
    }

    public function sendSignInNotice(User $user, bool $newAccount): bool
    {
        $apiKey = (string) config('services.brevo.api_key');
        $senderEmail = (string) config('services.brevo.sender_email');

        if ($apiKey === '' || $senderEmail === '') {
            Log::notice('Naise sign-in email skipped because Brevo is not configured.');

            return false;
        }

        $event = $newAccount ? 'signup' : 'signin';
        $subject = $newAccount ? 'Welcome to Naise Coffee' : 'New sign-in to your Naise Coffee account';
        $name = $user->name ?: 'Naise member';
        $time = now()->timezone('Asia/Kuala_Lumpur')->format('d M Y, g:i A').' MYT';
        $message = $newAccount
            ? "Welcome to Naise Coffee, {$name}. Your account was created using Google on {$time}."
            : "Hi {$name}, your Naise Coffee account was signed into using Google on {$time}. If this was not you, please contact Naise Coffee support.";

        try {
            $response = Http::acceptJson()
                ->withHeaders(['api-key' => $apiKey])
                ->timeout(8)
                ->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => [
                        'email' => $senderEmail,
                        'name' => config('services.brevo.sender_name', 'Naise Coffee'),
                    ],
                    'to' => [['email' => $user->email, 'name' => $name]],
                    'subject' => $subject,
                    'textContent' => $message,
                    'htmlContent' => '<p>'.e($message).'</p>',
                    'tags' => ['naise-account-'.$event],
                ]);

            if (! $response->successful()) {
                Log::warning('Brevo rejected a Naise sign-in email.', ['status' => $response->status()]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('Brevo sign-in email could not be sent.', ['exception' => $exception::class]);

            return false;
        }
    }
}
