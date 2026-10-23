<?php

namespace App\Http\Controllers\Proctor;

use App\Enums\AttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Support\ExamClassification;
use App\Models\User;
use App\Services\ExamAttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Pengawasan ujian (role Pengawas; admin, Admin Pendidikan, dan sensei juga boleh):
 * siapa yang sudah masuk, nomor soal yang sedang dibuka, sesi/bagian per klasifikasi ujian,
 * dan sisa waktu setiap peserta. Halaman memperbarui datanya sendiri setiap 10 detik.
 */
class MonitorController extends Controller
{
    /** Peserta dianggap online bila halaman ujiannya mengirim sinyal dalam 90 detik terakhir. */
    public const ONLINE_SECONDS = 90;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('proctor.index', $this->payload($filters) + [
            'filters' => $filters,
            'classifications' => ExamClassification::options(),
            'exams' => $this->examOptions($filters),
            'openExams' => $this->openExams($filters),
        ]);
    }

    /** Data terbaru untuk pembaruan otomatis (AJAX). */
    public function data(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $payload = $this->payload($filters);

        return response()->json([
            'openExams' => view('proctor._open_exams', ['openExams' => $this->openExams($filters)])->render(),
            'now' => now()->getTimestampMs(),
            'stats' => $payload['stats'],
            'rows' => view('proctor._rows', $payload)->render(),
            'waiting' => view('proctor._waiting', $payload)->render(),
        ]);
    }

    /** Hentikan ujian satu peserta (mis. ketahuan curang atau minta berhenti). */
    public function stop(Request $request, ExamAttempt $attempt, ExamAttemptService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $name = $attempt->student->name;

        return $service->stopAttempt($attempt, $request->user(), $data['reason'] ?? null)
            ? back()->with('success', "Ujian {$name} dihentikan. Jawaban yang sudah tersimpan dinilai dan nilainya keluar.")
            : back()->with('error', "Ujian {$name} sudah selesai sebelumnya.");
    }

    /**
     * Tutup ujian: kode tidak bisa dipakai lagi, dan semua peserta yang masih mengerjakan dihentikan
     * (jawaban tersimpan dinilai). Peserta yang sudah selesai tidak berubah.
     */
    public function closeExam(Request $request, Exam $exam, ExamAttemptService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $exam->update(['status' => ExamStatus::Closed]);

        $stopped = 0;
        $exam->attempts()->where('status', AttemptStatus::Ongoing)->get()->each(function (ExamAttempt $attempt) use ($service, $request, $data, &$stopped) {
            $stopped += (int) $service->stopAttempt($attempt, $request->user(), $data['reason'] ?? 'Ujian ditutup pengawas');
        });

        return back()->with('success', "Ujian \"{$exam->title}\" ({$exam->code}) ditutup."
            .($stopped ? " {$stopped} peserta yang masih mengerjakan dihentikan dan dinilai." : ' Tidak ada peserta yang masih mengerjakan.'));
    }

    /**
     * Klasifikasi dipilih sebagai "type:ID" (semua level satu jenis tes) atau "level:ID" (mis. JLPT N4).
     *
     * @return array{klasifikasi: ?string, test_type_id: ?int, level_id: ?int, exam_id: ?int, status: string, date: Carbon, is_today: bool}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'klasifikasi' => ['nullable', 'regex:/^(type|level):\d+$/'],
            'exam_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,ongoing,finished'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        [$kind, $id] = isset($data['klasifikasi']) ? explode(':', $data['klasifikasi']) : [null, null];
        $date = isset($data['date']) ? Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay() : today();

        return [
            'klasifikasi' => $data['klasifikasi'] ?? null,
            'test_type_id' => $kind === 'type' ? (int) $id : null,
            'level_id' => $kind === 'level' ? (int) $id : null,
            'exam_id' => isset($data['exam_id']) ? (int) $data['exam_id'] : null,
            'status' => $data['status'] ?? 'all',
            'date' => $date,
            'is_today' => $date->isToday(),
        ];
    }

    private function payload(array $f): array
    {
        $attempts = $this->attempts($f);
        $onlineSince = now()->subSeconds(self::ONLINE_SECONDS);
        $ongoing = $attempts->where('status', AttemptStatus::Ongoing);

        return [
            'attempts' => $attempts,
            'onlineSince' => $onlineSince,
            'waiting' => $this->waiting($f, $attempts),
            'stats' => [
                'joined' => $attempts->count(),
                'ongoing' => $ongoing->count(),
                'online' => $ongoing->filter(fn (ExamAttempt $a) => $a->last_seen_at?->gte($onlineSince))->count(),
                'finished' => $attempts->count() - $ongoing->count(),
            ],
        ];
    }

    /** Pengerjaan ujian pada tanggal terpilih (hari ini: termasuk yang masih berjalan dari kemarin). */
    private function attempts(array $f): Collection
    {
        $answered = fn ($q) => $q->where(fn ($w) => $w->whereNotNull('selected_option_id')->orWhereNotNull('answer_text'));

        return ExamAttempt::query()
            ->with([
                'student:id,name,email,role',
                'stoppedBy:id,name',
                'exam:id,title,code,level_id',
                'exam.level:id,code,name,test_type_id',
                'exam.level.testType:id,code,name',
                'sections' => fn ($q) => $q->withCount(['questions', 'questions as answered_count' => $answered]),
                'sections.examSection:id,section_id',
                'sections.examSection.section:id,name',
            ])
            ->when($f['test_type_id'], fn ($q, $id) => $q->whereHas('exam.level', fn ($l) => $l->where('test_type_id', $id)))
            ->when($f['level_id'], fn ($q, $id) => $q->whereHas('exam', fn ($e) => $e->where('level_id', $id)))
            ->when($f['exam_id'], fn ($q, $id) => $q->where('exam_id', $id))
            ->when($f['status'] === 'ongoing', fn ($q) => $q->where('status', AttemptStatus::Ongoing))
            ->when($f['status'] === 'finished', fn ($q) => $q->where('status', '!=', AttemptStatus::Ongoing))
            ->where(function ($q) use ($f) {
                $q->whereDate('started_at', $f['date']);
                if ($f['is_today']) {
                    $q->orWhere('status', AttemptStatus::Ongoing);
                }
            })
            ->orderByRaw("CASE WHEN status = 'ongoing' THEN 0 ELSE 1 END")
            ->latest('started_at')
            ->limit(500)
            ->get();
    }

    /**
     * Peserta yang sudah login hari ini tetapi belum memasukkan kode ujian.
     * Hanya bila tidak memilih klasifikasi/ujian tertentu (mereka belum memilih ujian).
     */
    private function waiting(array $f, Collection $attempts): Collection
    {
        if (! $f['is_today'] || $f['klasifikasi'] || $f['exam_id'] || $f['status'] === 'finished') {
            return collect();
        }

        return User::query()
            ->whereIn('role', [Role::Student, Role::TestTaker])
            ->where('is_active', true)
            ->whereDate('last_login_at', today())
            ->whereNotIn('id', $attempts->pluck('student_id'))
            ->whereDoesntHave('attempts', fn ($q) => $q->whereDate('started_at', today()))
            ->latest('last_login_at')
            ->limit(200)
            ->get(['id', 'name', 'email', 'role', 'last_login_at']);
    }

    /** Ujian yang sedang dibuka (sesuai filter) dan jumlah peserta yang masih mengerjakan, untuk tombol "Tutup ujian". */
    private function openExams(array $f): Collection
    {
        return Exam::query()
            ->with('level:id,code,test_type_id', 'level.testType:id,name')
            ->where('status', ExamStatus::Open)
            ->when($f['test_type_id'], fn ($q, $id) => $q->whereHas('level', fn ($l) => $l->where('test_type_id', $id)))
            ->when($f['level_id'], fn ($q, $id) => $q->where('level_id', $id))
            ->when($f['exam_id'], fn ($q, $id) => $q->whereKey($id))
            ->withCount(['attempts as ongoing_count' => fn ($q) => $q->where('status', AttemptStatus::Ongoing)])
            ->orderByDesc('ongoing_count')->orderBy('title')
            ->get(['id', 'title', 'code', 'level_id']);
    }

    /** Pilihan ujian di filter: ujian yang dibuka atau yang punya peserta pada tanggal itu. */
    private function examOptions(array $f): Collection
    {
        return Exam::query()
            ->with('level:id,code,test_type_id')
            ->when($f['test_type_id'], fn ($q, $id) => $q->whereHas('level', fn ($l) => $l->where('test_type_id', $id)))
            ->when($f['level_id'], fn ($q, $id) => $q->where('level_id', $id))
            ->where(fn ($q) => $q->where('status', ExamStatus::Open)
                ->orWhereHas('attempts', fn ($a) => $a->whereDate('started_at', $f['date']))
                ->orWhere('id', $f['exam_id'] ?? 0))
            ->orderBy('title')
            ->get(['id', 'title', 'code', 'level_id']);
    }
}
