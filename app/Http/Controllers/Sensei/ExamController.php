<?php

namespace App\Http\Controllers\Sensei;

use App\Enums\ContentStatus;
use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Level;
use App\Models\Question;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sensei membuat ujian: pilih level, lalu atur durasi dan jumlah soal tiap bagian.
 * Sensei hanya mengelola ujiannya sendiri; admin bisa semua.
 */
class ExamController extends Controller
{
    public function index(Request $request): View
    {
        $exams = Exam::with(['level.testType', 'sensei:id,name'])
            ->withCount('attempts')
            ->when(! $request->user()->isAcademicAdmin(), fn ($q) => $q->where('sensei_id', $request->user()->id))
            ->latest('id')->paginate(25);

        return view('sensei.exams.index', ['exams' => $exams]);
    }

    public function create(Request $request): View
    {
        $levels = $this->levels();
        $level = $levels->firstWhere('id', $request->integer('level_id')) ?? $levels->first();

        return view('sensei.exams.form', [
            'exam' => new Exam(['level_id' => $level?->id, 'status' => ExamStatus::Draft, 'is_paid' => false, 'show_result_immediately' => true, 'audience' => 'internal']),
            'levels' => $levels,
            'level' => $level,
            'rows' => $level ? $this->sectionRows($level) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $sections] = $this->validated($request);

        $exam = DB::transaction(function () use ($request, $data, $sections) {
            $exam = Exam::create($data + ['sensei_id' => $request->user()->id]);
            $exam->sections()->createMany($sections);

            return $exam;
        });

        return redirect()->route('sensei.exams.show', $exam)
            ->with('success', "Ujian dibuat dengan kode {$exam->code}.".($exam->status === ExamStatus::Open ? '' : ' Buka ujian agar student bisa masuk.'));
    }

    public function show(Request $request, Exam $exam): View
    {
        $this->authorizeExam($request, $exam);
        $exam->load(['level.testType', 'sections.section', 'sensei:id,name']);

        $attempts = $exam->attempts()->with(['student:id,name,email', 'sections'])->latest('id')->paginate(50);
        $available = $this->availableCounts($exam->sections->pluck('section_id'), $exam->questionPool());

        return view('sensei.exams.show', compact('exam', 'attempts', 'available'));
    }

    public function edit(Request $request, Exam $exam): View
    {
        $this->authorizeExam($request, $exam);
        $exam->load('sections');

        return view('sensei.exams.form', [
            'exam' => $exam,
            'levels' => $this->levels($exam->level_id),
            'level' => $exam->level,
            'rows' => $this->sectionRows($exam->level, $exam),
        ]);
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        [$data, $sections] = $this->validated($request, $exam);

        DB::transaction(function () use ($exam, $data, $sections) {
            $exam->update($data);
            $keep = collect($sections)->pluck('section_id');
            if ($exam->attempts()->doesntExist()) {
                $exam->sections()->whereNotIn('section_id', $keep)->delete();
            }
            foreach ($sections as $row) {
                $exam->sections()->updateOrCreate(['section_id' => $row['section_id']], $row);
            }
        });

        return redirect()->route('sensei.exams.show', $exam)
            ->with('success', 'Ujian diperbarui. Perubahan durasi berlaku untuk peserta yang mulai setelah ini.');
    }

    public function updateStatus(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        $data = $request->validate(['status' => ['required', new Enum(ExamStatus::class)]]);
        $exam->update($data);

        $msg = match ($exam->status) {
            ExamStatus::Open => "Ujian dibuka. Bagikan kode {$exam->code} ke student.",
            ExamStatus::Closed => 'Ujian ditutup. Peserta yang sedang mengerjakan tetap bisa menyelesaikan.',
            ExamStatus::Draft => 'Ujian dikembalikan ke draft.',
        };

        return back()->with('success', $msg);
    }

    /** Export nilai semua peserta ke Excel. */
    public function export(Request $request, Exam $exam): BinaryFileResponse
    {
        $this->authorizeExam($request, $exam);
        $exam->load(['level.testType', 'sections.section']);
        $attempts = $exam->attempts()->with(['student:id,name,email', 'sections'])->orderBy('started_at')->get();

        $header = ['No', 'Nama', 'Email', 'Mulai', 'Selesai', 'Status'];
        foreach ($exam->sections as $s) {
            $header[] = $s->section->name.' (benar)';
            $header[] = $s->section->name.' (nilai)';
        }
        array_push($header, 'Total benar', 'Jumlah soal', 'Nilai akhir');

        $status = ['ongoing' => 'Mengerjakan', 'pending_review' => 'Tunggu nilai esai', 'finished' => 'Selesai'];
        $rows = [$header];
        foreach ($attempts as $i => $a) {
            $bySection = $a->sections->keyBy('exam_section_id');
            $row = [$i + 1, $a->student->name, $a->student->email,
                $a->started_at?->format('Y-m-d H:i'), $a->finished_at?->format('Y-m-d H:i'), $status[$a->status->value] ?? $a->status->value];
            foreach ($exam->sections as $s) {
                $as = $bySection->get($s->id);
                $done = $as && $as->submitted_at;
                $row[] = $done ? $as->correct_count : null;
                $row[] = $done ? (float) $as->score : null;
            }
            array_push($row, $a->correct_count, $a->question_count, $a->score !== null ? (float) $a->score : null);
            $rows[] = $row;
        }

        $info = [
            ['Ujian', $exam->title],
            ['Level', $exam->level->testType->name.' '.$exam->level->code],
            ['Kode', $exam->code],
            ['Diekspor', now()->format('Y-m-d H:i')],
            ['Peserta', $attempts->count()],
            ['Keterangan', 'Nilai = persentase jawaban benar (0–100).'],
        ];

        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        (new XlsxWriter)
            ->addSheet('Nilai', $rows, array_merge([5, 24, 28, 17, 17, 16], array_fill(0, count($exam->sections) * 2 + 3, 14)))
            ->addSheet('Info', $info, [14, 50])
            ->save($path);

        $name = 'nilai-'.Str::slug($exam->title).'-'.$exam->code.'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    private function authorizeExam(Request $request, Exam $exam): void
    {
        abort_unless($request->user()->isAcademicAdmin() || $exam->sensei_id === $request->user()->id, 403);
    }

    /** Level yang bisa dipilih: yang aktif dan punya bagian aktif, ditambah level ujian yang sedang diedit. */
    private function levels(?int $keepLevelId = null): Collection
    {
        return Level::with('testType')
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('is_active', true)->whereHas('sections', fn ($s) => $s->where('is_active', true)))
                ->when($keepLevelId, fn ($w) => $w->orWhere('id', $keepLevelId)))
            ->get()
            ->sortBy(fn ($l) => [$l->testType->is_active ? 0 : 1, $l->testType->sort_order, $l->sort_order])
            ->values();
    }

