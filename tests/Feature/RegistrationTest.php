<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get(route('register'))->assertOk();
    }

    public function test_new_users_can_register_and_are_logged_in(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Maria Oliveira',
            'email' => 'maria@escritorio.com.br',
            'phone' => '(11) 91234-5678',
            'company' => 'Oliveira Advogados',
            'password' => 'Senha#Forte1',
            'password_confirmation' => 'Senha#Forte1',
            'terms' => true,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::firstWhere('email', 'maria@escritorio.com.br');

        $this->assertNotNull($user);
        $this->assertSame('Oliveira Advogados', $user->company);
        $this->assertSame('(11) 91234-5678', $user->phone);
        $this->assertSame(User::ROLE_OWNER, $user->role);
        $this->assertTrue($user->two_factor_enabled);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_registration_requires_office_fields(): void
    {
        $this->post(route('register'), [
            'name' => 'Maria',
            'email' => 'maria@escritorio.com.br',
            'password' => 'Senha#Forte1',
            'password_confirmation' => 'Senha#Forte1',
        ])->assertSessionHasErrors(['phone', 'company', 'terms']);

        $this->assertGuest();
    }

    public function test_registration_rejects_malformed_phone(): void
    {
        $this->post(route('register'), [
            'name' => 'Maria Oliveira',
            'email' => 'maria@escritorio.com.br',
            'phone' => '1234',
            'company' => 'Oliveira Advogados',
            'password' => 'Senha#Forte1',
            'password_confirmation' => 'Senha#Forte1',
            'terms' => true,
        ])->assertSessionHasErrors('phone');
    }

    public function test_registration_rejects_weak_password(): void
    {
        $this->post(route('register'), [
            'name' => 'Maria Oliveira',
            'email' => 'maria@escritorio.com.br',
            'phone' => '(11) 91234-5678',
            'company' => 'Oliveira Advogados',
            'password' => 'senha',
            'password_confirmation' => 'senha',
            'terms' => true,
        ])->assertSessionHasErrors('password');
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'maria@escritorio.com.br']);

        $this->post(route('register'), [
            'name' => 'Maria Oliveira',
            'email' => 'maria@escritorio.com.br',
            'phone' => '(11) 91234-5678',
            'company' => 'Oliveira Advogados',
            'password' => 'Senha#Forte1',
            'password_confirmation' => 'Senha#Forte1',
            'terms' => true,
        ])->assertSessionHasErrors('email');
    }
}
