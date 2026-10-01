<?php

namespace App\Http\Controllers\Student;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Payment;
use App\Services\ExamAccessService;
use App\Services\MidtransClient;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly MidtransClient $midtrans,
        private readonly ExamAccessService $access,
    ) {}

    /** Halaman pembayaran untuk satu ujian berbayar. */
    public function show(Request $request, string $code): View|RedirectResponse
    {
        $exam = $this->findExam($code);
        $student = $request->user();

        // Student kembali dari Midtrans: cek status terbaru transaksi yang masih pending.
        $pending = $this->pendingPayment($student->id, $exam);
        if ($pending && $this->midtrans->isConfigured()) {
            try {
                $pending = $this->payments->refresh($pending);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return view('student.payment', [
            'exam' => $exam->load('level.testType', 'sections'),
            'paid' => $this->access->hasPaid($student, $exam),
            'pending' => $pending?->status === PaymentStatus::Pending ? $pending : null,
            'online' => $this->midtrans->isConfigured(),
            'snapJs' => $this->midtrans->snapJsUrl(),
            'clientKey' => $this->midtrans->clientKey(),
        ]);
    }

    /** Buat (atau pakai ulang) transaksi Snap dan kembalikan token. */
    public function pay(Request $request, string $code): JsonResponse|RedirectResponse
    {
        $exam = $this->findExam($code);

        if ($this->access->hasPaid($request->user(), $exam)) {
            return $request->expectsJson()
                ? response()->json(['paid' => true])
                : redirect()->route('student.payments.show', $exam->code);
        }

        try {
            $payment = $this->payments->checkout($request->user(), $exam);
        } catch (Throwable $e) {
            report($e);
            $msg = 'Pembayaran online sedang tidak bisa diproses. Coba lagi nanti atau hubungi admin.';

            return $request->expectsJson()
                ? response()->json(['message' => $msg], 503)
                : back()->with('error', $msg);
        }

        $snap = $payment->gateway_payload;

        return $request->expectsJson()
            ? response()->json(['token' => $snap['snap_token'], 'order_id' => $payment->order_id])
            : redirect()->away($snap['redirect_url']);
    }

    /** Dipanggil setelah popup Snap ditutup/berhasil: cek status ke Midtrans. */
    public function refresh(Request $request, string $code): RedirectResponse
    {
        $exam = $this->findExam($code);
        $pending = $this->pendingPayment($request->user()->id, $exam);

        if ($pending) {
            try {
                $pending = $this->payments->refresh($pending);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $msg = match ($pending?->status) {
            PaymentStatus::Paid => 'Pembayaran berhasil. Silakan mulai ujian.',
            PaymentStatus::Pending => 'Pembayaran belum diterima. Selesaikan pembayaran sesuai instruksi, lalu tekan "Cek status".',
            PaymentStatus::Expired => 'Waktu pembayaran habis. Silakan bayar ulang.',
            PaymentStatus::Failed => 'Pembayaran gagal atau dibatalkan. Silakan coba lagi.',
            default => null,
        };

        return redirect()->route('student.payments.show', $exam->code)
            ->with($pending?->status === PaymentStatus::Paid ? 'success' : 'warning', $msg);
    }

    private function findExam(string $code): Exam
    {
        $exam = Exam::where('code', strtoupper($code))->where('is_paid', true)->firstOrFail();
        abort_unless($exam->isOpen(), 404);

        return $exam;
    }

    private function pendingPayment(int $studentId, Exam $exam): ?Payment
    {
        return Payment::where('student_id', $studentId)->where('exam_id', $exam->id)
            ->where('gateway', 'midtrans')->where('status', PaymentStatus::Pending)
            ->latest('id')->first();
    }
}
