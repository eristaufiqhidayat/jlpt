{{-- Masuk ujian: pilih klasifikasi ujian (jenis tes → level, dari Data Master) dulu, lalu kode ujian. Butuh $classifications. --}}
<form method="POST" action="{{ route('student.join') }}" class="join-form">
    @csrf
    <input type="hidden" name="with_type" value="1">
    <div class="field">
        <label for="level_id">1. Klasifikasi ujian</label>
        <select id="level_id" name="level_id" required
                onchange="if(this.value)document.getElementById('code').focus()">
            <option value="">— Pilih klasifikasi ujian —</option>
            @foreach ($classifications as $type)
                <optgroup label="{{ $type->name }}">
                    @foreach ($type->levels as $level)
                        <option value="{{ $level->id }}" @selected((int) old('level_id') === $level->id)>{{ \App\Support\ExamClassification::label($type, $level) }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="code">2. Kode ujian</label>
        <div class="inline-form">
            <div class="field" style="flex:1;margin:0">
                <input id="code" name="code" type="text" value="{{ old('code') }}" maxlength="20" required placeholder="KODE UJIAN" aria-label="Kode ujian"
                       style="text-transform:uppercase;letter-spacing:.15em;font-size:1.1rem;font-weight:700" autocomplete="off">
            </div>
            <button class="btn big" type="submit">Mulai</button>
        </div>
    </div>
</form>
