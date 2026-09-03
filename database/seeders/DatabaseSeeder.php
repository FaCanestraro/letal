<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Conta de demonstração para o ambiente local.
     * Senha: Senha#Forte1
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Fabio Canestraro',
            'email' => 'fabiocanestraro007@gmail.com',
            'phone' => '(11) 91234-5678',
            'company' => 'Canestraro Advogados',
            'password' => 'Senha#Forte1',
            'role' => User::ROLE_OWNER,
            'two_factor_enabled' => true,
        ]);
    }
}
