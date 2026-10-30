<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_requires_email_verification(): void
    {
        $this->postJson('/api/auth/signup', $this->details())->assertUnprocessable();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_signup_creates_normalized_customer_with_hashed_password(): void
    {
        $this->verifySignup();
        $this->postJson('/api/auth/signup', $this->details() + ['email' => 'attacker@test.com', 'points' => 9999])->assertCreated();
        $user = User::query()->sole();
        $this->assertSame('aina@gmail.com', $user->email);
        $this->assertSame('+60123456789', $user->whatsapp_number);
        $this->assertTrue(Hash::check('coffee-time-2026', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, (int) $user->points);
        $this->assertAuthenticatedAs($user);
        $this->postJson('/api/auth/signup', $this->details())->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        Http::assertSentCount(1);
    }

    public function test_code_signup_can_create_account_without_password(): void
    {
        $this->verifySignup();
        $this->postJson('/api/auth/signup', ['name' => 'Aina', 'whatsapp_number' => '0123456789'])->assertCreated();
        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_signup_verification_expires_before_completion(): void
    {
        $this->freezeTime();
        $this->verifySignup();
        $this->travel(10)->minutes();
        $this->postJson('/api/auth/signup', $this->details())->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_signup_rejects_invalid_phone_and_unconfirmed_password(): void
    {
        $this->verifySignup();
        $this->postJson('/api/auth/signup', array_replace($this->details(), ['whatsapp_number' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors('whatsapp_number');
        $this->postJson('/api/auth/signup', array_replace($this->details(), ['password_confirmation' => 'wrong']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/signup', ['whatsapp_number' => '0123456789'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_signin_rejects_unknown_email_without_sending_code_or_creating_account(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/api/auth/email/code', ['email' => 'unknown@gmail.com', 'purpose' => 'signin'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_signup_cannot_replace_existing_account(): void
    {
        $this->verifySignup();
        $user = User::factory()->create(['email' => 'aina@gmail.com']);
        $this->postJson('/api/auth/signup', $this->details())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertGuest();
    }

    public function test_signup_code_request_rejects_registered_email(): void
    {
        User::factory()->create(['email' => 'aina@gmail.com']);
        Http::preventStrayRequests();
        $this->postJson('/api/auth/email/code', ['email' => 'AINA@gmail.com', 'purpose' => 'signup'])->assertUnprocessable()->assertJsonValidationErrors('email');
        Http::assertNothingSent();
    }

    public function test_password_signin_keeps_existing_customer_data(): void
    {
        $user = User::factory()->create(['email' => 'aina@gmail.com', 'password' => 'coffee-time-2026', 'points' => 80]);
        $this->postJson('/api/auth/password', ['email' => 'AINA@gmail.com', 'password' => 'coffee-time-2026'])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'points' => 80]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_signin_rejects_wrong_password_and_unverified_account(): void
    {
        User::factory()->create(['email' => 'aina@gmail.com', 'password' => 'coffee-time-2026', 'email_verified_at' => null]);
        $this->postJson('/api/auth/password', ['email' => 'aina@gmail.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/auth/password', ['email' => 'aina@gmail.com', 'password' => 'coffee-time-2026'])->assertUnprocessable();
        $this->postJson('/api/auth/password', ['email' => 'unknown@gmail.com', 'password' => 'coffee-time-2026'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_password_signin_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/password', ['email' => 'aina@gmail.com', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/password', ['email' => 'aina@gmail.com', 'password' => 'wrong'])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_verified_customer_cannot_sign_in_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'aina@gmail.com', 'password' => 'coffee-time-2026']);
        $this->postJson('/api/auth/password', ['email' => 'aina@gmail.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_deleted_customer_cannot_be_recreated_by_signin_code(): void
    {
        $user = User::factory()->create(['email' => 'aina@gmail.com']);
        config(['services.brevo.api_key' => 'test-key', 'services.brevo.sender_email' => 'hello@naise.test']);
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'test'], 201)]);
        $response = $this->postJson('/api/auth/email/code', ['email' => 'aina@gmail.com', 'purpose' => 'signin'])->assertOk();
        preg_match('/code is ([0-9]{6})/', Http::recorded()->last()[0]['textContent'], $matches);
        $user->delete();
        $this->postJson('/api/auth/email/verify', ['challenge' => $response->json('challenge'), 'code' => $matches[1], 'purpose' => 'signup'])->assertUnprocessable();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/auth/signup', $this->details())->assertUnprocessable();
    }

    private function verifySignup(): void
    {
        config(['services.brevo.api_key' => 'test-key', 'services.brevo.sender_email' => 'hello@naise.test']);
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'test'], 201)]);
        $response = $this->postJson('/api/auth/email/code', ['email' => 'Aina@gmail.com', 'purpose' => 'signup'])->assertOk();
        preg_match('/code is ([0-9]{6})/', Http::recorded()->last()[0]['textContent'], $matches);
        $this->postJson('/api/auth/email/verify', ['challenge' => $response->json('challenge'), 'code' => $matches[1]])->assertOk()->assertJsonPath('needs_signup', true);
        $this->assertGuest();
    }

    private function details(): array
    {
        return ['name' => 'Aina', 'whatsapp_number' => '0123456789', 'password' => 'coffee-time-2026', 'password_confirmation' => 'coffee-time-2026'];
    }
}
