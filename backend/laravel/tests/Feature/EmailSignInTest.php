<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.brevo.api_key' => 'test-email-key', 'services.brevo.sender_email' => 'hello@naise.test']);
    }

    public function test_sign_in_emails_a_code_and_stores_only_its_hash(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'test-id'], 201)]);

        $response = $this->postJson('/api/auth/email/code', ['email' => 'Aina@gmail.com'])->assertOk()->assertJsonPath('expires_in', 600)->assertJsonMissingPath('code');

        $record = DB::table('email_login_challenges')->sole();
        $this->assertSame('aina@gmail.com', $record->email);
        $this->assertSame(hash('sha256', $response->json('challenge')), $record->id);
        $this->assertSame(now()->addMinutes(10)->toDateTimeString(), $record->expires_at);
        $this->assertTrue(Hash::check($this->sentCode(), $record->code_hash));
        $this->assertDatabaseCount('users', 0);
        Http::assertSent(fn ($request): bool => $request['to'][0]['email'] === 'aina@gmail.com' && $request->hasHeader('api-key', 'test-email-key'));
    }

    public function test_valid_email_code_creates_verified_customer_and_requests_name_and_phone(): void
    {
        [$challenge, $code] = $this->requestCode();

        $this->postJson('/api/auth/email/verify', ['challenge' => $challenge, 'code' => $code])->assertOk()->assertJsonPath('needs_profile', true);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'aina@gmail.com', 'name' => '', 'whatsapp_number' => null]);
        $this->assertNotNull(User::query()->sole()->email_verified_at);
        $this->assertDatabaseMissing('email_login_challenges', ['used_at' => null]);
        Http::assertSentCount(1);
    }

    public function test_returning_customer_keeps_saved_name_number_and_balances(): void
    {
        $user = User::factory()->create(['email' => 'aina@gmail.com', 'name' => 'Aina', 'whatsapp_number' => '+60123456789', 'points' => 90]);
        [$challenge, $code] = $this->requestCode();

        $this->postJson('/api/auth/email/verify', ['challenge' => $challenge, 'code' => $code])->assertOk()->assertJsonPath('needs_profile', false);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Aina', 'whatsapp_number' => '+60123456789', 'points' => 90]);
    }

    public function test_wrong_code_returns_422_and_records_the_failed_attempt(): void
    {
        [$challenge, $code] = $this->requestCode();
        $wrong = $code === '111111' ? '222222' : '111111';

        $this->postJson('/api/auth/email/verify', ['challenge' => $challenge, 'code' => $wrong])->assertUnprocessable();

        $this->assertGuest();
        $this->assertDatabaseHas('email_login_challenges', ['id' => hash('sha256', $challenge), 'attempts' => 1, 'used_at' => null]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_code_locks_after_five_failed_attempts_with_422(): void
    {
        [$challenge, $code] = $this->requestCode();
        $wrong = $code === '111111' ? '222222' : '111111';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/email/verify', ['challenge' => $challenge, 'code' => $wrong])->assertUnprocessable();
        }

        $this->postJson('/api/auth/email/verify', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();

        $this->assertGuest();
        $this->assertDatabaseHas('email_login_challenges', ['id' => hash('sha256', $challenge), 'attempts' => 5]);
    }

    public function test_expired_code_returns_422_without_creating_account(): void
    {
        $this->freezeTime();
        [$challenge, $code] = $this->requestCode();
        $this->travel(10)->minutes();

        $this->postJson('/api/auth/email/verify', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_code_cannot_be_reused(): void
    {
        [$challenge, $code] = $this->requestCode();
        $this->postJson('/api/auth/email/verify', ['challenge' => $challenge, 'code' => $code])->assertOk();

        $this->postJson('/api/auth/email/verify', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_resending_invalidates_the_previous_code_challenge(): void
    {
        $this->freezeTime();
        [$previous, $code] = $this->requestCode();
        $this->travel(61)->seconds();
        [$latest] = $this->requestCode();

        $this->postJson('/api/auth/email/verify', ['challenge' => $previous, 'code' => $code])->assertUnprocessable();

        $this->assertDatabaseCount('email_login_challenges', 1);
        $this->assertDatabaseHas('email_login_challenges', ['id' => hash('sha256', $latest), 'attempts' => 0]);
        $this->assertDatabaseMissing('email_login_challenges', ['id' => hash('sha256', $previous)]);
    }

    public function test_resending_too_soon_is_rate_limited_with_429(): void
    {
        $this->requestCode();

        $this->postJson('/api/auth/email/code', ['email' => 'aina@gmail.com'])->assertTooManyRequests();

        Http::assertSentCount(1);
    }

    public function test_missing_email_configuration_returns_503_without_pretending_the_code_was_sent(): void
    {
        config(['services.brevo.api_key' => '']);
        Http::preventStrayRequests();

        $this->postJson('/api/auth/email/code', ['email' => 'aina@gmail.com'])->assertServiceUnavailable();

        $this->assertDatabaseCount('email_login_challenges', 0);
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_email_provider_failure_returns_503_and_removes_the_unsent_code(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response([], 500)]);

        $this->postJson('/api/auth/email/code', ['email' => 'aina@gmail.com'])->assertServiceUnavailable();

        $this->assertDatabaseCount('email_login_challenges', 0);
    }

    public function test_invalid_email_returns_422_without_sending(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/api/auth/email/code', ['email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('email_login_challenges', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_challenge_returns_422_without_authentication(): void
    {
        $this->postJson('/api/auth/email/verify', ['challenge' => Str::random(64), 'code' => '123456'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_email_customer_saves_name_and_whatsapp_once(): void
    {
        $user = User::factory()->create(['name' => '', 'whatsapp_number' => null]);

        $this->actingAs($user)->putJson('/api/profile', ['name' => 'Aina', 'whatsapp_number' => '0123456789'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Aina', 'whatsapp_number' => '+60123456789']);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('needs_profile', false);
    }

    private function requestCode(): array
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'test-id'], 201)]);
        $response = $this->postJson('/api/auth/email/code', ['email' => 'aina@gmail.com'])->assertOk();

        return [$response->json('challenge'), $this->sentCode()];
    }

    private function sentCode(): string
    {
        $request = Http::recorded()->last()[0];
        preg_match('/code is ([0-9]{6})/', $request['textContent'], $matches);

        return $matches[1];
    }
}
