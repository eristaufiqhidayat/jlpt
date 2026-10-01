<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Exam;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pembayaran ujian lewat Midtrans Snap.
 *
 * Status dari Midtrans → status di aplikasi:
 *   settlement, capture (fraud accept)  → paid
 *   pending, capture (fraud challenge)  → pending
 *   expire                              → expired
 *   deny, cancel, failure               → failed
 *   refund, partial_refund              → refunded
 */
class PaymentService
{
    public function __construct(private readonly MidtransClient $midtrans) {}

    /** Ambil transaksi pending yang masih berlaku, atau buat yang baru. */
    public function checkout(User $student, Exam $exam): Payment
    {
        $existing = Payment::where('student_id', $student->id)->where('exam_id', $exam->id)
            ->where('status', PaymentStatus::Pending)->where('gateway', 'midtrans')
            ->where('amount', $exam->price)
            ->where('expires_at', '>', now()->addMinutes(2))
            ->latest('id')->first();

        if ($existing && ! empty($existing->gateway_payload['snap_token'])) {
            return $existing;
        }

        $orderId = sprintf('JLPT-%d-%d-%s', $exam->id, $student->id, Str::upper(Str::random(6)));
        $minutes = max(15, (int) config('services.midtrans.expiry_minutes', 60));

        $snap = $this->midtrans->createTransaction([
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => (int) $exam->price],
            'item_details' => [[
                'id' => 'EXAM-'.$exam->id,
                'price' => (int) $exam->price,
                'quantity' => 1,
                'name' => Str::limit($exam->title, 45, ''),
            ]],
            'customer_details' => array_filter([
                'first_name' => Str::limit($student->name, 50, ''),
                'email' => $student->email,
                'phone' => $student->phone,
            ]),
            'callbacks' => ['finish' => route('student.payments.show', $exam->code)],
            'expiry' => ['unit' => 'minutes', 'duration' => $minutes],
        ]);

        return Payment::create([
            'student_id' => $student->id,
            'exam_id' => $exam->id,
            'order_id' => $orderId,
            'amount' => (int) $exam->price,
            'status' => PaymentStatus::Pending,
            'gateway' => 'midtrans',
            'gateway_payload' => ['snap_token' => $snap['token'], 'redirect_url' => $snap['redirect_url']],
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    /** Cek langsung ke Midtrans (dipakai saat student kembali dari halaman bayar). */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->gateway !== 'midtrans' || $payment->status === PaymentStatus::Paid) {
            return $payment;
        }

        $status = $this->midtrans->status($payment->order_id);
        if ($status) {
            $this->apply($payment, $status);
        } elseif ($payment->expires_at?->isPast()) {
            $payment->update(['status' => PaymentStatus::Expired]);
        }

        return $payment->fresh();
    }

    /**
     * Terapkan data status dari Midtrans (notifikasi yang sudah terverifikasi, atau hasil status()).
     */
    public function apply(Payment $payment, array $data): void
    {
        DB::transaction(function () use ($payment, $data) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            // Nominal harus sama dengan yang ditagihkan.
            if (isset($data['gross_amount']) && (int) round((float) $data['gross_amount']) !== (int) $payment->amount) {
                Log::warning('Midtrans: nominal tidak cocok', ['order_id' => $payment->order_id, 'data' => $data]);

                return;
            }

            $new = $this->mapStatus($data['transaction_status'] ?? '', $data['fraud_status'] ?? null);
            if ($new === null) {
                return;
            }

            // Pembayaran yang sudah lunas hanya boleh berubah menjadi refund.
            if ($payment->status === PaymentStatus::Paid && $new !== PaymentStatus::Refunded) {
                return;
            }

            $history = $payment->gateway_payload ?? [];
            $history['last_notification'] = collect($data)->except(['signature_key'])->all();

            $payment->update([
                'status' => $new,
                'gateway_ref' => $data['transaction_id'] ?? $payment->gateway_ref,
                'gateway_payload' => $history,
                'paid_at' => $new === PaymentStatus::Paid ? ($payment->paid_at ?? now()) : $payment->paid_at,
            ]);
        });
    }

    public function mapStatus(string $transactionStatus, ?string $fraudStatus): ?PaymentStatus
    {
        return match ($transactionStatus) {
            'settlement' => PaymentStatus::Paid,
            'capture' => match ($fraudStatus) {
                'accept', null => PaymentStatus::Paid,
                'challenge' => PaymentStatus::Pending,
                default => PaymentStatus::Failed,
            },
            'pending' => PaymentStatus::Pending,
            'expire' => PaymentStatus::Expired,
            'deny', 'cancel', 'failure' => PaymentStatus::Failed,
            'refund', 'partial_refund' => PaymentStatus::Refunded,
            default => null,
        };
    }
}
