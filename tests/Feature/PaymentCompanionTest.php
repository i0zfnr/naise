<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PaymentCompanionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.naise.admin_email' => 'owner@naise.test', 'services.naise.admin_password_hash' => Hash::make('test-admin-password')]);
    }

    public function test_device_login_stores_only_hashed_token_and_refuses_wrong_credentials(): void
    {
        $this->postJson('/api/companion/login', ['email' => 'owner@naise.test', 'password' => 'wrong', 'name' => 'Shop phone'])->assertUnauthorized();
        $token = $this->connect();
        $this->assertDatabaseHas('payment_devices', ['token_hash' => hash('sha256', $token), 'name' => 'Shop phone']);
        $this->assertDatabaseMissing('payment_devices', ['token_hash' => $token]);
        $this->assertDatabaseCount('payment_devices', 1);
    }

    public function test_notifications_require_unexpired_device_login_and_admin_password_changes_revoke_access(): void
    {
        $this->freezeTime();
        $this->postJson('/api/companion/events', $this->payload(1))->assertUnauthorized();
        $token = $this->connect();
        config(['services.naise.admin_password_hash' => Hash::make('changed-password')]);
        $this->postJson('/api/companion/events', $this->payload(1), $this->headers($token))->assertUnauthorized();
        $token = $this->connect('changed-password');
        $this->travel(30)->days();
        $this->postJson('/api/companion/events', $this->payload(1), $this->headers($token))->assertUnauthorized();
        $this->assertDatabaseCount('payment_notification_events', 0);
    }

    public function test_matching_notification_is_pending_staff_review_and_duplicate_is_idempotent(): void
    {
        $order = $this->order();
        $headers = $this->headers($this->connect());
        $data = $this->payload($order);
        $response = $this->postJson('/api/companion/events', $data, $headers)->assertOk()->assertJsonPath('status', 'matched');
        $this->postJson('/api/companion/events', $data, $headers)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertDatabaseCount('payment_notification_events', 1);
        $this->assertDatabaseHas('orders', ['id' => $order, 'payment_status' => 'pending', 'paid_at' => null]);
    }

    public function test_wrong_amount_unknown_order_and_old_notification_need_review(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $headers = $this->headers($this->connect());
        $data = $this->payload($order);
        $this->postJson('/api/companion/events', array_replace($data, ['amount_minor' => 900]), $headers)->assertOk()->assertJsonPath('status', 'review');
        $this->postJson('/api/companion/events', array_replace($data, ['reference' => 'NAISE-999999', 'fingerprint' => str_repeat('b', 64)]), $headers)->assertOk()->assertJsonPath('status', 'review');
        $this->postJson('/api/companion/events', array_replace($data, ['observed_at' => now()->subDays(2)->toIso8601String(), 'fingerprint' => str_repeat('c', 64)]), $headers)->assertOk()->assertJsonPath('status', 'review');
        $this->assertDatabaseHas('orders', ['id' => $order, 'payment_status' => 'pending']);
    }

    public function test_staff_confirmation_marks_paid_and_awards_points_only_once(): void
    {
        $member = User::factory()->create(['points' => 0]);
        $order = $this->order($member->id);
        $headers = $this->headers($this->connect());
        $event = $this->postJson('/api/companion/events', $this->payload($order), $headers)->assertOk()->json('id');
        $this->postJson("/api/companion/events/{$event}/confirm", [], $headers)->assertUnprocessable();
        $this->postJson("/api/companion/events/{$event}/confirm", ['checked_in_wallet' => true], $headers)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postJson("/api/companion/events/{$event}/confirm", ['checked_in_wallet' => true], $headers)->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order, 'payment_status' => 'paid', 'status' => 'paid', 'payment_reference' => 'notification-review:'.$event]);
        $this->assertSame(8, (int) $member->fresh()->points);
        $this->assertDatabaseHas('payment_notification_events', ['id' => $event, 'status' => 'confirmed']);
    }

    public function test_other_device_cannot_confirm_payment_and_disconnect_revokes_its_token(): void
    {
        $order = $this->order();
        $first = $this->headers($this->connect());
        $event = $this->postJson('/api/companion/events', $this->payload($order), $first)->assertOk()->json('id');
        $second = $this->headers($this->connect());
        $this->postJson("/api/companion/events/{$event}/confirm", ['checked_in_wallet' => true], $second)->assertNotFound();
        $this->postJson('/api/companion/logout', [], $first)->assertOk();
        $this->postJson('/api/companion/events', $this->payload($order), $first)->assertUnauthorized();
        $this->assertDatabaseHas('orders', ['id' => $order, 'payment_status' => 'pending']);
    }

    public function test_cannot_confirm_changed_closed_or_previously_paid_order(): void
    {
        $order = $this->order();
        $headers = $this->headers($this->connect());
        $event = $this->postJson('/api/companion/events', $this->payload($order), $headers)->assertOk()->json('id');
        DB::table('orders')->where('id', $order)->update(['total' => 9]);
        $this->postJson("/api/companion/events/{$event}/confirm", ['checked_in_wallet' => true], $headers)->assertConflict();
        DB::table('orders')->where('id', $order)->update(['total' => 8, 'status' => 'cancelled']);
        $this->postJson("/api/companion/events/{$event}/confirm", ['checked_in_wallet' => true], $headers)->assertConflict();
        DB::table('orders')->where('id', $order)->update(['status' => 'paid', 'payment_status' => 'paid']);
        $this->postJson("/api/companion/events/{$event}/confirm", ['checked_in_wallet' => true], $headers)->assertConflict();
    }

    public function test_reused_fingerprint_with_different_amount_is_rejected(): void
    {
        $order = $this->order();
        $headers = $this->headers($this->connect());
        $data = $this->payload($order);
        $this->postJson('/api/companion/events', $data, $headers)->assertOk();
        $this->postJson('/api/companion/events', array_replace($data, ['amount_minor' => 900]), $headers)->assertConflict();
        $this->assertDatabaseCount('payment_notification_events', 1);
    }

    public function test_invalid_references_and_future_times_cannot_create_event(): void
    {
        $this->freezeTime();
        $headers = $this->headers($this->connect());
        $this->postJson('/api/companion/events', array_replace($this->payload(1), ['reference' => 'NAISE-1 OR 2']), $headers)->assertUnprocessable();
        $this->postJson('/api/companion/events', array_replace($this->payload(1), ['observed_at' => now()->addHour()->toIso8601String()]), $headers)->assertUnprocessable();
        $this->assertDatabaseCount('payment_notification_events', 0);
    }

    public function test_device_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/companion/login', ['email' => 'owner@naise.test', 'password' => 'wrong', 'name' => 'Shop phone'])->assertUnauthorized();
        }
        $this->postJson('/api/companion/login', ['email' => 'owner@naise.test', 'password' => 'wrong', 'name' => 'Shop phone'])->assertTooManyRequests();
        $this->assertDatabaseCount('payment_devices', 0);
    }

    private function connect(string $password = 'test-admin-password'): string
    {
        return $this->postJson('/api/companion/login', ['email' => 'owner@naise.test', 'password' => $password, 'name' => 'Shop phone'])->assertOk()->json('token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function order(?int $user = null): int
    {
        return DB::table('orders')->insertGetId(['user_id' => $user, 'customer_name' => 'Test customer', 'customer_phone' => '+60123456789', 'status' => 'awaiting_payment', 'payment_method' => 'duitnow_qr', 'payment_status' => 'pending', 'subtotal' => 8, 'discount_total' => 0, 'total' => 8, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(int $order): array
    {
        return ['reference' => 'NAISE-'.$order, 'amount_minor' => 800, 'fingerprint' => str_repeat('a', 64), 'observed_at' => now()->toIso8601String()];
    }
}
