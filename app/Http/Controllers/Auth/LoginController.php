<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login', [
            'demoRoles' => config('app.demo_login') ? collect(config('app.demo_accounts'))->keys()
                ->map(fn ($role) => Role::tryFrom($role))->filter() : collect(),
        ]);
    }

    /**
     * Demo masuk cepat: masuk sebagai akun demo satu peran tanpa password.
     * Hanya bila config app.demo_login aktif (DEMO_LOGIN=true); selain itu halaman ini tidak ada (404).
     * Akun demo dibuat otomatis dengan password acak bila belum ada, sehingga tetap tidak bisa dipakai lewat form biasa.
     */
    public function demo(Request $request, string $role): RedirectResponse
    {
        $email = config('app.demo_accounts')[$role] ?? null;
        abort_unless(config('app.demo_login') && $email && ($roleEnum = Role::tryFrom($role)), 404);

        $user = User::firstOrCreate(['email' => $email], [
            'name' => 'Demo '.$roleEnum->label(),
            'password' => Str::random(40),
            'role' => $roleEnum,
            'is_active' => true,
        ]);
        abort_unless($user->role === $roleEnum && $user->is_active, 403, 'Akun demo untuk peran ini tidak aktif atau perannya sudah diubah.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Maksimal 5 percobaan per menit per email + IP.
        $key = Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages([
                'email' => 'Email atau password salah, atau akun tidak aktif.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
