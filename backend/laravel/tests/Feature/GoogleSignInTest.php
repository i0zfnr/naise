<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.brevo.api_key' => '', 'services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'https://naise.ryz.my.id/auth/google/callback']);
    }

    public function test_missing_google_credentials_returns_to_profile_instead_of_a_503(): void
    {
        config(['services.google.client_id' => '', 'services.google.client_secret' => '']);

        $this->get('/auth/google')->assertRedirect('/')->assertSessionHas('profile', true)->assertSessionHasErrors('google');
    }

    public function test_sign_in_redirects_directly_to_google_with_only_identity_scopes(): void
    {
        $response = $this->get('/auth/google')->assertRedirect();
        $url = $response->headers->get('Location');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('accounts.google.com', parse_url($url, PHP_URL_HOST));
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('https://naise.ryz.my.id/auth/google/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_google_login_saves_verified_name_email_and_requests_a_phone_number(): void
    {
        Http::preventStrayRequests();
        $this->fakeGoogle();

        $this->get('/auth/google/callback')->assertRedirect('/')->assertSessionHas('profile', true)->assertSessionHas('needs_phone', true);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['google_id' => 'google-subject-123', 'name' => 'Aina Google', 'email' => 'aina@gmail.com', 'whatsapp_number' => null]);
        $user = User::query()->sole();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->whatsapp_number);
        Http::assertNothingSent();
    }

    public function test_returning_google_customer_keeps_the_saved_number_and_has_no_duplicate_account(): void
    {
        $user = User::factory()->create(['google_id' => 'google-subject-123', 'email' => 'aina@gmail.com', 'name' => 'Old name', 'whatsapp_number' => '+60123456789']);
        $this->fakeGoogle();

        $this->get('/auth/google/callback')->assertRedirect('/')->assertSessionHas('needs_phone', false);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Aina Google', 'whatsapp_number' => '+60123456789']);
    }

    public function test_verified_google_email_links_existing_customer_without_losing_points(): void
    {
        $user = User::factory()->create(['email' => 'aina@gmail.com', 'google_id' => null, 'points' => 150]);
        $this->fakeGoogle();

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_id' => 'google-subject-123', 'points' => 150]);
    }

    #[TestWith([false])]
    #[TestWith(['false'])]
    public function test_unverified_google_email_cannot_create_an_account(bool|string $verified): void
    {
        $this->fakeGoogle($verified);

        $this->get('/auth/google/callback')->assertRedirect('/')->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_conflicting_google_subject_cannot_take_over_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'aina@gmail.com', 'google_id' => 'different-google-subject']);
        $this->fakeGoogle();

        $this->get('/auth/google/callback')->assertRedirect('/')->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_id' => 'different-google-subject']);
    }

    public function test_invalid_oauth_state_returns_a_visible_profile_error_without_signing_in(): void
    {
        Socialite::fake('google', function (): never {
            throw new InvalidStateException;
        });

        $this->get('/auth/google/callback')->assertRedirect('/')->assertSessionHas('profile', true)->assertSessionHasErrors('google');

        $this->assertGuest();
    }

    #[TestWith(['012-345 6789', '+60123456789'])]
    #[TestWith(['+60 12-345 6789', '+60123456789'])]
    #[TestWith(['60123456789', '+60123456789'])]
    #[TestWith(['12-345 6789', '+60123456789'])]
    #[TestWith(['0065 8123 4567', '+6581234567'])]
    public function test_phone_completion_saves_international_number_and_preserves_google_identity(string $phone, string $expected): void
    {
        $user = User::factory()->create(['google_id' => 'google-subject-123', 'name' => 'Aina Google', 'email' => 'aina@gmail.com', 'whatsapp_number' => null]);

        $this->actingAs($user)->putJson('/api/profile', ['name' => 'Spoofed name', 'email' => 'spoofed@example.test', 'whatsapp_number' => $phone])->assertOk()->assertJsonPath('user.whatsapp_number', $expected)->assertJsonPath('user.name', 'Aina Google');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Aina Google', 'email' => 'aina@gmail.com', 'whatsapp_number' => $expected]);
    }

    #[TestWith(['not-a-number'])]
    #[TestWith(['123'])]
    #[TestWith([['0123456789']])]
    public function test_invalid_phone_returns_422_without_completing_the_profile(string|array $phone): void
    {
        $user = User::factory()->create(['google_id' => 'google-subject-123', 'whatsapp_number' => null]);

        $this->actingAs($user)->putJson('/api/profile', ['whatsapp_number' => $phone])->assertUnprocessable()->assertJsonValidationErrors('whatsapp_number');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'whatsapp_number' => null]);
    }

    public function test_phone_completion_requires_sign_in_with_401(): void
    {
        $this->putJson('/api/profile', ['whatsapp_number' => '0123456789'])->assertUnauthorized();
    }

    public function test_profile_exposes_google_identity_and_the_required_phone_step(): void
    {
        $user = User::factory()->create(['google_id' => 'google-subject-123', 'whatsapp_number' => null]);

        $this->actingAs($user)->getJson('/api/profile')->assertOk()->assertJsonPath('needs_phone', true)->assertJsonPath('google_connected', true);
    }

    public function test_google_customer_orders_use_saved_identity_without_retyping_name_or_phone(): void
    {
        $user = User::factory()->create(['google_id' => 'google-subject-123', 'name' => 'Aina Google', 'whatsapp_number' => '+60123456789']);

        $id = $this->actingAs($user)->postJson('/api/orders', ['payment_method' => 'cash', 'customer_name' => 'Spoofed name', 'customer_phone' => '+6599999999', 'items' => [['name' => 'Butterbrew', 'quantity' => 1]]])->assertCreated()->json('order_id');

        $this->assertDatabaseHas('orders', ['id' => $id, 'user_id' => $user->id, 'customer_name' => 'Aina Google', 'customer_phone' => '+60123456789']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'whatsapp_number' => '+60123456789']);
    }

    public function test_customer_must_complete_phone_step_before_ordering_with_422(): void
    {
        $user = User::factory()->create(['google_id' => 'google-subject-123', 'whatsapp_number' => null]);

        $this->actingAs($user)->postJson('/api/orders', ['payment_method' => 'cash', 'items' => [['name' => 'Butterbrew', 'quantity' => 1]]])->assertUnprocessable()->assertJsonPath('message', 'Add your WhatsApp number to your profile before placing an order.');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_ready_order_has_a_free_whatsapp_link_with_customer_name_and_order_reference(): void
    {
        Http::preventStrayRequests();
        $id = DB::table('orders')->insertGetId(['customer_name' => 'Aina Google', 'customer_phone' => '+60123456789', 'status' => 'ready', 'subtotal' => 8, 'total' => 8, 'payment_method' => 'cash', 'payment_status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->withSession(['is_admin' => true, 'staff_last_activity' => now()->timestamp])->getJson('/api/admin/workspace')->assertOk();

        $this->assertSame('https://wa.me/60123456789?text='.rawurlencode('Hi Aina Google, your Naise order #'.$id.' is ready for pickup. See you soon!'), $response->json('orders.0.whatsapp_url'));
        Http::assertNothingSent();
    }

    private function fakeGoogle(bool|string $verified = true): void
    {
        $googleUser = (new GoogleUser)->setRaw(['email_verified' => $verified])->map(['id' => 'google-subject-123', 'name' => 'Aina Google', 'email' => 'Aina@gmail.com', 'avatar' => 'https://lh3.googleusercontent.com/example']);
        Socialite::fake('google', $googleUser);
    }
}