    /** Baris form per bagian: nilai lama (edit) atau default dari master + jumlah soal tersedia. */
    private function sectionRows(Level $level, ?Exam $exam = null): Collection
    {
        $level->loadMissing('sections');
        $existing = $exam?->sections->keyBy('section_id') ?? collect();
        $ids = $level->sections->pluck('id');
        $practice = $this->availableCounts($ids, 'practice');
        $test = $this->availableCounts($ids, 'test');
        $available = ($exam?->questionPool() ?? 'practice') === 'test' ? $test : $practice;

        // Bagian nonaktif tidak ditawarkan untuk ujian baru, tetapi tetap tampil bila sudah dipakai ujian ini.
        return $level->sections->filter(fn ($s) => $s->is_active || $existing->has($s->id))->values()->map(fn ($s) => (object) [
            'section' => $s,
            'enabled' => $exam ? $existing->has($s->id) : true,
            'duration' => $existing->get($s->id)?->duration_minutes ?? $s->default_duration_minutes,
            'count' => $existing->get($s->id)?->question_count ?? ($s->default_question_count ?: min(10, $available[$s->id] ?? 0) ?: 10),
            'order' => $existing->get($s->id)?->sort_order ?? $s->sort_order,
            'available' => $available[$s->id] ?? 0,
            'available_practice' => $practice[$s->id] ?? 0,
            'available_test' => $test[$s->id] ?? 0,
        ]);
    }

