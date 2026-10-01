@if (session('success'))
    <div class="alert success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert error">{{ session('error') }}</div>
@endif
@if (session('warning'))
    <div class="alert warn">{{ session('warning') }}</div>
@endif
@if ($errors->any())
    <div class="alert error">
        Periksa kembali isian berikut:
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
