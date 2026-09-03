<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.profile'))
            ->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('settings.profile'), [
                'name' => 'Novo Nome',
                'email' => 'novo@escritorio.com.br',
                'phone' => '(21) 3344-5566',
                'company' => 'Nova Sociedade',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Novo Nome', $user->name);
        $this->assertSame('novo@escritorio.com.br', $user->email);
        $this->assertSame('(21) 3344-5566', $user->phone);
        $this->assertNull($user->email_verified_at);
    }

    public function test_password_can_be_updated_with_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'Outra#Senha9',
                'password_confirmation' => 'Outra#Senha9',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Outra#Senha9', $user->refresh()->password));
    }

    public function test_password_is_not_updated_with_a_wrong_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => 'senha-errada',
                'password' => 'Outra#Senha9',
                'password_confirmation' => 'Outra#Senha9',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_two_factor_can_be_toggled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('settings.two-factor.update'), ['two_factor_enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->refresh()->two_factor_enabled);
    }
}
