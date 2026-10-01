<?php

namespace App\Http\Controllers;

use App\Support\ProgramJepang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user()->load(['profile.level.testType', 'classrooms']);

        return view('profile.edit', [
            'user' => $user,
            'progress' => $user->isStudent() ? ProgramJepang::progress($user->programStages) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ], ['current_password.current_password' => 'Password lama salah.'], ['name' => 'nama', 'phone' => 'no. HP']);

        $request->user()->update(array_filter([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'] ?? null,
        ], fn ($v) => $v !== null) + ['phone' => $data['phone'] ?? null]);

        return back()->with('success', 'Profil diperbarui.');
    }
}
