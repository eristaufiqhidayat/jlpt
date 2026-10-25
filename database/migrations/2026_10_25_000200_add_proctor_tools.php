<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alat pengawasan tambahan:
 * - berapa kali peserta keluar dari halaman ujian (pindah tab/jendela) dan kapan terakhir,
 * - tambahan waktu dari pengawas (total menit + siapa yang terakhir menambah),
 * - kapan siswa terakhir membuka halaman Ujian (untuk daftar "siap ujian" di halaman pengawas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('focus_lost_count')->default(0)->after('last_seen_at');
            $table->timestamp('last_focus_lost_at')->nullable()->after('focus_lost_count');
            $table->unsignedSmallInteger('extra_minutes')->default(0)->after('last_focus_lost_at');
            $table->foreignId('extended_by')->nullable()->after('extra_minutes')->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('exam_page_seen_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('extended_by');
            $table->dropColumn(['focus_lost_count', 'last_focus_lost_at', 'extra_minutes']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('exam_page_seen_at'));
    }
};