    /** @return array<int, int> section_id => jumlah soal siap pakai untuk sumber soal ini */
    private function availableCounts(Collection $sectionIds, string $pool): array
    {
        return Question::where('status', ContentStatus::Published)
            ->forPool($pool)
            ->whereIn('section_id', $sectionIds)
            ->selectRaw('section_id, COUNT(*) as total')->groupBy('section_id')
            ->pluck('total', 'section_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array{0: array, 1: list<array>} */
    private function validated(Request $request, ?Exam $exam = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'level_id' => ['required', 'exists:levels,id'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'is_paid' => ['boolean'],
            'audience' => ['nullable', Rule::in(array_keys(Exam::AUDIENCES))],
            'question_pool' => ['nullable', Rule::in(['practice', 'test'])],
            'price' => ['nullable', 'integer', 'min:0', 'required_if:is_paid,1'],
            'pass_score' => ['nullable', 'integer', 'min:1', 'max:100'],
            'audio_play_limit' => ['nullable', 'integer', 'min:1', 'max:5'],
            'shuffle_options' => ['boolean'],
            'show_result_immediately' => ['boolean'],
            'status' => ['required', new Enum(ExamStatus::class)],
            'sections' => ['required', 'array'],
            'sections.*.enabled' => ['nullable', 'boolean'],
            'sections.*.duration' => ['nullable', 'integer', 'min:1', 'max:300'],
            'sections.*.count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'sections.*.order' => ['nullable', 'integer', 'min:0', 'max:50'],
        ], [
            'price.required_if' => 'Isi harga untuk ujian berbayar.',
            'closes_at.after' => 'Waktu tutup harus setelah waktu buka.',
        ], ['title' => 'judul', 'level_id' => 'level']);

        $level = Level::with('sections')->findOrFail($data['level_id']);
        // Level nonaktif tidak bisa dipakai untuk ujian baru (ujian lama di level itu tetap bisa diedit).
        if (! $level->is_active && $exam?->level_id !== $level->id) {
            throw ValidationException::withMessages(['level_id' => 'Level ini sudah dinonaktifkan Admin Pendidikan.']);
        }
        if ($exam && $exam->level_id !== $level->id && $exam->attempts()->exists()) {
            throw ValidationException::withMessages(['level_id' => 'Level tidak bisa diganti karena ujian sudah pernah dikerjakan.']);
        }

        $sections = [];
        $used = $exam ? $exam->sections()->pluck('section_id')->flip() : collect();
        foreach ($level->sections as $s) {
            if (! $s->is_active && ! $used->has($s->id)) {
                continue; // bagian nonaktif tidak bisa ditambahkan ke ujian
            }
            $row = $data['sections'][$s->id] ?? null;
            if (! $row || empty($row['enabled'])) {
                continue;
            }
            if (empty($row['duration']) || empty($row['count'])) {
                throw ValidationException::withMessages(['sections' => "Isi durasi dan jumlah soal untuk bagian {$s->name}."]);
            }
            $sections[] = [
                'section_id' => $s->id,
                'duration_minutes' => (int) $row['duration'],
                'question_count' => (int) $row['count'],
                'sort_order' => (int) ($row['order'] ?? $s->sort_order),
            ];
        }
        if ($sections === []) {
            throw ValidationException::withMessages(['sections' => 'Pilih minimal satu bagian ujian.']);
        }

        unset($data['sections']);
        $data['audience'] = $data['audience'] ?? $exam?->audience ?? 'internal';
        $data['question_pool'] = $data['question_pool'] ?? null; // kosong = otomatis
        $data['is_paid'] = $request->boolean('is_paid');
        $data['price'] = $data['is_paid'] ? (int) $data['price'] : 0;
        $data['shuffle_options'] = $request->boolean('shuffle_options');
        $data['show_result_immediately'] = $request->boolean('show_result_immediately');

        return [$data, $sections];
    }
}
