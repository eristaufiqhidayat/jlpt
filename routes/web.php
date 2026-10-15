<?php

use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Manage\ClassroomController;
use App\Http\Controllers\Manage\MasterDataController;
use App\Http\Controllers\Manage\MaterialController;
use App\Http\Controllers\Manage\ProgramController;
use App\Http\Controllers\Manage\ReportController;
use App\Http\Controllers\Manage\UploadController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Student\LearningController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\Sensei\ExamController;
use App\Http\Controllers\Sensei\GradingController;
use App\Http\Controllers\Sensei\LegacyArchiveController;
use App\Http\Controllers\Sensei\PassageController;
use App\Http\Controllers\Sensei\QuestionController;
use App\Http\Controllers\Sensei\QuestionImportController;
use App\Http\Controllers\Student\AttemptController;
use App\Http\Controllers\Student\PaymentController as StudentPaymentController;
use Illuminate\Support\Facades\Route;

// Notifikasi pembayaran dari Midtrans (tanpa login; dicek dengan signature_key)
Route::post('/payments/midtrans/notification', MidtransNotificationController::class)
    ->middleware('throttle:120,1')->name('payments.midtrans.notification');

// Verifikasi sertifikat (publik, dari tautan di sertifikat)
Route::get('/verifikasi/{token}', [CertificateController::class, 'verify'])->name('certificates.verify');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    // Demo masuk cepat: hanya aktif bila DEMO_LOGIN=true (dicek di controller)
    Route::post('/login/demo/{role}', [LoginController::class, 'demo'])->middleware('throttle:20,1')->name('login.demo');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    // Semua role
    Route::get('/profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/pesan', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/pesan/tulis', [MessageController::class, 'create'])->name('messages.create');
    Route::post('/pesan', [MessageController::class, 'store'])->name('messages.store');
    Route::get('/pesan/{message}', [MessageController::class, 'show'])->name('messages.show');
    Route::get('/sertifikat/{certificate}', [CertificateController::class, 'show'])->name('certificates.show');
    Route::get('/program/dokumen/{document}', [ProgramController::class, 'document'])->name('program.document');

    // Gambar/audio soal (disimpan privat, hanya untuk user yang login)
    Route::get('/media/{path}', MediaController::class)->where('path', '.*')->name('media');

    // ---------------- Admin ----------------
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::post('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('payments/{payment}/status', [PaymentController::class, 'updateStatus'])->name('payments.status');
    });

    // ---------------- Laporan & monitoring (direktur, admin; sensei untuk monitoring) ----------------
    Route::middleware('role:director,admin,education_admin')->get('/laporan', ReportController::class)->name('reports');
    Route::middleware('role:director,admin,education_admin,sensei')->prefix('manage')->name('manage.')->group(function () {
        // Data master akademik: semua staf boleh melihat
        Route::get('master', [MasterDataController::class, 'index'])->name('master.index');
        Route::get('program', [ProgramController::class, 'index'])->name('program.index');
        Route::get('program/{student}', [ProgramController::class, 'show'])->name('program.show');
    });

    // ---------------- Data master akademik: hanya Admin Pendidikan yang boleh mengubah ----------------
    Route::middleware('role:education_admin')->prefix('manage/master')->name('manage.master.')
        ->where(['entity' => implode('|', MasterDataController::ENTITIES), 'id' => '[0-9]+'])->group(function () {
            Route::get('{entity}/create', [MasterDataController::class, 'create'])->name('create');
            Route::post('{entity}', [MasterDataController::class, 'store'])->name('store');
            Route::get('{entity}/{id}/edit', [MasterDataController::class, 'edit'])->name('edit');
            Route::put('{entity}/{id}', [MasterDataController::class, 'update'])->name('update');
            Route::post('{entity}/{id}/toggle', [MasterDataController::class, 'toggle'])->name('toggle');
        });

    // ---------------- Upload bertahap file materi (yang boleh membuat materi) ----------------
    Route::middleware(['role:sensei,education_admin', 'throttle:600,1'])->prefix('manage/uploads')->name('manage.uploads.')->group(function () {
        Route::post('/', [UploadController::class, 'start'])->name('start');
        Route::post('{upload}/chunk', [UploadController::class, 'chunk'])->name('chunk');
        Route::delete('{upload}', [UploadController::class, 'destroy'])->name('destroy');
    });

    // ---------------- Materi, kelas, program (sensei, admin, Admin Pendidikan) ----------------
    Route::middleware('role:sensei,admin,education_admin')->prefix('manage')->name('manage.')->group(function () {
        Route::resource('materials', MaterialController::class); // show = lihat/pakai untuk mengajar (semua staf, tanpa lock)
        Route::resource('classes', ClassroomController::class)->except(['destroy']);
        Route::post('classes/{class}/students', [ClassroomController::class, 'addStudents'])->name('classes.students.add');
        Route::delete('classes/{class}/students/{student}', [ClassroomController::class, 'removeStudent'])->name('classes.students.remove');
        Route::post('classes/{class}/sessions', [ClassroomController::class, 'storeSession'])->name('classes.sessions.store');
        Route::delete('sessions/{session}', [ClassroomController::class, 'destroySession'])->name('sessions.destroy');
        Route::get('sessions/{session}/attendance', [ClassroomController::class, 'attendance'])->name('sessions.attendance');
        Route::post('sessions/{session}/attendance', [ClassroomController::class, 'saveAttendance'])->name('sessions.attendance.save');
        Route::put('program/{student}', [ProgramController::class, 'update'])->name('program.update');
    });

    // ---------------- Sensei (admin dan Admin Pendidikan juga boleh) ----------------
    Route::middleware('role:sensei,admin,education_admin')->prefix('sensei')->name('sensei.')->group(function () {
        // Import harus didaftarkan sebelum resource agar "questions/import" tidak dianggap {question}
        Route::get('questions/import', [QuestionImportController::class, 'create'])->name('questions.import');
        Route::get('questions/import/template', [QuestionImportController::class, 'template'])->name('questions.import.template');
        Route::post('questions/import/preview', [QuestionImportController::class, 'preview'])->name('questions.import.preview');
        Route::post('questions/import', [QuestionImportController::class, 'store'])->name('questions.import.store');
        Route::resource('questions', QuestionController::class)->except(['show']);
        Route::resource('passages', PassageController::class)->except(['show', 'destroy']);

        Route::resource('exams', ExamController::class)->except(['destroy']);
        Route::post('exams/{exam}/status', [ExamController::class, 'updateStatus'])->name('exams.status');
        Route::get('exams/{exam}/export', [ExamController::class, 'export'])->name('exams.export');

        Route::get('grading', [GradingController::class, 'index'])->name('grading.index');
        Route::post('grading/{item}', [GradingController::class, 'store'])->name('grading.store');

        Route::get('legacy', [LegacyArchiveController::class, 'index'])->name('legacy.index');
        Route::get('legacy/{file}', [LegacyArchiveController::class, 'show'])->name('legacy.show');
        Route::get('legacy/{file}/pdf', [LegacyArchiveController::class, 'pdf'])->name('legacy.pdf');
        Route::put('legacy/{file}/keys', [LegacyArchiveController::class, 'updateKeys'])->name('legacy.keys');
        Route::get('legacy/keys/{key}/audio', [LegacyArchiveController::class, 'audio'])->name('legacy.audio');
    });

    // ---------------- Student ----------------
    Route::middleware('role:student')->group(function () {
        Route::post('/exam/join', [AttemptController::class, 'join'])->name('student.join');
        Route::get('/results', [AttemptController::class, 'index'])->name('student.results');
        Route::get('/attempts/{attempt}/review', [AttemptController::class, 'review'])->name('student.attempts.review');
        Route::get('/ujian', [AttemptController::class, 'exams'])->name('student.exams');
        Route::get('/materi', [LearningController::class, 'materials'])->name('student.materials');
        Route::get('/materi/{material}', [LearningController::class, 'material'])->name('student.materials.show');
        Route::post('/materi/{material}/selesai', [LearningController::class, 'completeMaterial'])->name('student.materials.complete');
        Route::get('/kelas', [LearningController::class, 'classes'])->name('student.classes');
        Route::get('/program-jepang', [LearningController::class, 'program'])->name('student.program');
        Route::get('/sertifikat', [CertificateController::class, 'index'])->name('student.certificates');
        Route::get('/pay/{code}', [StudentPaymentController::class, 'show'])->name('student.payments.show');
        Route::post('/pay/{code}', [StudentPaymentController::class, 'pay'])->name('student.payments.pay');
        Route::post('/pay/{code}/refresh', [StudentPaymentController::class, 'refresh'])->name('student.payments.refresh');
        Route::get('/attempts/{attempt}', [AttemptController::class, 'show'])->name('student.attempts.show');
        Route::post('/attempts/{attempt}/answer', [AttemptController::class, 'answer'])->name('student.attempts.answer');
        Route::post('/attempts/{attempt}/submit', [AttemptController::class, 'submitSection'])->name('student.attempts.submit');
        Route::get('/attempts/{attempt}/result', [AttemptController::class, 'result'])->name('student.attempts.result');
        Route::post('/attempts/{attempt}/audio/{item}/play', [AttemptController::class, 'play'])->name('student.attempts.play');
        Route::get('/attempts/{attempt}/audio/{item}', [AttemptController::class, 'audio'])
            ->middleware('signed')->name('student.attempts.audio');
    });
});
