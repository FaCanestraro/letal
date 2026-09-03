<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Support\PendingTwoFactorLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_valid_credentials_start_the_two_factor_challenge_without_logging_in(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.create'));
        $this->assertGuest();
        $this->assertDatabaseCount('two_factor_codes', 1);

        Mail::assertSent(TwoFactorCodeMail::class, fn (TwoFactorCodeMail $mail) => $mail->hasTo($user->email));
    }

    public function test_users_without_two_factor_are_logged_in_directly(): void
    {
        Mail::fake();

        $user = User::factory()->withoutTwoFactor()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        Mail::assertNothingSent();
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'senha-errada',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('two_factor_codes', 0);
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->post(route('login'), [
                'email' => $user->email,
                'password' => 'senha-errada',
            ]);
        }

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Muitas tentativas',
            session('errors')->first('email'),
        );
    }

    public function test_the_challenge_page_requires_a_pending_login(): void
    {
        $this->get(route('two-factor.create'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('sped.index'))->assertRedirect(route('login'));
        $this->get(route('settings.profile'))->assertRedirect(route('login'));
    }

    public function test_pending_challenge_state_is_cleared_on_cancel(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $this->assertNotNull(PendingTwoFactorLogin::user());

        $this->delete(route('two-factor.destroy'))->assertRedirect(route('login'));
        $this->assertNull(session(PendingTwoFactorLogin::KEY));
    }
}
