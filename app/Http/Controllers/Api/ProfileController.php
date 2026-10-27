<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['authenticated' => false]);
        }

        $orderQuery = DB::table('orders')->where('user_id', $user->id)->latest();
        $orders = $request->boolean('all_orders') ? $orderQuery->get() : $orderQuery->limit(3)->get();
        $orderItems = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->get()->groupBy('order_id');
        $vouchers = DB::table('vouchers')->where('user_id', $user->id)->latest()->limit(3)->get();

        return response()->json([
            'authenticated' => true,
            'user' => ['name' => $user->name, 'email' => $user->email, 'avatar_url' => $user->avatar_url, 'whatsapp_number' => $user->whatsapp_number, 'points' => $user->points, 'available_points' => $user->points, 'total_points' => $user->total_points, 'redeemed_points' => $user->redeemed_points, 'member_since' => $user->created_at?->toDateString()],
            'points_per_free_drink' => (int) config('services.naise.points_per_free_drink'),
            'vouchers' => $vouchers,
            'recent_orders' => $orders->map(fn ($order) => ['id' => $order->id, 'status' => $order->status, 'payment_method' => $order->payment_method ?? 'cash', 'payment_status' => $order->payment_status ?? 'cash_on_pickup', 'subtotal' => (float) $order->subtotal, 'discount_total' => (float) $order->discount_total, 'total' => (float) $order->total, 'created_at' => $order->created_at, 'items' => $orderItems->get($order->id, collect())->map(fn ($item) => ['name' => $item->product_name, 'quantity' => $item->quantity, 'unit_price' => (float) $item->unit_price])->values()])->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Sign in to update your profile.'], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'whatsapp_number' => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user->name = $validated['name'];
        $user->whatsapp_number = $validated['whatsapp_number'] ?? null;

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_url = Storage::disk('public')->url($path);
        }

        $user->save();

        return response()->json(['message' => 'Profile updated.', 'user' => ['name' => $user->name, 'whatsapp_number' => $user->whatsapp_number, 'avatar_url' => $user->avatar_url]]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Sign in with Google to redeem your points.'], 401);
        }

        $pointsCost = (int) config('services.naise.points_per_free_drink');
        $voucher = DB::transaction(function () use ($user, $pointsCost): ?int {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->points < $pointsCost) {
                return null;
            }

            $lockedUser->decrement('points', $pointsCost);
            $lockedUser->increment('redeemed_points', $pointsCost);

            return DB::table('vouchers')->insertGetId([
                'user_id' => $lockedUser->id,
                'name' => 'FREE DRINK',
                'description' => 'Redeem one free Naise drink for '.number_format($pointsCost).' points. Valid for the cheapest drink up to RM 12.',
                'points_cost' => $pointsCost,
                'discount_amount' => 0,
                'minimum_spend' => 0,
                'maximum_discount' => null,
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        if (! $voucher) {
            return response()->json(['message' => 'You need '.number_format($pointsCost).' points for a free drink.'], 422);
        }

        return response()->json(['message' => 'Free drink voucher created.', 'voucher_id' => $voucher, 'points' => $user->fresh()->points], 201);
    }
}
