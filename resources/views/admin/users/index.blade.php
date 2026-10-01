@extends('layouts.app')
@section('title', 'User')
@section('content')
    <div class="page-head">
        <h1>User</h1>
        <a class="btn" href="{{ route('admin.users.create', ['role' => request('role', 'student')]) }}">+ Tambah user</a>
    </div>

    <form method="GET" class="filters">
        <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role" onchange="this.form.submit()">
                <option value="">Semua</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="flex:1">
            <label for="q">Cari nama / email</label>
            <input id="q" type="search" name="q" value="{{ request('q') }}">
        </div>
        <button class="btn secondary">Cari</button>
    </form>

    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>Nama</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->name }}@if ($user->phone)<br><small>{{ $user->phone }}</small>@endif</td>
                    <td>{{ $user->email }}</td>
                    <td><span class="badge info">{{ $user->role->label() }}</span></td>
                    <td>{!! $user->is_active ? '<span class="badge ok">Aktif</span>' : '<span class="badge err">Nonaktif</span>' !!}</td>
                    <td class="actions">
                        <a class="btn small secondary" href="{{ route('admin.users.edit', $user) }}">Edit</a>
                        @unless ($user->is(auth()->user()))
                            <form method="POST" action="{{ route('admin.users.toggle', $user) }}" style="display:inline">
                                @csrf
                                <button class="btn small {{ $user->is_active ? 'danger' : 'success' }}">{{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Tidak ada user.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $users->links() }}
@endsection
