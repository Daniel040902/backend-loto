<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Administrador',
                'username' => 'admin',
                'email' => 'admin@loto.app',
                'password' => Hash::make('admin123'),
            ]
        );
    }
}
