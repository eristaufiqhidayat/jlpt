<div class="cert-wrap">
    <div class="cert">
        <span class="flower" style="top:26px;left:34px">🌸</span>
        <span class="flower" style="top:26px;right:34px">🌸</span>
        <img class="seal" src="{{ asset('images/logo-160.png') }}" alt="">
        <p class="org">{{ config('app.brand') }}</p>
        <h1>Sertifikat Kelulusan</h1>
        <p class="to">diberikan kepada</p>
        <p class="name">{{ $certificate->student->name }}</p>
        <p class="desc">
            Telah dinyatakan <strong>LULUS</strong> {{ $certificate->exam->title }}
            ({{ $certificate->exam->level->testType->name ?? '' }} {{ $certificate->exam->level->code }})
            dengan nilai <strong>{{ number_format((float) $certificate->score, 0) }}</strong>.
        </p>
        <div class="foot">
            <span>No. {{ $certificate->number }}</span>
            <span>Diterbitkan {{ $certificate->issued_at->translatedFormat('d F Y') }}</span>
            <span>Verifikasi: {{ route('certificates.verify', $certificate->verify_token) }}</span>
        </div>
    </div>
</div>
