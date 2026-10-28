<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LoyaltyPointsService
{
    public function awardForPaidOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId): int {
            $order = DB::table('orders')
                ->where('id', $orderId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $order || ! $order->user_id || $order->payment_status !== 'paid' || $order->points_awarded_at) {
                return 0;
            }

            $points = max(0, (int) floor((float) $order->total * (int) config('services.naise.points_per_ringgit', 1)));

            if ($points > 0) {
                DB::table('users')->where('id', $order->user_id)->increment('points', $points);
                DB::table('users')->where('id', $order->user_id)->increment('total_points', $points);
            }

            DB::table('orders')->where('id', $orderId)->update([
                'points_earned' => $points,
                'points_awarded_at' => now(),
            ]);

            return $points;
        });
    }
}
