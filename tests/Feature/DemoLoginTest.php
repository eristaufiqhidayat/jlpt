<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_login_is_off_by_default(): void
    {
        config(['app.demo_login' => false]);

        $this->get(route('login'))->assertOk()->assertDontSee('Demo masuk cepat');
        $this->post(route('login.demo', 'admin'))->assertNotFound();
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_each_role_can_log_in_when_enabled(): void
    {
        config(['app.demo_login' => true]);

        $this->get(route('login'))->assertOk()->assertSee('Demo masuk cepat')
            ->assertSee('Peserta')->assertSee('Admin Pendidikan')->assertSee('Direktur');

        foreach (config('app.demo_accounts') as $role => $email) {
            $this->post(route('login.demo', $role))->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs(User::where('email', $email)->first());
            $this->assertSame($role, auth()->user()->role->value);
            $this->post(route('logout'));
        }

        $this->assertSame(count(config('app.demo_accounts')), User::count());
    }

    public function test_demo_account_is_reused_not_duplicated(): void
    {
        config(['app.demo_login' => true]);
        $sensei = User::factory()->create(['email' => 'sensei@example.com', 'role' => Role::Sensei]);

        $this->post(route('login.demo', 'sensei'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($sensei);
        $this->assertSame(1, User::where('email', 'sensei@example.com')->count());
    }

    public function test_unknown_role_is_not_found(): void
    {
        config(['app.demo_login' => true]);

        $this->post(route('login.demo', 'superuser'))->assertNotFound();
        $this->assertGuest();
    }

    public function test_demo_email_with_a_different_role_is_refused(): void
    {
        config(['app.demo_login' => true]);
        User::factory()->create(['email' => 'sensei@example.com', 'role' => Role::Admin]);

        $this->post(route('login.demo', 'sensei'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_inactive_demo_account_is_refused(): void
    {
        config(['app.demo_login' => true]);
        User::factory()->create(['email' => 'student@example.com', 'role' => Role::Student, 'is_active' => false]);

        $this->post(route('login.demo', 'student'))->assertForbidden();
        $this->assertGuest();
    }
}
