<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CertificateController extends Controller
{
    public function index(Request $request): View
    {
        return view('certificates.index', [
            'certificates' => $request->user()->certificates()->with('exam.level.testType')->latest('issued_at')->get(),
        ]);
    }

    public function show(Request $request, Certificate $certificate): View
    {
        $user = $request->user();
        abort_unless($certificate->student_id === $user->id || in_array($user->role->value, ['admin', 'sensei', 'director']), 403);

        return view('certificates.show', ['certificate' => $certificate->load('student', 'exam.level.testType'), 'public' => false]);
    }

    /** Halaman verifikasi publik (tanpa login), dari tautan/QR di sertifikat. */
    public function verify(string $token): View
    {
        $certificate = Certificate::with('student', 'exam.level.testType')->where('verify_token', $token)->firstOrFail();

        return view('certificates.verify', ['certificate' => $certificate]);
    }
}
