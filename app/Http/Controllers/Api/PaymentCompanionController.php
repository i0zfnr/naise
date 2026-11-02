<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoyaltyPointsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PaymentCompanionController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:128', 'name' => 'required|string|max:100']);
        $hash = (string) config('services.naise.admin_password_hash');
        abort_unless($hash !== '' && hash_equals(strtolower(trim((string) config('services.naise.admin_email'))), strtolower(trim($data['email']))) && Hash::check($data['password'], $hash), 401, 'Administrator email or password is incorrect.');
        $token = Str::random(64);
        DB::table('payment_devices')->insert([
            'id' => (string) Str::uuid(), 'name' => $data['name'], 'token_hash' => hash('sha256', $token),
            'credential_version' => $this->credentialVersion(), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['token' => $token, 'expires_in' => 2592000])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        $device = $this->device($request);
        DB::table('payment_devices')->where('id', $device->id)->update(['revoked_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Phone disconnected.']);
    }

    public function store(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'reference' => ['required', 'string', 'regex:/^NAISE-[1-9][0-9]{0,14}$/'],
            'amount_minor' => 'required|integer|min:1|max:100000000',
            'observed_at' => 'required|date',
        ]);
        $observed = Carbon::parse($data['observed_at'])->utc();
        abort_if($observed->gt(now()->addMinutes(5)), 422, 'Notification time is in the future. Check your phone clock.');
        $event = DB::transaction(function () use ($device, $data, $observed): object {
            $existing = DB::table('payment_notification_events')->where('fingerprint', $data['fingerprint'])->first();
            if ($existing) {
                abort_unless($existing->device_id === $device->id && $existing->reference === $data['reference'] && (int) $existing->amount_minor === (int) $data['amount_minor'], 409, 'This notification was already received with different details or from another phone. Review it in the dashboard.');

                return $existing;
            }
            $order = DB::table('orders')->whereNull('deleted_at')->find((int) substr($data['reference'], 6));
            $reason = $this->mismatch($order, (int) $data['amount_minor'], $observed);
            DB::table('payment_notification_events')->insertOrIgnore([
                'device_id' => $device->id, 'fingerprint' => $data['fingerprint'], 'reference' => $data['reference'], 'amount_minor' => $data['amount_minor'],
                'observed_at' => $observed, 'order_id' => $order?->id, 'status' => $reason === null ? 'matched' : 'review',
                'message' => $reason ?? 'Reference and amount match. Check the incoming money in TNG before confirming.', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $record = DB::table('payment_notification_events')->where('fingerprint', $data['fingerprint'])->first();
            abort_unless($record && $record->device_id === $device->id && $record->reference === $data['reference'] && (int) $record->amount_minor === (int) $data['amount_minor'], 409, 'This notification was already received with different details. Check it manually.');

            return $record;
        });

        return $this->eventResponse($event);
    }

    public function confirm(Request $request, int $event, LoyaltyPointsService $loyalty): JsonResponse
    {
        $device = $this->device($request);
        $request->validate(['checked_in_wallet' => 'required|accepted']);
        $record = DB::transaction(function () use ($device, $event, $loyalty): object {
            $record = DB::table('payment_notification_events')->where('device_id', $device->id)->lockForUpdate()->find($event);
            abort_unless($record, 404);
            if ($record->status === 'confirmed') {
                return $record;
            }
            abort_unless($record->status === 'matched', 409, 'This payment needs manual review in the dashboard.');
            $order = DB::table('orders')->whereNull('deleted_at')->lockForUpdate()->find($record->order_id);
            $reason = $this->mismatch($order, (int) $record->amount_minor, Carbon::parse($record->observed_at));
            abort_if($reason !== null, 409, $reason ?? 'Order cannot be confirmed.');
            DB::table('orders')->where('id', $order->id)->update([
                'payment_status' => 'paid', 'paid_at' => now(), 'payment_reference' => 'notification-review:'.$record->id,
                'status' => in_array($order->status, ['awaiting_payment', 'awaiting_verification', 'received', 'pending']) ? 'paid' : $order->status, 'updated_at' => now(),
            ]);
            DB::table('payment_notification_events')->where('id', $event)->update(['status' => 'confirmed', 'message' => 'Payment checked and confirmed by shop staff.', 'confirmed_at' => now(), 'updated_at' => now()]);
            $loyalty->awardForPaidOrder((int) $order->id);

            return DB::table('payment_notification_events')->find($event);
        });

        return $this->eventResponse($record);
    }

    private function mismatch(?object $order, int $amount, Carbon $observed): ?string
    {
        if (! $order || $order->payment_method !== 'duitnow_qr') {
            return 'No QR order matches this reference. Check it in the dashboard.';
        }
        if ($order->payment_status === 'paid' || in_array($order->status, ['cancelled', 'completed', 'picked_up'])) {
            return 'This order is already paid or closed. Do not confirm it again.';
        }
        if ((int) round((float) $order->total * 100) !== $amount) {
            return 'The payment amount differs from the order total. Check it manually.';
        }
        if ($observed->lt(now()->subDay()) || $observed->lt(Carbon::parse($order->created_at)->subMinutes(5))) {
            return 'This notification is too old or predates the order. Check it manually.';
        }

        return null;
    }

    private function device(Request $request): object
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) === 64, 401, 'Connect this phone again.');
        $device = DB::table('payment_devices')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($device && hash_equals($device->credential_version, $this->credentialVersion()), 401, 'This connection expired. Sign in again.');

        return $device;
    }

    private function credentialVersion(): string
    {
        return hash('sha256', config('services.naise.admin_email').'|'.config('services.naise.admin_password_hash'));
    }

    private function eventResponse(object $event): JsonResponse
    {
        return response()->json(['id' => $event->id, 'status' => $event->status, 'reference' => $event->reference, 'amount_minor' => $event->amount_minor, 'message' => $event->message])->header('Cache-Control', 'no-store');
    }
}
