<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data untuk halaman Pengawas:
 * - users.last_login_at              : kapan terakhir login (daftar peserta yang sudah masuk)
 * - exam_attempts.current_question_no: nomor soal yang sedang dibuka peserta di bagian berjalan
 * - exam_attempts.last_seen_at       : sinyal terakhir dari halaman ujian (online/offline)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('last_login_at')->nullable()->after('is_active');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_question_no')->nullable()->after('status');
            $table->dateTime('last_seen_at')->nullable()->after('current_question_no');
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_seen_at']);
            $table->dropColumn(['current_question_no', 'last_seen_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_login_at'));
    }
};
