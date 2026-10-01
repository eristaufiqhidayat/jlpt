<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai minimal lulus per ujian (opsional). Bila diisi, halaman hasil menampilkan "Lulus" / "Belum lulus".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedTinyInteger('pass_score')->nullable()->after('price'); // 0–100
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('pass_score');
        });
    }
};
