<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arsip data aplikasi lama (diisi oleh `php artisan legacy:import`).
 *
 * - legacy_exams        : baris m_exam (paket). Beberapa paket boleh berbagi kode_exam (soal sama).
 * - legacy_exam_files   : baris detail_soal (satu file PDF per bagian), terhubung lewat kode_exam.
 * - legacy_answer_keys  : baris jwb_soal (kunci per nomor), sudah dinormalisasi A–D → 1–4.
 *
 * Arsip ini menjadi sumber sensei saat menginput ulang soal ke bank soal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_exams', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id')->unique();   // m_exam.id
            $table->string('kode_exam', 100)->index();
            $table->string('jenis_tes', 50)->nullable();
            $table->string('kelas', 20)->nullable();          // level, mis. N5
            $table->string('ulevel', 20)->nullable();         // paket, mis. N56
            $table->string('nama_tes')->nullable();
            $table->string('access_key', 50)->nullable();     // m_exam.knc
            $table->string('status', 20)->nullable();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('legacy_created_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legacy_exam_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id')->unique();   // detail_soal.id_soal
            $table->string('kode_exam', 100)->index();
            $table->string('section_name', 100)->nullable();  // detail_soal.ket_soal
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stored_filename')->nullable();    // detail_soal.data_soal (....html)
            $table->string('original_filename')->nullable();  // detail_soal.flag3 (....pdf)
            $table->string('file_path')->nullable();          // lokasi baru di storage/app/private
            $table->string('file_mime', 100)->nullable();
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->unsignedSmallInteger('key_count')->default(0);
            $table->unsignedSmallInteger('valid_key_count')->default(0);
            // ok | incomplete | invalid_keys | no_key_needed
            $table->string('key_status', 20)->index();
            $table->json('issues')->nullable();               // daftar masalah untuk ditinjau sensei
            $table->dateTime('legacy_created_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legacy_answer_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legacy_exam_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('legacy_id')->unique();   // jwb_soal.id
            $table->unsignedSmallInteger('number');           // jwb_soal.no_soal
            $table->string('raw_key', 10)->nullable();        // nilai asli (A, B, Z, F, ...)
            $table->unsignedTinyInteger('option_label')->nullable(); // 1..4, null jika tidak valid
            $table->boolean('is_valid')->default(false);
            $table->timestamps();

            $table->index(['legacy_exam_file_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_answer_keys');
        Schema::dropIfExists('legacy_exam_files');
        Schema::dropIfExists('legacy_exams');
    }
};
