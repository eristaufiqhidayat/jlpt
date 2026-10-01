<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 *   php artisan user:password admin@example.com            # password acak, ditampilkan sekali
 *   php artisan user:password admin@example.com --admin    # buat akun admin bila belum ada
 */
class ResetUserPassword extends Command
{
    protected $signature = 'user:password {email} {--admin : Buat sebagai admin bila akun belum ada}';

    protected $description = 'Atur ulang password user (mis. admin yang lupa password)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user && ! $this->option('admin')) {
            $this->error("User {$email} tidak ditemukan. Tambahkan --admin untuk membuatnya sebagai admin.");

            return self::FAILURE;
        }

        $password = $this->secret('Password baru (kosongkan untuk dibuat acak)') ?: Str::password(14, symbols: false);

        $user ??= new User(['email' => $email, 'name' => 'Administrator', 'role' => Role::Admin]);
        $user->fill(['password' => $password, 'is_active' => true])->save();

        $this->info("Password untuk {$email} sudah diatur: {$password}");

        return self::SUCCESS;
    }
}
