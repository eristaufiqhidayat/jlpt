<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ujian yang dibuat sensei.
 *
 * - exams         : satu ujian dengan kode akses untuk student; bisa gratis atau berbayar.
 * - exam_sections : bagian ujian + durasi dan jumlah soal yang ditentukan sensei.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensei_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();            // kode yang dibagikan ke student
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->unsignedInteger('price')->default(0);    // Rupiah
            $table->unsignedTinyInteger('audio_play_limit')->nullable(); // null = bebas, 1 = sekali putar (seperti JLPT asli)
            $table->boolean('shuffle_options')->default(false);
            $table->boolean('show_result_immediately')->default(true);
            $table->string('status', 20)->default('draft');  // draft | open | closed
            $table->timestamps();
        });

        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('question_count');
            $table->timestamps();

            $table->unique(['exam_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sections');
        Schema::dropIfExists('exams');
    }
};
