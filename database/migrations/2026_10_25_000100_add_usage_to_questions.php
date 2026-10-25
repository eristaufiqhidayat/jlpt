<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penggunaan soal: latihan (practice), tes online (test), atau keduanya (both).
 * Soal lama menjadi "latihan" sehingga ujian internal tetap berjalan seperti sebelumnya.
 *
 * Sumber soal ujian (exams.question_pool): null = otomatis mengikuti "Untuk siapa"
 * (siswa LPK → latihan, peserta tes umum/keduanya → tes), atau dipilih manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('usage', 10)->default('practice')->after('status');
            $table->index(['section_id', 'status', 'usage']);
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->string('question_pool', 10)->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['section_id', 'status', 'usage']);
            $table->dropColumn('usage');
        });
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn('question_pool'));
    }
};
