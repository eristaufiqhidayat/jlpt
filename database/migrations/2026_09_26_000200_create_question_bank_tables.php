<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank soal.
 *
 * - passages  : bacaan / audio yang dipakai bersama oleh beberapa soal (Dokkai, Choukai, soal isian wacana).
 * - questions : satu butir soal. type = blank | star | reading | listening.
 * - question_options : pilihan jawaban (teks dan/atau gambar), tepat satu is_correct = true.
 *
 * Teks Jepang disimpan dengan notasi furigana {漢字|かんじ}; tampilan diubah menjadi <ruby> di view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();   // teks bacaan, boleh berisi penanda {{21}} untuk soal isian
            $table->string('image_path')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('status', 20)->default('draft'); // draft | published | archived
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('passage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('blank');   // blank | star | reading | listening
            $table->string('mondai', 50)->nullable();        // label kelompok soal, mis. "問題1"
            $table->unsignedSmallInteger('number_in_passage')->nullable();
            $table->longText('stem')->nullable();            // teks soal (boleh kosong untuk choukai)
            $table->string('image_path')->nullable();
            $table->string('audio_path')->nullable();
            $table->unsignedTinyInteger('star_position')->nullable(); // posisi ★ untuk soal menyusun kalimat
            $table->string('correct_order', 20)->nullable();          // urutan benar soal ★, mis. "4-1-3-2"
            $table->text('explanation')->nullable();
            $table->string('status', 20)->default('draft');  // draft | published | archived
            $table->foreignId('legacy_file_id')->nullable()->index(); // asal soal dari arsip lama (opsional)
            $table->unsignedSmallInteger('legacy_number')->nullable();
            $table->timestamps();

            $table->index(['section_id', 'status']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('label');            // 1..4
            $table->text('content')->nullable();             // boleh kosong (choukai: pilihan hanya diucapkan)
            $table->string('image_path')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->timestamps();

            $table->unique(['question_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('passages');
    }
};
