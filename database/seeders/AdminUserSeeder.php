<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Akun admin pertama. Atur ADMIN_EMAIL dan ADMIN_PASSWORD di .env sebelum seeding;
 * bila ADMIN_PASSWORD kosong, password acak dibuat dan ditampilkan sekali di terminal.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} sudah ada, dilewati.");

            return;
        }

        $password = env('ADMIN_PASSWORD') ?: Str::password(14, symbols: false);

        User::create([
            'name' => 'Administrator',
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
            'is_active' => true,
        ]);

        if (! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Admin dibuat: {$email} / {$password} — segera ganti password ini.");
        }
    }
}
