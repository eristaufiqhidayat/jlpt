<?php

namespace App\Http\Controllers\Sensei;

use App\Enums\LegacyKeyStatus;
use App\Http\Controllers\Controller;
use App\Models\LegacyAnswerKey;
use App\Models\LegacyExam;
use App\Models\LegacyExamFile;
use App\Support\Legacy\AnswerKeyAnalyzer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Arsip soal dan kunci dari aplikasi lama. Sensei bisa membaca PDF-nya,
 * memperbaiki kunci (mis. E/F yang salah input), dan memakainya saat menginput ulang soal.
 */
class LegacyArchiveController extends Controller
{
    public function index(Request $request): View
    {
        $files = LegacyExamFile::query()
            ->with(['section.level.testType', 'exams:id,kode_exam,jenis_tes,kelas,ulevel,nama_tes'])
            ->withCount('questions')
            ->when($request->input('status') === 'problem', fn ($q) => $q->whereIn('key_status', [LegacyKeyStatus::InvalidKeys, LegacyKeyStatus::Incomplete]))
            ->when($request->filled('status') && $request->input('status') !== 'problem', fn ($q) => $q->where('key_status', $request->string('status')))
            ->when($request->filled('jenis_tes'), function ($q) use ($request) {
                $jenis = $request->string('jenis_tes');
                $q->where(fn ($w) => $w->whereHas('exams', fn ($e) => $e->where('jenis_tes', $jenis))
                    ->orWhereHas('section.level.testType', fn ($t) => $t->where('code', $jenis)));
            })
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('kode_exam', 'like', '%'.$request->string('q').'%')
                ->orWhere('original_filename', 'like', '%'.$request->string('q').'%')))
            ->orderByDesc('legacy_created_at')
            ->paginate(30)->withQueryString();

        return view('sensei.legacy.index', [
            'files' => $files,
            'types' => LegacyExam::query()->distinct()->orderBy('jenis_tes')->pluck('jenis_tes')->filter(),
            'statuses' => LegacyKeyStatus::cases(),
        ]);
    }

    public function show(LegacyExamFile $file): View
    {
        $file->load(['section.level.testType', 'exams', 'answerKeys', 'questions:id,legacy_file_id,legacy_number,status']);
        $keys = $file->answerKeys->groupBy('number');

        return view('sensei.legacy.show', [
            'file' => $file,
            'rows' => collect(range(1, max(1, $file->question_count)))->map(fn ($n) => (object) [
                'number' => $n,
                'key' => $keys->get($n)?->last(),
                'duplicates' => max(0, ($keys->get($n)?->count() ?? 0) - 1),
            ]),
            'hasPdf' => $file->file_path && Storage::disk('local')->exists($file->file_path),
            'hasAudio' => $file->answerKeys->contains(fn ($k) => $k->audio_filename),
        ]);
    }

    public function pdf(LegacyExamFile $file): StreamedResponse
    {
        abort_unless($file->file_path && Storage::disk('local')->exists($file->file_path), 404);

        return Storage::disk('local')->response($file->file_path, basename($file->original_filename ?: $file->file_path), [
            'Content-Type' => $file->file_mime ?: 'application/pdf',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function audio(LegacyAnswerKey $key): StreamedResponse
    {
        abort_unless($key->audio_path && Storage::disk('local')->exists($key->audio_path), 404);

        return Storage::disk('local')->response($key->audio_path, $key->audio_filename, ['Cache-Control' => 'private, max-age=600']);
    }

    /** Simpan perbaikan kunci per nomor (1–4 atau kosong), lalu hitung ulang status file. */
    public function updateKeys(Request $request, LegacyExamFile $file): RedirectResponse
    {
        $data = $request->validate([
            'keys' => ['array'],
            'keys.*' => ['nullable', 'in:1,2,3,4'],
        ]);
        $submitted = collect($data['keys'] ?? [])->mapWithKeys(fn ($v, $n) => [(int) $n => $v === null ? null : (int) $v]);

        DB::transaction(function () use ($file, $submitted, $request) {
            $existing = $file->answerKeys()->get()->groupBy('number');

            foreach (range(1, $file->question_count) as $n) {
                $label = $submitted->get($n);
                $rows = $existing->get($n, collect());
                $latest = $rows->last();

                // Duplikat lama dinonaktifkan; yang terakhir dipakai sebagai kunci.
                $rows->slice(0, -1)->each(fn ($r) => $r->update(['is_valid' => false]));

                if ($latest) {
                    if ($latest->option_label !== $label || $latest->is_valid !== ($label !== null)) {
                        $latest->update(['option_label' => $label, 'is_valid' => $label !== null, 'edited_by' => $request->user()->id]);
                    }
                } elseif ($label !== null) {
                    $file->answerKeys()->create([
                        'number' => $n, 'raw_key' => null, 'option_label' => $label, 'is_valid' => true, 'edited_by' => $request->user()->id,
                    ]);
                }
            }

            $this->recalculate($file);
        });

        return back()->with('success', 'Kunci jawaban diperbarui. Status: '.$file->fresh()->key_status->label().'.');
    }

    private function recalculate(LegacyExamFile $file): void
    {
        $keys = $file->answerKeys()->get();
        $validNumbers = $keys->where('is_valid', true)->pluck('number')->unique()
            ->filter(fn ($n) => $n >= 1 && $n <= $file->question_count);
        $missing = collect(range(1, $file->question_count))->diff($validNumbers)->values()->all();

        // Buang catatan lama tentang kunci; catatan lain (file hilang, dll.) dipertahankan.
        $issues = collect($file->issues ?? [])
            ->reject(fn ($i) => str_starts_with($i, 'No. ') || str_starts_with($i, 'Tanpa kunci'))
            ->values();
        if ($missing) {
            $issues->prepend('Tanpa kunci: no. '.AnswerKeyAnalyzer::compressRanges($missing));
        }

        $status = $file->key_status === LegacyKeyStatus::NoKeyNeeded && $validNumbers->isEmpty()
            ? LegacyKeyStatus::NoKeyNeeded
            : ($missing ? LegacyKeyStatus::Incomplete : LegacyKeyStatus::Ok);

        $file->update([
            'key_count' => $keys->count(),
            'valid_key_count' => $validNumbers->count(),
            'key_status' => $status,
            'issues' => $issues->isEmpty() ? null : $issues->all(),
        ]);
    }
}
