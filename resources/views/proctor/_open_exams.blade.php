{{-- Ujian yang sedang dibuka + tombol Tutup ujian. --}}
@if ($openExams->isNotEmpty())
    <div class="card">
        <div class="card-head"><h2>Ujian yang dibuka</h2></div>
        @foreach ($openExams as $e)
            <div class="list-row">
                <div class="ic orange">📝</div>
                <div class="info">
                    <p>{{ $e->title }} · <code>{{ $e->code }}</code></p>
                    <span>{{ $e->level->testType?->name }} {{ $e->level->code }} · {{ $e->ongoing_count ? $e->ongoing_count.' peserta masih mengerjakan' : 'tidak ada yang sedang mengerjakan' }}</span>
                </div>
                <form method="POST" action="{{ route('proctor.exams.close', $e) }}" data-confirm-stop
                      data-message="{{ 'Tutup ujian "'.$e->title.'" ('.$e->code.')? Kode tidak bisa dipakai lagi'.($e->ongoing_count ? ' dan '.$e->ongoing_count.' peserta yang masih mengerjakan akan dihentikan serta langsung dinilai' : '').'.' }}"
                      data-default="Ujian ditutup pengawas">
                    @csrf
                    <input type="hidden" name="reason">
                    <button class="btn small danger" type="submit">⏹ Tutup ujian</button>
                </form>
            </div>
        @endforeach
    </div>
@endif
