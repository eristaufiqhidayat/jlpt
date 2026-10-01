<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

/**
 * Pembayaran manual (transfer bank dicek admin). Integrasi payment gateway menyusul;
 * gateway nanti cukup mengubah status payment menjadi "paid" lewat webhook.
 */
class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $payments = Payment::with(['student', 'exam'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.payments.index', [
            'payments' => $payments,
            'students' => User::where('role', Role::Student)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'exams' => Exam::where('is_paid', true)->orderByDesc('id')->get(['id', 'title', 'code', 'price']),
            'statuses' => PaymentStatus::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Student->value)],
            'exam_id' => ['required', Rule::exists('exams', 'id')->where('is_paid', true)],
            'amount' => ['required', 'integer', 'min:0'],
            'gateway_ref' => ['nullable', 'string', 'max:100'],
        ], [], ['student_id' => 'student', 'exam_id' => 'ujian', 'amount' => 'jumlah', 'gateway_ref' => 'no. referensi']);

        $alreadyPaid = Payment::where('student_id', $data['student_id'])->where('exam_id', $data['exam_id'])
            ->where('status', PaymentStatus::Paid)->exists();
        if ($alreadyPaid) {
            return back()->with('warning', 'Student ini sudah tercatat lunas untuk ujian tersebut.');
        }

        Payment::create($data + [
            'order_id' => 'MAN-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'status' => PaymentStatus::Paid,
            'gateway' => 'manual',
            'paid_at' => now(),
        ]);

        return back()->with('success', 'Pembayaran dicatat. Student sekarang bisa mengikuti ujian tersebut.');
    }

    public function updateStatus(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', new Enum(PaymentStatus::class)]]);
        $payment->update([
            'status' => $data['status'],
            'paid_at' => $data['status'] === PaymentStatus::Paid->value ? ($payment->paid_at ?? now()) : $payment->paid_at,
        ]);

        return back()->with('success', 'Status pembayaran diperbarui.');
    }
}
