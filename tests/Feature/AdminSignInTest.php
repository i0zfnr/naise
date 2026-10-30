<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.naise.admin_email' => 'owner@naise.test', 'services.naise.admin_password_hash' => Hash::make('test-admin-password')]);
    }

    public function test_correct_admin_credentials_open_dashboard_and_set_admin_session(): void
    {
        $this->post('/admin/login', ['email' => 'OWNER@naise.test', 'password' => 'test-admin-password'])
            ->assertRedirect('/admin/dashboard')->assertSessionHas('is_admin', true);
        $this->get('/admin/dashboard')->assertOk();
    }

    public function test_customer_password_does_not_grant_admin_access(): void
    {
        $this->from('/admin')->post('/admin/login', ['email' => 'owner@naise.test', 'password' => 'wrong'])
            ->assertRedirect('/admin')->assertSessionHasErrors('email')->assertSessionMissing('is_admin');
    }

    public function test_failed_attempts_show_login_form_with_429_and_retry_after_then_allow_login_after_wait(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/admin')->post('/admin/login', ['email' => 'owner@naise.test', 'password' => 'wrong'])->assertRedirect('/admin');
        }
        $this->post('/admin/login', ['email' => 'owner@naise.test', 'password' => 'test-admin-password'])
            ->assertTooManyRequests()->assertViewIs('admin.login')->assertHeader('Retry-After', '60')
            ->assertSee('Too many unsuccessful sign-in attempts.')->assertSee('owner@naise.test')->assertDontSee('test-admin-password');
        $this->assertFalse((bool) session('is_admin'));
        $this->get('/admin')->assertOk();
        $this->travel(61)->seconds();
        $this->post('/admin/login', ['email' => 'owner@naise.test', 'password' => 'test-admin-password'])
            ->assertRedirect('/admin/dashboard')->assertSessionHas('is_admin', true);
    }

    public function test_successful_logins_do_not_consume_failed_attempt_limit(): void
    {
        for ($attempt = 0; $attempt < 7; $attempt++) {
            $this->post('/admin/login', ['email' => 'owner@naise.test', 'password' => 'test-admin-password'])->assertRedirect('/admin/dashboard');
            $this->post('/admin/logout')->assertRedirect('/admin');
        }
    }

    public function test_demo_attempts_do_not_lock_out_administrator(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/demo/login', ['email' => 'owner@naise.test', 'password' => 'wrong'])->assertNotFound();
        }
        $this->post('/admin/login', ['email' => 'owner@naise.test', 'password' => 'test-admin-password'])
            ->assertRedirect('/admin/dashboard')->assertSessionHas('is_admin', true);
    }

    public function test_admin_login_get_redirects_to_form_without_intercepting_post(): void
    {
        $this->get('/admin/login')->assertRedirect('/admin');
        $this->post('/admin/login', ['email' => 'owner@naise.test', 'password' => 'test-admin-password'])->assertRedirect('/admin/dashboard');
    }
}
