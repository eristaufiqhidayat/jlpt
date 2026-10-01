@php($keys = array_keys(\App\Support\ProgramJepang::STAGES))
<div class="timeline-h" role="list">
    @foreach (\App\Support\ProgramJepang::STAGES as $key => $label)
        @php($st = $stages->get($key))
        @php($status = $st?->status ?? 'belum')
        <div role="listitem" @class(['tl-step', 'done' => $status === 'selesai', 'current' => $status === 'proses'])>
            <div class="tl-circ">{{ $status === 'selesai' ? '✓' : $loop->iteration }}</div>
            <div class="tl-label">{{ $label }}<br>
                {{ $st?->date ? $st->date->translatedFormat('d M Y') : \App\Support\ProgramJepang::STATUSES[$status] }}
            </div>
        </div>
    @endforeach
</div>
