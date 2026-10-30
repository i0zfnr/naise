<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminWorkspace;
use App\Services\LoyaltyPointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['/admin/dashboard'])]
    #[TestWith(['/admin/menu'])]
    #[TestWith(['/admin/promotions'])]
    #[TestWith(['/admin/rewards'])]
    #[TestWith(['/admin/customers'])]
    #[TestWith(['/admin/reports'])]
    #[TestWith(['/manage'])]
    #[TestWith(['/shift'])]
    public function test_admin_pages_require_sign_in(string $path): void
    {
        $this->get($path)->assertRedirect('/admin');
    }

    public function test_workspace_data_refuses_customer_sessions_with_401(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/workspace')->assertUnauthorized();
    }

    public function test_workspace_write_refuses_unauthorized_requests_with_401(): void
    {
        $this->putJson('/api/admin/workspace/menu', ['value' => []])->assertUnauthorized();
        $this->assertDatabaseMissing('admin_documents', ['key' => 'menu']);
    }

    public function test_workspace_lists_real_orders_and_customers_without_passwords(): void
    {
        $member = User::factory()->create();
        $order = $this->createOrder(['user_id' => $member->id]);
        $this->admin();

        $response = $this->getJson('/api/admin/workspace')->assertOk()->assertJsonPath('orders.0.id', $order)->assertJsonPath('customers.0.id', $member->id);

        $this->assertArrayNotHasKey('password', $response->json('customers.0'));
        $this->assertArrayNotHasKey('receipt_path', $response->json('orders.0'));
    }

    public function test_menu_changes_persist_and_hidden_items_cannot_be_ordered(): void
    {
        $this->admin();
        $menu = app(AdminWorkspace::class)->menu();
        $menu[0]['available'] = false;
        $this->putJson('/api/admin/workspace/menu', ['value' => $menu])->assertOk();

        $this->getJson('/api/menu?search=Butterbrew')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/orders', $this->orderPayload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_invalid_price_returns_422_and_preserves_the_menu(): void
    {
        $this->admin();
        $menu = app(AdminWorkspace::class)->menu();
        $menu[0]['price'] = -5;

        $this->putJson('/api/admin/workspace/menu', ['value' => $menu])->assertUnprocessable()->assertJsonValidationErrors('value.0.price');
        $this->assertDatabaseMissing('admin_documents', ['key' => 'menu']);
    }

    public function test_unexpected_configuration_keys_return_422(): void
    {
        $this->admin();
        $value = app(AdminWorkspace::class)->rewards();
        $value['admin_password'] = 'unsafe';

        $this->putJson('/api/admin/workspace/rewards', ['value' => $value])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->assertDatabaseMissing('admin_documents', ['key' => 'rewards']);
    }

    public function test_rewards_settings_apply_to_future_payments_only(): void
    {
        $this->admin();
        $member = User::factory()->create(['points' => 15, 'total_points' => 15]);
        $settings = app(AdminWorkspace::class)->rewards();
        $settings['beans_per_rm'] = 10;
        $this->putJson('/api/admin/workspace/rewards', ['value' => $settings])->assertOk();
        $order = $this->createOrder(['user_id' => $member->id]);

        $this->post('/staff/orders/'.$order.'/mark-cash-received')->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $member->id, 'points' => 95, 'total_points' => 95, 'stamp_count' => 1]);
        $this->assertDatabaseHas('orders', ['id' => $order, 'points_earned' => 80]);
    }

    public function test_stamp_milestones_issue_one_voucher_even_when_payment_is_retried(): void
    {
        $this->freezeTime();
        $member = User::factory()->create(['stamp_count' => 3]);
        $order = $this->createOrder(['user_id' => $member->id, 'payment_status' => 'paid', 'paid_at' => now()]);
        $service = app(LoyaltyPointsService::class);

        $service->awardForPaidOrder($order);
        $service->awardForPaidOrder($order);

        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseHas('vouchers', ['user_id' => $member->id, 'discount_amount' => 5, 'minimum_spend' => 11, 'expires_at' => now()->addDays(30)->toDateTimeString()]);
        $this->assertDatabaseHas('users', ['id' => $member->id, 'stamp_count' => 4]);
    }

    public function test_stamp_settings_reject_milestones_larger_than_the_card_with_422(): void
    {
        $this->admin();
        $stamps = app(AdminWorkspace::class)->stamps();
        $stamps['reward_at'] = 9;

        $this->putJson('/api/admin/workspace/stamps', ['value' => $stamps])->assertUnprocessable()->assertJsonValidationErrors('value.reward_at');
        $this->assertDatabaseMissing('admin_documents', ['key' => 'stamps']);
    }

    public function test_active_promotion_discounts_new_orders_using_server_prices(): void
    {
        $this->freezeTime();
        $this->admin();
        $promotion = ['id' => 'test-promo', 'name' => 'Coffee hour', 'type' => 'percent', 'amount' => 25, 'min_spend' => 8, 'starts_at' => now()->subHour()->toISOString(), 'ends_at' => now()->addHour()->toISOString(), 'enabled' => true];
        $this->putJson('/api/admin/workspace/promotions', ['value' => [$promotion]])->assertOk();

        $order = $this->postJson('/api/orders', $this->orderPayload())->assertCreated()->assertJsonPath('discount_total', 2)->assertJsonPath('total', 6)->json('order_id');

        $this->assertDatabaseHas('orders', ['id' => $order, 'subtotal' => 8, 'discount_total' => 2, 'total' => 6]);
    }

    public function test_expired_promotions_do_not_discount_orders(): void
    {
        $this->freezeTime();
        app(AdminWorkspace::class)->put('promotions', [['id' => 'ended', 'name' => 'Ended', 'type' => 'fixed', 'amount' => 5, 'min_spend' => 0, 'starts_at' => now()->subDays(2)->toISOString(), 'ends_at' => now()->subDay()->toISOString(), 'enabled' => true]]);

        $this->postJson('/api/orders', $this->orderPayload())->assertCreated()->assertJsonPath('total', 8);
    }

    public function test_shift_count_includes_float_cash_sales_and_signed_movements(): void
    {
        $this->freezeTime();
        $this->admin();
        $this->postJson('/api/admin/shifts', ['action' => 'open', 'amount' => 50])->assertOk();
        $this->createOrder(['payment_status' => 'paid', 'paid_at' => now(), 'total' => 8]);
        $this->createOrder(['payment_status' => 'paid', 'paid_at' => now(), 'payment_method' => 'duitnow_qr', 'total' => 12]);
        $this->postJson('/api/admin/shifts', ['action' => 'movement', 'type' => 'cash_in', 'amount' => 10, 'note' => 'Float top-up'])->assertOk();
        $this->postJson('/api/admin/shifts', ['action' => 'movement', 'type' => 'qr_to_cash', 'amount' => 5])->assertOk();
        $this->getJson('/api/admin/workspace')->assertOk()->assertJsonPath('shifts.0.expected', 63)->assertJsonPath('shifts.0.qr_sales', 12);

        $this->postJson('/api/admin/shifts', ['action' => 'close', 'amount' => 60])->assertOk();

        $closed = app(AdminWorkspace::class)->get('shifts')[0];
        $this->assertSame(63, $closed['expected']);
        $this->assertSame(60, $closed['counted']);
        $this->assertNotNull($closed['closed_at']);
    }

    public function test_second_open_shift_is_rejected_with_422(): void
    {
        $this->admin();
        $this->postJson('/api/admin/shifts', ['action' => 'open', 'amount' => 50])->assertOk();

        $this->postJson('/api/admin/shifts', ['action' => 'open', 'amount' => 10])->assertUnprocessable();
        $this->assertCount(1, app(AdminWorkspace::class)->get('shifts'));
    }

    public function test_shift_excludes_payments_already_received_in_the_same_second(): void
    {
        $this->freezeTime();
        $this->admin();
        $this->createOrder(['payment_status' => 'paid', 'paid_at' => now(), 'total' => 8]);
        $this->postJson('/api/admin/shifts', ['action' => 'open', 'amount' => 50])->assertOk();

        $this->getJson('/api/admin/workspace')->assertOk()->assertJsonPath('shifts.0.expected', 50)->assertJsonPath('shifts.0.cash_sales', 0);
    }

    public function test_movement_without_open_shift_returns_422(): void
    {
        $this->admin();

        $this->postJson('/api/admin/shifts', ['action' => 'movement', 'type' => 'cash_out', 'amount' => 5])->assertUnprocessable();
        $this->assertSame([], app(AdminWorkspace::class)->get('shifts'));
    }

    public function test_payment_correction_requires_manager_passcode_and_preserves_paid_state(): void
    {
        config(['services.naise.store_passcode_hash' => Hash::make('2468')]);
        $this->admin();
        $order = $this->createOrder(['payment_status' => 'paid', 'paid_at' => now()]);
        $this->postJson('/api/admin/orders/'.$order, ['action' => 'payment', 'method' => 'duitnow_qr', 'passcode' => 'bad'])->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $order, 'payment_method' => 'cash']);

        $this->postJson('/api/admin/orders/'.$order, ['action' => 'payment', 'method' => 'duitnow_qr', 'passcode' => '2468'])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order, 'payment_method' => 'duitnow_qr', 'payment_status' => 'paid']);
    }

    public function test_drink_updates_cannot_modify_another_orders_items(): void
    {
        $this->admin();
        $first = $this->createOrder();
        $second = $this->createOrder();
        $item = DB::table('order_items')->where('order_id', $second)->value('id');

        $this->postJson('/api/admin/orders/'.$first, ['action' => 'item', 'item_id' => $item, 'status' => 'ready'])->assertNotFound();
        $this->assertDatabaseHas('order_items', ['id' => $item, 'preparation_status' => 'queued']);
    }

    public function test_only_ready_and_paid_orders_can_be_completed(): void
    {
        $this->admin();
        $order = $this->createOrder();
        $item = DB::table('order_items')->where('order_id', $order)->value('id');
        $this->postJson('/api/admin/orders/'.$order, ['action' => 'item', 'item_id' => $item, 'status' => 'ready'])->assertOk();
        $this->postJson('/api/admin/orders/'.$order, ['action' => 'complete'])->assertUnprocessable();
        $this->post('/staff/orders/'.$order.'/mark-cash-received')->assertRedirect();

        $this->postJson('/api/admin/orders/'.$order, ['action' => 'complete'])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'completed']);
    }

    public function test_member_attachment_awards_paid_guest_order_once(): void
    {
        $this->admin();
        $member = User::factory()->create(['points' => 0, 'total_points' => 0]);
        $order = $this->createOrder(['payment_status' => 'paid', 'paid_at' => now()]);
        $this->postJson('/api/admin/orders/'.$order, ['action' => 'attach', 'user_id' => $member->id])->assertOk();

        $this->postJson('/api/admin/orders/'.$order, ['action' => 'attach', 'user_id' => $member->id])->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $member->id, 'points' => 8, 'stamp_count' => 1]);
    }

    public function test_paid_order_cannot_be_cancelled_without_refund_review(): void
    {
        $this->admin();
        $order = $this->createOrder(['payment_status' => 'paid', 'paid_at' => now()]);

        $this->postJson('/api/admin/orders/'.$order, ['action' => 'cancel'])->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'received']);
    }

    private function admin(): void
    {
        $this->withSession(['is_admin' => true, 'staff_last_activity' => now()->timestamp]);
    }

    private function createOrder(array $overrides = []): int
    {
        $id = DB::table('orders')->insertGetId([...['status' => 'received', 'subtotal' => 8, 'total' => 8, 'payment_method' => 'cash', 'payment_status' => 'cash_on_pickup', 'created_at' => now(), 'updated_at' => now()], ...$overrides]);
        DB::table('order_items')->insert(['order_id' => $id, 'product_name' => 'Butterbrew', 'quantity' => 1, 'unit_price' => 8, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function orderPayload(): array
    {
        return ['items' => [['name' => 'Butterbrew', 'quantity' => 1]], 'payment_method' => 'cash', 'customer_name' => 'Test Customer', 'customer_phone' => '+60123456789'];
    }
}
