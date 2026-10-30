<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminWorkspace;
use App\Services\WhatsAppNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    public function store(Request $request, AdminWorkspace $workspace, WhatsAppNumber $numbers): JsonResponse
    {
        $member = $request->user();
        if ($member && $numbers->normalize((string) $member->whatsapp_number) === null) {
            return response()->json(['message' => 'Add your WhatsApp number to your profile before placing an order.'], 422);
        }
        if ($member && blank($member->name)) {
            return response()->json(['message' => 'Add your name to your profile before placing an order.'], 422);
        }
        if ($member) {
            $request->merge(['customer_name' => $member->name, 'customer_phone' => $member->whatsapp_number]);
        }
        $phone = $request->input('customer_phone');
        $request->merge(['customer_phone' => is_string($phone) ? $numbers->normalize($phone) : null]);
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'payment_method' => ['required', 'in:cash,duitnow_qr'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);

        if ($request->user() && blank($request->user()->whatsapp_number)) {
            return response()->json(['message' => 'Add your WhatsApp number to your profile before placing an order.'], 422);
        }

        if ($validated['payment_method'] === 'duitnow_qr') {
            if (! $request->user()) {
                return response()->json(['message' => 'Sign in before choosing QR payment so your receipt can be linked to your order.'], 401);
            }

            abort_unless(DB::table('payment_settings')->whereNotNull('qr_code_path')->exists(), 422, 'QR payment is not available yet. Please choose cash on pickup.');
        }
        $catalog = collect($workspace->menu())->filter(fn (array $item): bool => ($item['available'] ?? true) && ! ($item['archived'] ?? false))->keyBy('name');
        $pricedItems = collect($validated['items'])->map(function (array $item) use ($catalog): array {
            abort_unless($catalog->has($item['name']), 422, 'A selected drink is not on the menu.');

            return [...$item, 'unit_price' => (float) $catalog->get($item['name'])['price']];
        });
        $subtotal = $pricedItems->sum(fn (array $item): float => $item['unit_price'] * $item['quantity']);
        $discount = $workspace->discount((float) $subtotal);
        $total = round($subtotal - $discount, 2);
        $paymentMethod = $validated['payment_method'];
        $orderId = DB::transaction(function () use ($request, $validated, $pricedItems, $subtotal, $discount, $total, $paymentMethod): int {
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $request->user()?->id,
                'customer_name' => trim($validated['customer_name']),
                'customer_phone' => trim($validated['customer_phone']),
                'status' => $paymentMethod === 'duitnow_qr' ? 'awaiting_payment' : 'received',
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentMethod === 'duitnow_qr' ? 'pending' : 'cash_on_pickup',
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'total' => $total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($pricedItems as $item) {
                DB::table('order_items')->insert(['order_id' => $orderId, 'product_name' => $item['name'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'created_at' => now(), 'updated_at' => now()]);
            }

            return $orderId;
        });

        return response()->json([
            'message' => $paymentMethod === 'duitnow_qr' ? 'Order created. Waiting for payment provider confirmation.' : 'Order placed. Pay at the counter when you pick it up.',
            'order_id' => $orderId,
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'total' => $total,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentMethod === 'duitnow_qr' ? 'pending' : 'cash_on_pickup',
        ], 201);
    }

    public function paymentSettings(): JsonResponse
    {
        $settings = DB::table('payment_settings')->first();

        return response()->json([
            'configured' => (bool) ($settings?->qr_code_path),
            'merchant_name' => $settings?->merchant_name,
            'qr_code_url' => $settings?->qr_code_path ? Storage::disk('public')->url($settings->qr_code_path) : null,
        ]);
    }

    public function receipt(Request $request, int $order): JsonResponse
    {
        $user = $request->user();
        $orderRecord = DB::table('orders')->whereNull('deleted_at')->where('id', $order)->first();

        abort_unless($orderRecord && $user && $orderRecord->user_id === $user->id, 404);
        abort_unless($orderRecord->payment_method === 'duitnow_qr', 422, 'A receipt is only needed for QR orders.');

        $validated = $request->validate([
            'receipt' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $receiptPath = $validated['receipt']->store('payment-receipts');

        if ($orderRecord->receipt_path) {
            Storage::disk('local')->delete($orderRecord->receipt_path);
        }

        DB::table('orders')->whereNull('deleted_at')->where('id', $order)->update([
            'receipt_path' => $receiptPath,
            'payment_status' => $orderRecord->payment_status === 'paid' ? 'paid' : 'receipt_submitted',
            'status' => $orderRecord->payment_status === 'paid' ? 'paid' : 'awaiting_verification',
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Receipt submitted. Store staff can review it before preparing your order.', 'payment_status' => $orderRecord->payment_status === 'paid' ? 'paid' : 'receipt_submitted']);
    }

    public function paymentStatus(Request $request, int $order): JsonResponse
    {
        $orderRecord = DB::table('orders')->whereNull('deleted_at')->where('id', $order)->first();

        abort_unless($orderRecord && $request->user() && $orderRecord->user_id === $request->user()->id, 404);

        return response()->json([
            'order_id' => $orderRecord->id,
            'status' => $orderRecord->status,
            'payment_status' => $orderRecord->payment_status,
            'receipt_submitted' => (bool) $orderRecord->receipt_path,
        ]);
    }
}
