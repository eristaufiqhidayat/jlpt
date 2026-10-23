<?php

namespace App\Http\Controllers\Proctor;

use App\Enums\AttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\TestType;
use App\Models\User;
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
            'testTypes' => TestType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name']),
            'exams' => $this->examOptions($filters),
        ]);
    }

    /** Data terbaru untuk pembaruan otomatis (AJAX). */
    public function data(Request $request): JsonResponse
    {
        $payload = $this->payload($this->filters($request));

        return response()->json([
            'now' => now()->getTimestampMs(),
            'stats' => $payload['stats'],
            'rows' => view('proctor._rows', $payload)->render(),
            'waiting' => view('proctor._waiting', $payload)->render(),
        ]);
    }

    /** @return array{test_type_id: ?int, exam_id: ?int, status: string, date: Carbon, is_today: bool} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'test_type_id' => ['nullable', 'integer'],
            'exam_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,ongoing,finished'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = isset($data['date']) ? Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay() : today();

        return [
            'test_type_id' => isset($data['test_type_id']) ? (int) $data['test_type_id'] : null,
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
                'exam:id,title,code,level_id',
                'exam.level:id,code,name,test_type_id',
                'exam.level.testType:id,code,name',
                'sections' => fn ($q) => $q->withCount(['questions', 'questions as answered_count' => $answered]),
                'sections.examSection:id,section_id',
                'sections.examSection.section:id,name',
            ])
            ->when($f['test_type_id'], fn ($q, $id) => $q->whereHas('exam.level', fn ($l) => $l->where('test_type_id', $id)))
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
        if (! $f['is_today'] || $f['test_type_id'] || $f['exam_id'] || $f['status'] === 'finished') {
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

    /** Pilihan ujian di filter: ujian yang dibuka atau yang punya peserta pada tanggal itu. */
    private function examOptions(array $f): Collection
    {
        return Exam::query()
            ->with('level:id,code,test_type_id')
            ->when($f['test_type_id'], fn ($q, $id) => $q->whereHas('level', fn ($l) => $l->where('test_type_id', $id)))
            ->where(fn ($q) => $q->where('status', ExamStatus::Open)
                ->orWhereHas('attempts', fn ($a) => $a->whereDate('started_at', $f['date']))
                ->orWhere('id', $f['exam_id'] ?? 0))
            ->orderBy('title')
            ->get(['id', 'title', 'code', 'level_id']);
    }
}
