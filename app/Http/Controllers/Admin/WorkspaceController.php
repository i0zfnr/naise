<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminWorkspace;
use App\Services\LoyaltyPointsService;
use App\Services\WhatsAppNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(): View
    {
        return view('admin.workspace');
    }

    public function show(AdminWorkspace $workspace, WhatsAppNumber $numbers): JsonResponse
    {
        $orders = DB::table('orders')->whereNull('deleted_at')->orderByDesc('id')->get(['id', 'user_id', 'customer_name', 'customer_phone', 'status', 'payment_method', 'payment_status', 'receipt_path', 'subtotal', 'discount_total', 'total', 'paid_at', 'created_at']);
        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->get()->groupBy('order_id');
        $orders->each(function (object $order) use ($items, $numbers): void {
            $order->items = $items->get($order->id, collect());
            $order->has_receipt = (bool) $order->receipt_path;
            $phone = $numbers->normalize((string) $order->customer_phone);
            $message = 'Hi '.($order->customer_name ?: 'there').', your Naise order #'.$order->id.' is ready for pickup. See you soon!';
            $order->whatsapp_url = $phone && $order->status === 'ready' ? 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($message) : null;
            unset($order->receipt_path);
        });
        $counts = DB::table('orders')->whereNull('deleted_at')->whereNotNull('user_id')->selectRaw('user_id, COUNT(*) as order_count')->groupBy('user_id');
        $customers = DB::table('users')->leftJoinSub($counts, 'counts', 'users.id', '=', 'counts.user_id')->orderByDesc('users.id')->get(['users.id', 'users.name', 'users.email', 'users.whatsapp_number', 'users.points', 'users.total_points', 'counts.order_count']);
        $shifts = array_map(fn (array $shift): array => [...$shift, ...($shift['closed_at'] ? [] : $workspace->shiftTotals($shift))], $workspace->get('shifts'));

        return response()->json(['menu' => $workspace->menu(), 'orders' => $orders, 'customers' => $customers, 'rewards' => $workspace->rewards(), 'promotions' => $workspace->get('promotions'), 'stamps' => $workspace->get('stamps', ['enabled' => true, 'card_size' => 8, 'reward_at' => 4, 'rm_off' => 5, 'min_spend' => 11, 'free_drink_cap' => 12, 'expiry_days' => 30]), 'addons' => $workspace->get('addons'), 'shifts' => $shifts]);
    }

    public function update(Request $request, string $section, AdminWorkspace $workspace): JsonResponse
    {
        $rules = match ($section) {
            'menu' => ['value' => 'present|array|max:500', 'value.*' => 'array:name,price,category,bestSeller,image,available,archived,isNew,cost', 'value.*.name' => 'required|string|max:120|distinct', 'value.*.category' => 'required|string|max:80', 'value.*.price' => 'required|numeric|min:0|max:10000', 'value.*.cost' => 'sometimes|numeric|min:0|max:10000', 'value.*.image' => ['nullable', 'string', 'max:500', 'regex:~^(?:/images/[a-zA-Z0-9._/-]+|https://[^\s]+)$~'], 'value.*.bestSeller' => 'required|boolean', 'value.*.available' => 'sometimes|boolean', 'value.*.archived' => 'sometimes|boolean', 'value.*.isNew' => 'sometimes|boolean'],
            'rewards' => ['value' => 'required|array:beans_per_rm,referral_beans,voucher_label,tiers', 'value.beans_per_rm' => 'required|integer|min:0|max:1000', 'value.referral_beans' => 'required|integer|min:0|max:100000', 'value.voucher_label' => 'required|string|max:80', 'value.tiers' => 'present|array|max:20', 'value.tiers.*' => 'array:name,description,threshold,archived', 'value.tiers.*.name' => 'required|string|max:80', 'value.tiers.*.description' => 'required|string|max:255', 'value.tiers.*.threshold' => 'required|integer|min:0|max:10000000', 'value.tiers.*.archived' => 'required|boolean'],
            'stamps' => ['value' => 'required|array:enabled,card_size,reward_at,rm_off,min_spend,free_drink_cap,expiry_days', 'value.enabled' => 'required|boolean', 'value.card_size' => 'required|integer|min:1|max:100', 'value.reward_at' => 'required|integer|min:1|lte:value.card_size', 'value.rm_off' => 'required|numeric|min:0|max:1000', 'value.min_spend' => 'required|numeric|min:0|max:10000', 'value.free_drink_cap' => 'required|numeric|min:0|max:1000', 'value.expiry_days' => 'required|integer|min:1|max:3650'],
            'promotions' => ['value' => 'present|array|max:100', 'value.*' => 'array:id,name,type,amount,min_spend,starts_at,ends_at,enabled', 'value.*.id' => 'required|string|max:80|distinct', 'value.*.name' => 'required|string|max:120', 'value.*.type' => 'required|in:percent,fixed', 'value.*.amount' => 'required|numeric|min:0|max:100', 'value.*.min_spend' => 'required|numeric|min:0|max:10000', 'value.*.starts_at' => 'required|date', 'value.*.ends_at' => 'required|date|after:value.*.starts_at', 'value.*.enabled' => 'required|boolean'],
            'addons' => ['value' => 'present|array|max:100', 'value.*' => 'array:name,price', 'value.*.name' => 'required|string|max:80|distinct', 'value.*.price' => 'required|numeric|min:0|max:1000'],
            default => abort(404),
        };
        $validated = $request->validate($rules);
        $workspace->put($section, $validated['value']);

        return response()->json(['message' => 'Changes saved.']);
    }

    public function shift(Request $request, AdminWorkspace $workspace): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:open,close,movement', 'amount' => 'required|numeric|min:0|max:1000000', 'type' => 'required_if:action,movement|in:cash_in,cash_out,qr_to_cash,cash_to_qr', 'note' => 'nullable|string|max:255']);
        DB::transaction(function () use ($data, $workspace): void {
            DB::table('admin_documents')->where('key', 'shifts')->lockForUpdate()->first();
            $shifts = $workspace->get('shifts');
            $active = array_find_key($shifts, fn (array $shift): bool => $shift['closed_at'] === null);
            if ($data['action'] === 'open') {
                abort_if($active !== null, 422, 'A shift is already open.');
                array_unshift($shifts, ['id' => (string) Str::uuid(), 'opened_at' => now()->toDateTimeString(), 'closed_at' => null, 'float' => round($data['amount'], 2), 'movements' => [], 'opening_paid_ids' => DB::table('orders')->where('payment_status', 'paid')->pluck('id')->all()]);
            } else {
                abort_if($active === null, 422, 'Open a shift first.');
                if ($data['action'] === 'close') {
                    $shifts[$active] = [...$shifts[$active], ...$workspace->shiftTotals($shifts[$active]), 'closed_at' => now()->toDateTimeString(), 'counted' => round($data['amount'], 2)];
                } else {
                    abort_if($data['amount'] <= 0, 422, 'Enter an amount greater than zero.');
                    $delta = in_array($data['type'], ['cash_in', 'cash_to_qr']) ? $data['amount'] : -$data['amount'];
                    $shifts[$active]['movements'][] = [...$data, 'cash_delta' => round($delta, 2), 'created_at' => now()->toDateTimeString()];
                }
            }
            $workspace->put('shifts', $shifts);
        });

        return response()->json(['message' => $data['action'] === 'close' ? 'Shift closed and count saved.' : 'Shift updated.']);
    }

    public function order(Request $request, int $order, LoyaltyPointsService $loyalty): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:item,payment,cancel,complete,attach', 'item_id' => 'required_if:action,item|integer', 'status' => 'required_if:action,item|in:queued,making,ready', 'method' => 'required_if:action,payment|in:cash,duitnow_qr', 'passcode' => 'required_if:action,payment|string|max:128', 'user_id' => 'required_if:action,attach|integer|exists:users,id']);
        DB::transaction(function () use ($data, $order, $loyalty): void {
            $record = DB::table('orders')->whereNull('deleted_at')->where('id', $order)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_if(in_array($record->status, ['cancelled', 'completed', 'picked_up']), 422, 'This order is already closed.');
            if ($data['action'] === 'payment') {
                $hash = (string) config('services.naise.store_passcode_hash');
                abort_unless($hash !== '' && Hash::check($data['passcode'], $hash), 422, 'The manager passcode was not recognized.');
                $updates = ['payment_method' => $data['method'], 'updated_at' => now()];
                if ($record->payment_status !== 'paid' && $record->payment_method !== $data['method']) {
                    $updates['payment_status'] = $data['method'] === 'cash' ? 'cash_on_pickup' : 'pending';
                    if (in_array($record->status, ['awaiting_payment', 'received'])) {
                        $updates['status'] = $data['method'] === 'cash' ? 'received' : 'awaiting_payment';
                    }
                }
                DB::table('orders')->where('id', $order)->update($updates);
            } elseif ($data['action'] === 'item') {
                $updated = DB::table('order_items')->where('order_id', $order)->where('id', $data['item_id'])->update(['preparation_status' => $data['status'], 'updated_at' => now()]);
                abort_unless($updated, 404);
                $ready = ! DB::table('order_items')->where('order_id', $order)->where('preparation_status', '!=', 'ready')->exists();
                DB::table('orders')->where('id', $order)->update(['status' => $ready ? 'ready' : 'preparing', 'updated_at' => now()]);
            } elseif ($data['action'] === 'attach') {
                abort_if($record->user_id !== null, 422, 'This order already has a member.');
                DB::table('orders')->where('id', $order)->update(['user_id' => $data['user_id'], 'updated_at' => now()]);
                $loyalty->awardForPaidOrder($order);
            } elseif ($data['action'] === 'complete') {
                abort_unless($record->payment_status === 'paid', 422, 'Confirm payment before completing this order.');
                abort_if(DB::table('order_items')->where('order_id', $order)->where('preparation_status', '!=', 'ready')->exists(), 422, 'Mark every drink ready first.');
                DB::table('orders')->where('id', $order)->update(['status' => 'completed', 'updated_at' => now()]);
            } else {
                abort_if($record->payment_status === 'paid', 422, 'Paid orders require a refund review before cancellation.');
                DB::table('orders')->where('id', $order)->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
