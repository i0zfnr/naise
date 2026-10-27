<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'client@naise.test'],
            [
                'name' => 'Naise Demo Client',
                'password' => Hash::make('NaiseTestClient-v3!'),
                'points' => 2120,
                'total_points' => 2620,
                'redeemed_points' => 500,
                'whatsapp_number' => '17-976 4854',
                'email_verified_at' => now(),
            ],
        );

        if (! DB::table('orders')->where('user_id', $user->id)->exists()) {
            foreach ([
                ['status' => 'completed', 'subtotal' => 8, 'total' => 8, 'item' => 'Butterbrew', 'quantity' => 1, 'price' => 8],
                ['status' => 'completed', 'subtotal' => 26, 'total' => 26, 'item' => 'Buttercream Latte', 'quantity' => 2, 'price' => 13],
                ['status' => 'ready', 'subtotal' => 16, 'total' => 16, 'item' => 'Matcha Latte', 'quantity' => 1, 'price' => 16],
            ] as $demoOrder) {
                $createdAt = now()->subDays(random_int(1, 14));
                $isPaid = in_array($demoOrder['status'], ['paid', 'completed', 'picked_up'], true);
                $orderId = DB::table('orders')->insertGetId([
                    'user_id' => $user->id,
                    'status' => $demoOrder['status'],
                    'payment_status' => $isPaid ? 'paid' : 'cash_on_pickup',
                    'paid_at' => $isPaid ? $createdAt : null,
                    'subtotal' => $demoOrder['subtotal'],
                    'discount_total' => 0,
                    'total' => $demoOrder['total'],
                    'created_at' => $createdAt,
                    'updated_at' => now(),
                ]);

                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_name' => $demoOrder['item'],
                    'quantity' => $demoOrder['quantity'],
                    'unit_price' => $demoOrder['price'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! DB::table('vouchers')->where('user_id', $user->id)->exists()) {
            DB::table('vouchers')->insert([
                'user_id' => $user->id,
                'name' => 'FREE DRINK',
                'description' => 'Sample reward voucher for the demo account.',
                'points_cost' => 100,
                'discount_amount' => 0,
                'minimum_spend' => 0,
                'maximum_discount' => 12,
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
