<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderPaymentAndPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_order_is_confirmed_by_staff_points_are_awarded_and_delete_archives_it(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer);

        $orderResponse = $this->postJson('/api/orders', [
            'items' => [['name' => 'Butterbrew', 'quantity' => 2]],
            'payment_method' => 'cash',
            'customer_name' => 'Test Customer',
            'customer_phone' => '+60 12-345 6789',
        ])->assertCreated();

        $orderId = $orderResponse->json('order_id');
        $this->withSession(['is_admin' => true, 'staff_last_activity' => now()->timestamp])
            ->post("/staff/orders/{$orderId}/mark-cash-received")
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => 'paid', 'points_earned' => 16]);
        $this->assertSame(16, $customer->fresh()->points);
        $this->assertSame(16, $customer->fresh()->total_points);

        $staffSession = ['is_admin' => true, 'staff_last_activity' => now()->timestamp];
        $this->withSession($staffSession)->post("/staff/orders/{$orderId}/mark-ready")->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'ready', 'payment_status' => 'paid']);
        $this->withSession($staffSession)->get('/staff/orders')
            ->assertOk()
            ->assertSee('OPEN WHATSAPP · SEND READY NOTICE')
            ->assertSee('https://wa.me/60123456789?text=', false);

        $this->delete("/staff/orders/{$orderId}")->assertRedirect();
        $this->assertDatabaseMissing('orders', ['id' => $orderId, 'deleted_at' => null]);
        $this->assertSame(16, $customer->fresh()->points);
    }

    public function test_signed_qr_payment_webhook_confirms_order_and_awards_points_only_once(): void
    {
        $customer = $this->customer();
        $this->configureQr();
        config(['services.naise.payment_webhook_secret' => 'test-webhook-secret']);
        $this->actingAs($customer);

        $orderId = $this->postJson('/api/orders', [
            'items' => [['name' => 'Butterbrew', 'quantity' => 1]],
            'payment_method' => 'duitnow_qr',
            'customer_name' => 'Test Customer',
            'customer_phone' => '+60 12-345 6789',
        ])->assertCreated()->json('order_id');

        $payload = [
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => '8.00',
            'payment_reference' => 'test-payment-'.$orderId,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, 'test-webhook-secret');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->call('POST', '/api/payments/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_NAISE_SIGNATURE' => $signature,
            ], $body)->assertOk();
        }

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => 'paid', 'points_earned' => 8]);
        $this->assertSame(8, $customer->fresh()->points);
        $this->assertSame(8, $customer->fresh()->total_points);
    }

    public function test_wrong_qr_amount_does_not_confirm_or_award_points(): void
    {
        $customer = $this->customer();
        $this->configureQr();
        config(['services.naise.payment_webhook_secret' => 'test-webhook-secret']);
        $this->actingAs($customer);

        $orderId = $this->postJson('/api/orders', [
            'items' => [['name' => 'Butterbrew', 'quantity' => 1]],
            'payment_method' => 'duitnow_qr',
            'customer_name' => 'Test Customer',
            'customer_phone' => '+60 12-345 6789',
        ])->assertCreated()->json('order_id');

        $payload = ['order_id' => $orderId, 'status' => 'paid', 'amount' => '8.01', 'payment_reference' => 'wrong-amount-'.$orderId];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_NAISE_SIGNATURE' => hash_hmac('sha256', $body, 'test-webhook-secret'),
        ], $body)->assertUnprocessable();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => 'pending', 'points_earned' => 0]);
        $this->assertSame(0, $customer->fresh()->points);
    }

    public function test_redeeming_one_hundred_available_points_creates_a_free_drink_voucher(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['points' => 100, 'total_points' => 100])->save();
        $this->actingAs($customer);

        $this->postJson('/api/vouchers/redeem')
            ->assertCreated()
            ->assertJsonPath('points', 0);

        $this->assertSame(0, $customer->fresh()->points);
        $this->assertSame(100, $customer->fresh()->redeemed_points);
        $this->assertDatabaseHas('vouchers', ['user_id' => $customer->id, 'points_cost' => 100, 'status' => 'available']);
    }

    public function test_thirty_and_two_hundred_paid_cups_credit_points_by_paid_ringgit(): void
    {
        $customer = $this->customer();
        $this->configureQr();
        config(['services.naise.payment_webhook_secret' => 'test-webhook-secret']);
        $this->actingAs($customer);

        $cupCountTotal = 0;
        foreach ([30, 200] as $cupCount) {
            foreach (array_chunk(array_fill(0, $cupCount, 1), 99) as $cupBatch) {
                $quantity = count($cupBatch);
                $orderId = DB::table('orders')->insertGetId([
                    'user_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_phone' => '+60 12-345 6789',
                    'status' => 'awaiting_payment',
                    'payment_method' => 'duitnow_qr',
                    'payment_status' => 'pending',
                    'subtotal' => 8 * $quantity,
                    'discount_total' => 0,
                    'total' => 8 * $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_name' => 'Butterbrew',
                    'quantity' => $quantity,
                    'unit_price' => 8,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $payload = ['order_id' => $orderId, 'status' => 'paid', 'amount' => number_format(8 * $quantity, 2, '.', ''), 'payment_reference' => 'volume-'.$orderId];
                $body = json_encode($payload, JSON_THROW_ON_ERROR);
                $this->call('POST', '/api/payments/webhook', [], [], [], [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_NAISE_SIGNATURE' => hash_hmac('sha256', $body, 'test-webhook-secret'),
                ], $body)->assertOk();
                $cupCountTotal += $quantity;
            }
        }

        $this->assertSame(230, $cupCountTotal);
        $this->assertSame(1840, $customer->fresh()->points);
        $this->assertSame(1840, $customer->fresh()->total_points);
        $this->assertSame(4, DB::table('orders')->where('payment_status', 'paid')->count());
    }

    private function customer(): User
    {
        return User::query()->create([
            'name' => 'Naise Test Customer',
            'email' => 'customer@example.test',
            'password' => Hash::make('test-password'),
            'points' => 0,
            'total_points' => 0,
            'redeemed_points' => 0,
            'whatsapp_number' => '+60 12-345 6789',
        ]);
    }

    private function configureQr(): void
    {
        DB::table('payment_settings')->insert([
            'id' => 1,
            'merchant_name' => 'Naise Test QR',
            'qr_code_path' => 'payment-qr/test.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
