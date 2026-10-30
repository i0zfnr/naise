<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AdminWorkspace
{
    public function get(string $key, array $default = []): array
    {
        $value = DB::table('admin_documents')->where('key', $key)->value('value');

        return $value === null ? $default : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function put(string $key, array $value): void
    {
        DB::table('admin_documents')->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()]);
    }

    public function menu(): array
    {
        return $this->get('menu', json_decode(file_get_contents(resource_path('menu.json')), true, flags: JSON_THROW_ON_ERROR));
    }

    public function rewards(): array
    {
        return $this->get('rewards', ['beans_per_rm' => (int) config('services.naise.points_per_ringgit', 1), 'referral_beans' => 200, 'voucher_label' => 'RM5 Voucher', 'tiers' => [['name' => 'Tak Naise Do', 'description' => 'Earn Beans with every order', 'threshold' => 0, 'archived' => false], ['name' => 'Naise', 'description' => 'A free birthday drink and more', 'threshold' => 1000, 'archived' => false], ['name' => 'Naisek Club', 'description' => 'Exclusive rewards and perks', 'threshold' => 3000, 'archived' => false]]]);
    }

    public function stamps(): array
    {
        return $this->get('stamps', ['enabled' => true, 'card_size' => 8, 'reward_at' => 4, 'rm_off' => 5, 'min_spend' => 11, 'free_drink_cap' => 12, 'expiry_days' => 30]);
    }

    public function shiftTotals(array $shift): array
    {
        $sales = DB::table('orders')->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->where('paid_at', '>=', $shift['opened_at'])->whereNotIn('id', $shift['opening_paid_ids'] ?? [])->when($shift['closed_at'] ?? null, fn ($query, $end) => $query->where('paid_at', '<=', $end))->get(['payment_method', 'total']);
        $cash = round((float) $sales->where('payment_method', 'cash')->sum('total'), 2);
        $qr = round((float) $sales->where('payment_method', 'duitnow_qr')->sum('total'), 2);
        $movement = round(array_sum(array_column($shift['movements'], 'cash_delta')), 2);

        return ['cash_sales' => $cash, 'qr_sales' => $qr, 'movement_total' => $movement, 'expected' => round($shift['float'] + $cash + $movement, 2)];
    }

    public function discount(float $subtotal): float
    {
        $discount = 0;
        foreach ($this->get('promotions') as $promotion) {
            if (! $promotion['enabled'] || $subtotal < $promotion['min_spend'] || now()->lt($promotion['starts_at']) || now()->gt($promotion['ends_at'])) {
                continue;
            }
            $discount = max($discount, $promotion['type'] === 'percent' ? $subtotal * $promotion['amount'] / 100 : $promotion['amount']);
        }

        return round(min($subtotal, $discount), 2);
    }
}
