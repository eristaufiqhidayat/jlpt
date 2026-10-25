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
use Illuminate\Database\Eloquent\Builder;
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

    /** Sesi yang lewat waktu lebih dari ini tetapi belum dikumpulkan dianggap tanda cron tidak berjalan. */
    public const OVERDUE_MINUTES = 2;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('proctor.index', $this->payload($filters, $request->user()) + [
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
        $payload = $this->payload($filters, $request->user());

        return response()->json([
            'openExams' => view('proctor._open_exams', ['openExams' => $this->openExams($filters), 'canManage' => $payload['canManage']])->render(),
            'now' => now()->getTimestampMs(),
            'stats' => $payload['stats'],
            'rows' => view('proctor._rows', $payload)->render(),
            'waiting' => view('proctor._waiting', $payload)->render(),
            'alerts' => view('proctor._alerts', $payload)->render(),
        ]);
    }

    /** Hentikan ujian satu peserta (mis. ketahuan curang atau minta berhenti). */
    public function stop(Request $request, ExamAttempt $attempt, ExamAttemptService $service): RedirectResponse
    {
        $this->authorizeManage($request->user(), $attempt->exam);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $name = $attempt->student->name;

        return $service->stopAttempt($attempt, $request->user(), $data['reason'] ?? null)
            ? back()->with('success', "Ujian {$name} dihentikan. Jawaban yang sudah tersimpan dinilai dan nilainya keluar.")
            : back()->with('error', "Ujian {$name} sudah selesai sebelumnya.");
    }

    /** Tambah waktu sesi yang sedang dikerjakan satu peserta (mis. internet putus). */
    public function extend(Request $request, ExamAttempt $attempt, ExamAttemptService $service): RedirectResponse
    {
        $this->authorizeManage($request->user(), $attempt->exam);
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:60']], [], ['minutes' => 'tambahan waktu']);
        $name = $attempt->student->name;

        return $service->extendAttempt($attempt, $request->user(), (int) $data['minutes'])
            ? back()->with('success', "Waktu sesi {$name} ditambah {$data['minutes']} menit.")
            : back()->with('error', "Ujian {$name} tidak sedang berjalan, waktu tidak bisa ditambah.");
    }

    /**
     * Tutup ujian: kode tidak bisa dipakai lagi, dan semua peserta yang masih mengerjakan dihentikan
     * (jawaban tersimpan dinilai). Peserta yang sudah selesai tidak berubah.
     */
    public function closeExam(Request $request, Exam $exam, ExamAttemptService $service): RedirectResponse
    {
        $this->authorizeManage($request->user(), $exam);
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
     * Pengawas, admin, dan Admin Pendidikan boleh mengambil tindakan pada semua ujian;
     * sensei hanya pada ujian yang ia buat (sensei lain tetap bisa memantau).
     */
    public static function canManage(User $user, Exam $exam): bool
    {
        return $user->isProctor() || $user->isAcademicAdmin() || $exam->sensei_id === $user->id;
    }

    private function authorizeManage(User $user, Exam $exam): void
    {
        abort_unless(self::canManage($user, $exam), 403, 'Hanya pembuat ujian, pengawas, atau admin yang bisa melakukan ini.');
    }

    /**
     * Klasifikasi dipilih sebagai "type:ID" (semua level satu jenis tes) atau "level:ID" (mis. JLPT N4).
     *
     * Jenis: semua ujian, hanya ujian bersumber soal tes (tes online), atau hanya latihan.
     *
     * @return array{klasifikasi: ?string, test_type_id: ?int, level_id: ?int, exam_id: ?int, status: string, jenis: string, date: Carbon, is_today: bool}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'klasifikasi' => ['nullable', 'regex:/^(type|level):\d+$/'],
            'exam_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,ongoing,finished'],
            'jenis' => ['nullable', 'in:all,test,practice'],
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
            'jenis' => $data['jenis'] ?? 'all',
            'date' => $date,
            'is_today' => $date->isToday(),
        ];
    }

    private function payload(array $f, User $viewer): array
    {
        $attempts = $this->attempts($f);
        $onlineSince = now()->subSeconds(self::ONLINE_SECONDS);
        $ongoing = $attempts->where('status', AttemptStatus::Ongoing);

        // Sesi yang sudah lewat waktu > 2 menit tetapi belum dikumpulkan: tanda cron "schedule:run" tidak berjalan.
        $overdue = $ongoing->filter(fn (ExamAttempt $a) => $a->sections->contains(
            fn ($s) => $s->isRunning() && $s->deadline_at->lt(now()->subMinutes(self::OVERDUE_MINUTES))
        ))->count();

        return [
            'attempts' => $attempts,
            'onlineSince' => $onlineSince,
            'waiting' => $this->waiting($f, $attempts),
            'overdue' => $overdue,
            'canManage' => fn (Exam $exam) => self::canManage($viewer, $exam),
            'stats' => [
                'joined' => $attempts->count(),
                'ongoing' => $ongoing->count(),
                'online' => $ongoing->filter(fn (ExamAttempt $a) => $a->last_seen_at?->gte($onlineSince))->count(),
                'finished' => $attempts->count() - $ongoing->count(),
                'away' => $ongoing->filter(fn (ExamAttempt $a) => $a->focus_lost_count > 0)->count(),
            ],
        ];
    }

    /** Ujian bersumber soal tes: dipilih manual "test", atau otomatis karena bisa diikuti peserta tes umum. */
    private function whereExamKind(Builder $q, string $jenis): void
    {
        $isTest = fn ($w) => $w->where('question_pool', 'test')
            ->orWhere(fn ($a) => $a->whereNull('question_pool')->where('audience', '!=', 'internal'));
        $isPractice = fn ($w) => $w->where('question_pool', 'practice')
            ->orWhere(fn ($a) => $a->whereNull('question_pool')->where(fn ($b) => $b->where('audience', 'internal')->orWhereNull('audience')));

        if ($jenis === 'test') {
            $q->where($isTest);
        } elseif ($jenis === 'practice') {
            $q->where($isPractice);
        }
    }

    /** Pengerjaan ujian pada tanggal terpilih (hari ini: termasuk yang masih berjalan dari kemarin). */
    private function attempts(array $f): Collection
    {
        $answered = fn ($q) => $q->where(fn ($w) => $w->whereNotNull('selected_option_id')->orWhereNotNull('answer_text'));

        return ExamAttempt::query()
            ->with([
                'student:id,name,email,role',
                'stoppedBy:id,name',
                'extendedBy:id,name',
                'exam:id,title,code,level_id,sensei_id,audience,question_pool',
                'exam.level:id,code,name,test_type_id',
                'exam.level.testType:id,code,name',
                'sections' => fn ($q) => $q->withCount(['questions', 'questions as answered_count' => $answered]),
                'sections.examSection:id,section_id',
                'sections.examSection.section:id,name',
            ])
            ->when($f['test_type_id'], fn ($q, $id) => $q->whereHas('exam.level', fn ($l) => $l->where('test_type_id', $id)))
            ->when($f['level_id'], fn ($q, $id) => $q->whereHas('exam', fn ($e) => $e->where('level_id', $id)))
            ->when($f['exam_id'], fn ($q, $id) => $q->where('exam_id', $id))
            ->when($f['jenis'] !== 'all', fn ($q) => $q->whereHas('exam', fn ($e) => $this->whereExamKind($e, $f['jenis'])))
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
     * Peserta yang siap ujian hari ini tetapi belum memasukkan kode:
     * peserta tes umum yang sudah login, dan siswa LPK yang sudah membuka halaman Ujian
     * (siswa yang hanya membuka materi/jadwal tidak ikut, supaya daftarnya tidak penuh).
     * Hanya bila tidak memilih klasifikasi/ujian tertentu (mereka belum memilih ujian).
     */
    private function waiting(array $f, Collection $attempts): Collection
    {
        if (! $f['is_today'] || $f['klasifikasi'] || $f['exam_id'] || $f['status'] === 'finished') {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->when($f['jenis'] !== 'practice', fn ($w) => $w->orWhere(fn ($t) => $t->where('role', Role::TestTaker)->whereDate('last_login_at', today())))
                ->when($f['jenis'] !== 'test', fn ($w) => $w->orWhere(fn ($s) => $s->where('role', Role::Student)->whereDate('exam_page_seen_at', today()))))
            ->whereNotIn('id', $attempts->pluck('student_id'))
            ->whereDoesntHave('attempts', fn ($q) => $q->whereDate('started_at', today()))
            ->latest('last_login_at')
            ->limit(200)
            ->get(['id', 'name', 'email', 'role', 'last_login_at', 'exam_page_seen_at']);
    }

    /** Ujian yang sedang dibuka (sesuai filter) dan jumlah peserta yang masih mengerjakan, untuk tombol "Tutup ujian". */
    private function openExams(array $f): Collection
    {
        // Saat melihat tanggal lampau, tombol "Tutup ujian" disembunyikan supaya ujian hari ini tidak tertutup tanpa sengaja.
        if (! $f['is_today']) {
            return collect();
        }

        return Exam::query()
            ->with('level:id,code,test_type_id', 'level.testType:id,name')
            ->where('status', ExamStatus::Open)
            ->when($f['test_type_id'], fn ($q, $id) => $q->whereHas('level', fn ($l) => $l->where('test_type_id', $id)))
            ->when($f['level_id'], fn ($q, $id) => $q->where('level_id', $id))
            ->when($f['exam_id'], fn ($q, $id) => $q->whereKey($id))
            ->when($f['jenis'] !== 'all', fn ($q) => $this->whereExamKind($q, $f['jenis']))
            ->withCount(['attempts as ongoing_count' => fn ($q) => $q->where('status', AttemptStatus::Ongoing)])
            ->orderByDesc('ongoing_count')->orderBy('title')
            ->get(['id', 'title', 'code', 'level_id', 'sensei_id', 'audience', 'question_pool']);
    }

    /** Pilihan ujian di filter: ujian yang dibuka atau yang punya peserta pada tanggal itu. */
    private function examOptions(array $f): Collection
    {
        return Exam::query()
            ->with('level:id,code,test_type_id')
            ->when($f['test_type_id'], fn ($q, $id) => $q->whereHas('level', fn ($l) => $l->where('test_type_id', $id)))
            ->when($f['level_id'], fn ($q, $id) => $q->where('level_id', $id))
            ->when($f['jenis'] !== 'all', fn ($q) => $this->whereExamKind($q, $f['jenis']))
            ->where(fn ($q) => $q->where('status', ExamStatus::Open)
                ->orWhereHas('attempts', fn ($a) => $a->whereDate('started_at', $f['date']))
                ->orWhere('id', $f['exam_id'] ?? 0))
            ->orderBy('title')
            ->get(['id', 'title', 'code', 'level_id']);
    }
}
