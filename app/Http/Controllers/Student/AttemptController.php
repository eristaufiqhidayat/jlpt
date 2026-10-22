<?php

namespace App\Http\Controllers\Student;

use App\Exceptions\ExamAccessException;
use App\Http\Controllers\Controller;
use App\Models\AttemptQuestion;
use App\Models\AttemptSection;
use App\Models\ExamAttempt;
use App\Services\ExamAccessService;
use App\Services\ExamAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttemptController extends Controller
{
    public function __construct(
        private readonly ExamAttemptService $attempts,
        private readonly ExamAccessService $access,
    ) {}

    /** Riwayat semua ujian student. */
    public function index(Request $request): View
    {
        $attempts = $request->user()->attempts()->with(['exam.level.testType'])
            ->latest('started_at')->paginate(20);

        return view('student.results', ['attempts' => $attempts]);
    }

    /** Menu "Ujian": masukkan kode, lanjutkan ujian/pembayaran. */
    public function exams(Request $request): View
    {
        $user = $request->user();

        return view('student.exams', [
            'ongoing' => $user->attempts()->with('exam')->where('status', 'ongoing')->latest()->get(),
            'pendingPayments' => $user->payments()->with('exam')->where('status', 'pending')->where('expires_at', '>', now())->latest()->get(),
            'recent' => $user->attempts()->with('exam.level')->where('status', '!=', 'ongoing')->latest('finished_at')->take(5)->get(),
        ]);
    }

    /** Pembahasan: jawaban peserta, kunci, dan penjelasan (setelah ujian selesai). */
    public function review(Request $request, ExamAttempt $attempt): View|RedirectResponse
    {
        $this->authorizeOwner($request, $attempt);
        if (! $attempt->isFinished() || ! $attempt->exam->show_result_immediately) {
            return redirect()->route('student.attempts.result', $attempt);
        }
        $attempt->load(['exam', 'sections.examSection.section', 'sections.questions.question.options', 'sections.questions.question.passage']);

        return view('student.review', ['attempt' => $attempt]);
    }

    /** Student memasukkan kode ujian. */
    public function join(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => 'kode ujian']);

        try {
            $exam = $this->access->findOpenExamByCode($data['code'], $request->user());
            $attempt = $this->attempts->start($request->user(), $exam);
        } catch (ExamAccessException $e) {
            if ($e->reason === 'payment_required') {
                return redirect()->route('student.payments.show', $exam->code);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('student.attempts.show', $attempt);
    }

    /** Halaman mengerjakan bagian yang sedang berjalan. */
    public function show(Request $request, ExamAttempt $attempt): View|RedirectResponse
    {
        $this->authorizeOwner($request, $attempt);
        $this->attempts->expireOverdueSections($attempt);
        $attempt->refresh()->load(['exam', 'sections.examSection.section']);

        if ($attempt->isFinished()) {
            return redirect()->route('student.attempts.result', $attempt);
        }

        $section = $attempt->sections->first(fn (AttemptSection $s) => $s->isRunning());
        if (! $section) {
            // Seharusnya tidak terjadi; mulai bagian berikutnya yang belum dimulai.
            $next = $attempt->sections->first(fn (AttemptSection $s) => $s->started_at === null);
            abort_unless($next, 404);
            $this->attempts->startSection($next);

            return redirect()->route('student.attempts.show', $attempt);
        }

        $items = $section->questions()->with(['question.options', 'question.passage'])->get();

        return view('student.attempt', [
            'attempt' => $attempt,
            'section' => $section,
            'items' => $items,
            'groups' => $items->groupBy(fn (AttemptQuestion $i) => $i->question->passage_id ? 'p'.$i->question->passage_id : 'q'.$i->id),
            'remaining' => $section->remainingSeconds(),
            'playLimit' => $attempt->exam->audio_play_limit,
        ]);
    }

    /** Autosave satu jawaban (AJAX). */
    public function answer(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorizeOwner($request, $attempt);
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'option_id' => ['nullable', 'integer'],
            'text' => ['nullable', 'string', 'max:2000'],
            'flagged' => ['nullable', 'boolean'],
        ]);

        $item = $this->findItem($attempt, (int) $data['item_id']);

        try {
            $item->correct_option_id === null
                ? $this->attempts->answerEssay($item, $data['text'] ?? null, (bool) ($data['flagged'] ?? false))
                : $this->attempts->answer($item, $data['option_id'] ?? null, (bool) ($data['flagged'] ?? false));
        } catch (ExamAccessException $e) {
            return response()->json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'remaining' => $item->attemptSection->fresh()->remainingSeconds()]);
    }

    /** Kumpulkan bagian (tombol "Selesai" atau otomatis saat timer habis). */
    public function submitSection(Request $request, ExamAttempt $attempt): RedirectResponse
    {
        $this->authorizeOwner($request, $attempt);
        $section = $attempt->sections()->whereNotNull('started_at')->whereNull('submitted_at')->first();

        if ($section) {
            abort_unless((int) $request->input('section_id') === $section->id, 409, 'Bagian sudah berganti. Muat ulang halaman.');

            // Simpan jawaban terakhir dari form (cadangan bila autosave gagal).
            foreach ((array) $request->input('answers', []) as $itemId => $value) {
                $item = $section->questions()->whereKey((int) $itemId)->first();
                if (! $item) {
                    continue;
                }
                try {
                    $item->correct_option_id === null
                        ? $this->attempts->answerEssay($item, is_string($value) ? $value : null, $item->is_flagged)
                        : $this->attempts->answer($item, is_numeric($value) ? (int) $value : null, $item->is_flagged);
                } catch (ExamAccessException) {
                    break; // waktu habis: jawaban yang sudah tersimpan tetap dipakai
                }
            }

            $this->attempts->submitSection($section->fresh(), $request->boolean('timeout') ? 'timeout' : 'student');
        }

        $attempt->refresh();
        if ($attempt->isFinished()) {
            return redirect()->route('student.attempts.result', $attempt);
        }

        return redirect()->route('student.attempts.show', $attempt)->with('success', 'Bagian sebelumnya sudah dikumpulkan. Lanjut ke bagian berikutnya.');
    }

    public function result(Request $request, ExamAttempt $attempt): View|RedirectResponse
    {
        $this->authorizeOwner($request, $attempt);
        if (! $attempt->isFinished()) {
            return redirect()->route('student.attempts.show', $attempt);
        }
        $attempt->load(['exam.level.testType', 'sections.examSection.section', 'certificate']);
        $history = $request->user()->attempts()->with('exam')
            ->where('status', '!=', 'ongoing')->whereKeyNot($attempt->id)
            ->latest('finished_at')->take(5)->get();

        return view('student.result', ['attempt' => $attempt, 'history' => $history]);
    }

    /**
     * Student menekan tombol putar: cek batas putar, catat, lalu beri URL audio bertanda tangan (berlaku 10 menit).
     */
    public function play(Request $request, ExamAttempt $attempt, int $item): JsonResponse
    {
        $this->authorizeOwner($request, $attempt);
        $row = $this->findItem($attempt, $item);
        abort_unless($row->attemptSection->isRunning() && $this->audioPath($row), 404);

        $limit = $attempt->exam->audio_play_limit;
        $allowed = DB::transaction(function () use ($row, $limit) {
            $row = AttemptQuestion::whereKey($row->id)->lockForUpdate()->first();
            if ($limit !== null && $row->audio_play_count >= $limit) {
                return false;
            }
            $row->increment('audio_play_count');

            return true;
        });

        if (! $allowed) {
            return response()->json(['ok' => false, 'message' => 'Batas putar audio sudah habis.'], 409);
        }

        return response()->json([
            'ok' => true,
            'url' => URL::temporarySignedRoute('student.attempts.audio', now()->addMinutes(10), ['attempt' => $attempt->id, 'item' => $item]),
            'plays_left' => $limit === null ? null : max(0, $limit - $row->fresh()->audio_play_count),
        ]);
    }

    public function audio(Request $request, ExamAttempt $attempt, int $item): StreamedResponse
    {
        $this->authorizeOwner($request, $attempt);
        $path = $this->audioPath($this->findItem($attempt, $item));
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    private function authorizeOwner(Request $request, ExamAttempt $attempt): void
    {
        abort_unless($attempt->student_id === $request->user()->id, 403);
    }

    private function findItem(ExamAttempt $attempt, int $itemId): AttemptQuestion
    {
        return AttemptQuestion::with(['attemptSection', 'question.passage'])
            ->whereKey($itemId)
            ->whereHas('attemptSection', fn ($q) => $q->where('exam_attempt_id', $attempt->id))
            ->firstOrFail();
    }

    private function audioPath(AttemptQuestion $item): ?string
    {
        return $item->question->audio_path ?: $item->question->passage?->audio_path;
    }
}
