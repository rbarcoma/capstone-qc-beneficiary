<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): User
    {
        return User::factory()->role('energy_manager')->unverified()->create([
            'password' => 'Temporary-Only-458!Ab', 'must_change_password' => true,
            'temporary_password_expires_at' => now()->addHour(),
        ]);
    }

    public function test_temporary_login_is_limited_to_setup_and_replacement_requires_a_new_strong_password(): void
    {
        $user = $this->pending();
        $this->post('/login', ['email' => $user->email, 'password' => 'Temporary-Only-458!Ab'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertRedirect(route('password.setup'));
        $this->getJson('/reports')->assertForbidden();
        $this->postJson('/preprocessing/build')->assertForbidden();
        $this->get(route('password.setup'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/password-setup'));
        $this->putJson(route('password.setup.update'), ['current_password' => 'wrong', 'password' => 'New-Private-Password-45!', 'password_confirmation' => 'New-Private-Password-45!'])->assertUnprocessable();
        $this->putJson(route('password.setup.update'), ['current_password' => 'Temporary-Only-458!Ab', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertUnprocessable();
        $this->put(route('password.setup.update'), ['current_password' => 'Temporary-Only-458!Ab', 'password' => 'New-Private-Password-45!', 'password_confirmation' => 'New-Private-Password-45!'])->assertRedirect('/login');
        $this->assertGuest();
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->temporary_password_expires_at);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('New-Private-Password-45!', $user->password));
        $this->post('/login', ['email' => $user->email, 'password' => 'New-Private-Password-45!'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertOk();
        $this->assertStringNotContainsString('New-Private-Password-45!', json_encode(AuditLog::all()->toArray()));
    }

    public function test_expired_temporary_credentials_cannot_login_or_continue_an_existing_session(): void
    {
        $user = $this->pending();
        $user->forceFill(['temporary_password_expires_at' => now()->subMinute()])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'Temporary-Only-458!Ab'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->actingAs($user)->getJson(route('password.setup'))->assertUnauthorized();
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_admin_can_renew_expired_credentials_once_without_revealing_them_in_history(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->pending();
        $user->forceFill(['temporary_password_expires_at' => now()->subMinute()])->save();
        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->postJson('/users/'.$user->id.'/temporary-password', ['version' => 1, 'reason' => 'Expired before handover'])->assertOk();
        $password = $response->json('temporaryPassword');
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertFalse(Hash::check('Temporary-Only-458!Ab', $user->fresh()->password));
        $this->assertFalse($user->fresh()->temporaryPasswordExpired());
        $this->assertStringNotContainsString($password, json_encode(AuditLog::all()->toArray()));
        $this->postJson('/users/'.$user->id.'/temporary-password', ['version' => 1, 'reason' => 'Replay'])->assertUnprocessable();
        $this->getJson('/users/'.$user->id)->assertJsonMissingPath('temporaryPassword');
    }

    public function test_fortify_password_reset_clears_setup_but_never_reactivates_a_disabled_account(): void
    {
        $user = $this->pending();
        $input = ['password' => 'New-Private-Password-45!', 'password_confirmation' => 'New-Private-Password-45!'];
        app(ResetUserPassword::class)->reset($user, $input);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertNull($user->fresh()->temporary_password_expires_at);
        $hash = $user->fresh()->password;
        $user->refresh()->forceFill(['is_active' => false])->save();
        try {
            app(ResetUserPassword::class)->reset($user, $input);
            $this->fail('Password reset must not enable a disabled account.');
        } catch (ValidationException) {
            $this->assertFalse($user->fresh()->is_active);
            $this->assertSame($hash, $user->fresh()->password);
        }
    }

    public function test_authenticated_password_confirmation_is_throttled(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('password.confirm.store'), ['password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(route('password.confirm.store'), ['password' => 'wrong'])->assertStatus(429);
    }
}
