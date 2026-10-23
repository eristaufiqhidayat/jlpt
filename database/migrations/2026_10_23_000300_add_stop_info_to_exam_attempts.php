<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catatan bila ujian peserta dihentikan pengawas (per peserta atau saat ujian ditutup). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->foreignId('stopped_by')->nullable()->after('last_seen_at')->constrained('users')->nullOnDelete();
            $table->string('stop_reason')->nullable()->after('stopped_by');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stopped_by');
            $table->dropColumn('stop_reason');
        });
    }
};
