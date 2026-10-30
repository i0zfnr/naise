<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LoyaltyPointsService
{
    public function __construct(private AdminWorkspace $workspace) {}

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

            $points = max(0, (int) floor((float) $order->total * $this->workspace->rewards()['beans_per_rm']));
            $member = DB::table('users')->where('id', $order->user_id)->lockForUpdate()->first();

            if ($points > 0) {
                DB::table('users')->where('id', $order->user_id)->increment('points', $points);
                DB::table('users')->where('id', $order->user_id)->increment('total_points', $points);
            }

            DB::table('orders')->where('id', $orderId)->update([
                'points_earned' => $points,
                'points_awarded_at' => now(),
            ]);

            $stamps = $this->workspace->stamps();
            if ($member && $stamps['enabled'] && (float) $order->total > 0) {
                $count = ($member->stamp_count % $stamps['card_size']) + 1;
                DB::table('users')->where('id', $member->id)->update(['stamp_count' => $count % $stamps['card_size']]);
                if ($count === $stamps['reward_at'] || $count === $stamps['card_size']) {
                    $isFreeDrink = $count === $stamps['card_size'];
                    DB::table('vouchers')->insert([
                        'user_id' => $member->id,
                        'name' => $isFreeDrink ? 'FREE DRINK' : $this->workspace->rewards()['voucher_label'],
                        'description' => $isFreeDrink ? 'Stamp card reward: one drink up to RM '.number_format($stamps['free_drink_cap'], 2) : 'Stamp card reward: RM '.number_format($stamps['rm_off'], 2).' off.',
                        'discount_amount' => $isFreeDrink ? 0 : $stamps['rm_off'],
                        'minimum_spend' => $isFreeDrink ? 0 : $stamps['min_spend'],
                        'maximum_discount' => $isFreeDrink ? $stamps['free_drink_cap'] : $stamps['rm_off'],
                        'status' => 'available',
                        'expires_at' => now()->addDays($stamps['expiry_days']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return $points;
        });
    }
}
