<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sasaran ujian: siswa LPK (internal), peserta tes umum di testonline (public), atau keduanya (both). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('audience', 10)->default('internal')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn('audience'));
    }
};
