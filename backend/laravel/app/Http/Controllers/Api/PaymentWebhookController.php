<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoyaltyPointsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, LoyaltyPointsService $loyaltyPoints): JsonResponse
    {
        $secret = (string) config('services.naise.payment_webhook_secret');
        abort_if($secret === '', 503, 'Payment provider webhook is not configured.');

        $timestamp = (string) $request->header('X-Naise-Timestamp');
        $signature = (string) $request->header('X-Naise-Signature');
        $tolerance = max(30, (int) config('services.naise.payment_webhook_tolerance_seconds', 300));
        abort_unless(ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= $tolerance, 401, 'Payment webhook timestamp is missing or expired.');

        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless($signature !== '' && hash_equals($expectedSignature, $signature), 401, 'Invalid payment webhook signature.');

        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:150'],
            'event_type' => ['required', 'in:payment.succeeded'],
            'order_reference' => ['required', 'integer'],
            'payment_reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'in:MYR'],
        ]);

        $provider = (string) config('services.naise.payment_provider', 'acquirer');
        $duplicate = DB::transaction(function () use ($validated, $loyaltyPoints, $provider, $request): bool {
            $inserted = DB::table('payment_events')->insertOrIgnore([
                'provider' => $provider,
                'provider_event_id' => $validated['event_id'],
                'event_type' => $validated['event_type'],
                'order_reference' => (string) $validated['order_reference'],
                'payment_reference' => $validated['payment_reference'],
                'payload_sha256' => hash('sha256', $request->getContent()),
                'received_at' => now(),
            ]);

            if (! $inserted) {
                $existingEvent = DB::table('payment_events')
                    ->where('provider', $provider)
                    ->where('provider_event_id', $validated['event_id'])
                    ->first();
                abort_unless($existingEvent && hash_equals($existingEvent->payload_sha256, hash('sha256', $request->getContent())), 409, 'Provider event ID was reused with a different payload.');

                return true;
            }

            $order = DB::table('orders')->whereNull('deleted_at')->lockForUpdate()->find($validated['order_reference']);

            abort_unless($order && $order->payment_method === 'duitnow_qr', 404);
            abort_unless($validated['currency'] === 'MYR', 422, 'Payment currency does not match the order currency.');
            abort_unless(number_format((float) $order->total, 2, '.', '') === number_format((float) $validated['amount'], 2, '.', ''), 422, 'Payment amount does not match the order total.');

            if ($order->payment_status === 'paid') {
                abort_unless($order->payment_reference === $validated['payment_reference'], 409, 'Order was paid using a different payment reference.');

                return false;
            }

            abort_if(
                DB::table('orders')
                    ->where('payment_reference', $validated['payment_reference'])
                    ->where('id', '!=', $order->id)
                    ->exists(),
                409,
                'Payment reference has already been used.',
            );

            DB::table('orders')->where('id', $order->id)->update([
                'payment_status' => 'paid',
                'payment_reference' => $validated['payment_reference'],
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

            $loyaltyPoints->awardForPaidOrder((int) $order->id);

            return false;
        });

        return response()->json(['ok' => true, 'duplicate' => $duplicate, 'message' => $duplicate ? 'Webhook event was already processed.' : 'Payment status recorded.']);
    }
}
