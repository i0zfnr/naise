<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.naise.payment_webhook_secret');
        abort_if($secret === '', 503, 'Payment provider webhook is not configured.');

        $signature = (string) $request->header('X-Naise-Signature');
        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless($signature !== '' && hash_equals($expectedSignature, $signature), 401, 'Invalid payment webhook signature.');

        $validated = $request->validate([
            'order_id' => ['required', 'integer'],
            'status' => ['required', 'in:paid'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_reference' => ['required', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($validated): void {
            $order = DB::table('orders')->whereNull('deleted_at')->lockForUpdate()->find($validated['order_id']);

            abort_unless($order && $order->payment_method === 'duitnow_qr', 404);
            abort_unless(number_format((float) $order->total, 2, '.', '') === number_format((float) $validated['amount'], 2, '.', ''), 422, 'Payment amount does not match the order total.');

            if ($order->payment_status === 'paid') {
                return;
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
        });

        return response()->json(['ok' => true, 'message' => 'Payment status recorded.']);
    }
}
