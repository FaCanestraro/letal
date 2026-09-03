<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCodeMail;
use App\Models\TwoFactorCode;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\LocalTwoFactorHint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Faz o login com a senha e devolve o usuário junto do código em texto puro.
     *
     * @return array{0: User, 1: string}
     */
    private function startChallenge(): array
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $code = null;

        Mail::assertSent(TwoFactorCodeMail::class, function (TwoFactorCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return [$user, (string) $code];
    }

    public function test_challenge_screen_is_rendered_for_a_pending_login(): void
    {
        [$user] = $this->startChallenge();

        $this->get(route('two-factor.create'))
            ->assertOk()
            ->assertSee($user->maskedEmail());
    }

    public function test_the_correct_code_completes_the_login(): void
    {
        [$user, $code] = $this->startChallenge();

        $this->post(route('two-factor.store'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertNotNull(TwoFactorCode::first()->consumed_at);
    }

    public function test_an_incorrect_code_is_rejected_and_counts_an_attempt(): void
    {
        [, $code] = $this->startChallenge();

        $wrong = str_pad((string) ((int) $code + 1), 6, '0', STR_PAD_LEFT);

        $this->post(route('two-factor.store'), ['code' => $wrong])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertSame(1, TwoFactorCode::first()->attempts);
    }

    public function test_the_code_is_blocked_after_the_maximum_attempts(): void
    {
        [, $code] = $this->startChallenge();

        $wrong = str_pad((string) ((int) $code + 1), 6, '0', STR_PAD_LEFT);

        foreach (range(1, (int) config('two_factor.max_attempts')) as $ignored) {
            $this->post(route('two-factor.store'), ['code' => $wrong]);
        }

        // Mesmo o código correto deixa de valer depois do limite de tentativas.
        $this->post(route('two-factor.store'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_an_expired_code_is_rejected(): void
    {
        [, $code] = $this->startChallenge();

        $this->travel((int) config('two_factor.expires_in_minutes') + 1)->minutes();

        $this->post(route('two-factor.store'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_resending_is_throttled_by_the_cooldown(): void
    {
        $this->startChallenge();

        $this->post(route('two-factor.resend'))->assertSessionHasErrors('code');
        $this->assertDatabaseCount('two_factor_codes', 1);
    }

    public function test_resending_after_the_cooldown_invalidates_the_previous_code(): void
    {
        [, $firstCode] = $this->startChallenge();

        $this->travel((int) config('two_factor.resend_cooldown') + 1)->seconds();

        $this->post(route('two-factor.resend'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('two_factor_codes', 2);

        $this->post(route('two-factor.store'), ['code' => $firstCode])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_service_issues_a_code_with_the_configured_length(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $issued = app(TwoFactorService::class)->issue($user);

        $this->assertSame((int) config('two_factor.code_length'), strlen($issued->code));
        $this->assertTrue(ctype_digit($issued->code));

        Mail::assertSent(
            TwoFactorCodeMail::class,
            fn (TwoFactorCodeMail $mail) => $mail->code === $issued->code,
        );
    }

    public function test_the_plain_code_is_never_exposed_outside_local_development(): void
    {
        $this->startChallenge();

        $this->get(route('two-factor.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('localHintCode', null));
    }

    public function test_the_plain_code_is_shown_on_screen_while_mail_is_only_logged(): void
    {
        config(['two_factor.expose_code_on_screen' => true]);

        [, $code] = $this->startChallenge();

        $this->get(route('two-factor.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('localHintCode', $code));
    }

    public function test_the_on_screen_code_is_dropped_once_the_login_completes(): void
    {
        config(['two_factor.expose_code_on_screen' => true]);

        [, $code] = $this->startChallenge();

        $this->post(route('two-factor.store'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertNull(session(LocalTwoFactorHint::KEY));
    }
}
