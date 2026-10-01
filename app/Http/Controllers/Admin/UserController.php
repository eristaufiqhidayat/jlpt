<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->orderBy('role')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'roles' => Role::cases()]);
    }

    public function create(Request $request): View
    {
        return view('admin.users.form', [
            'user' => new User(['role' => $request->query('role', Role::Student->value), 'is_active' => true]),
            'roles' => Role::cases(),
        ] + $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $profile = $data['_profile']; unset($data['_profile']);
        $user = User::create($data);
        $this->saveProfile($user, $profile);

        return redirect()->route('admin.users.index', ['role' => $user->role->value])
            ->with('success', "Akun {$user->name} ({$user->role->label()}) berhasil dibuat.");
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', ['user' => $user->load('profile', 'classrooms'), 'roles' => Role::cases()] + $this->formData());
    }

    private function formData(): array
    {
        return [
            'levels' => \App\Models\Level::with('testType')->get()->sortBy(fn ($l) => [$l->testType->sort_order, $l->sort_order])->values(),
            'classrooms' => \App\Models\Classroom::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function saveProfile(User $user, array $profile): void
    {
        if (! $user->isStudent()) {
            return;
        }
        $user->profile()->updateOrCreate([], collect($profile)->except('classroom_ids')->all());
        $user->classrooms()->sync($profile['classroom_ids'] ?? []);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if ($user->is($request->user()) && ($data['role'] !== Role::Admin->value || ! $data['is_active'])) {
            return back()->with('error', 'Anda tidak bisa menurunkan role atau menonaktifkan akun sendiri.');
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }
        $profile = $data['_profile']; unset($data['_profile']);
        $user->update($data);
        $this->saveProfile($user, $profile);

        return redirect()->route('admin.users.index', ['role' => $user->role->value])
            ->with('success', "Akun {$user->name} diperbarui.");
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Anda tidak bisa menonaktifkan akun sendiri.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->name.($user->is_active ? ' diaktifkan.' : ' dinonaktifkan.'));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', new Enum(Role::class)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ], [], [
            'name' => 'nama', 'phone' => 'no. HP', 'role' => 'role',
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $profile = $request->validate([
            'nis' => ['nullable', 'string', 'max:30', Rule::unique('student_profiles', 'nis')->ignore($user?->profile?->id)],
            'level_id' => ['nullable', 'exists:levels,id'],
            'program_name' => ['nullable', 'string', 'max:150'],
            'joined_at' => ['nullable', 'date'],
            'classroom_ids' => ['array'],
            'classroom_ids.*' => ['exists:classrooms,id'],
        ], [], ['nis' => 'NIS', 'program_name' => 'program']);
        $data['_profile'] = $profile;

        return $data;
    }
}
