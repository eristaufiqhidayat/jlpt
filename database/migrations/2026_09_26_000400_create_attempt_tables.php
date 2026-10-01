<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengerjaan ujian oleh student.
 *
 * Soal diacak saat student mulai dan disalin ke attempt_questions (termasuk kunci saat itu),
 * sehingga nilai tetap konsisten walaupun bank soal diedit belakangan.
 * Waktu dihitung di server lewat attempt_sections.deadline_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->decimal('score', 5, 2)->nullable();       // 0–100
            $table->string('status', 20)->default('ongoing'); // ongoing | finished
            $table->timestamps();

            $table->index(['exam_id', 'student_id']);
        });

        Schema::create('attempt_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->unsignedSmallInteger('duration_minutes'); // disalin dari exam_sections saat mulai
            $table->dateTime('started_at')->nullable();
            $table->dateTime('deadline_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->string('submitted_by', 20)->nullable();   // student | timeout
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'exam_section_id']);
            $table->index(['submitted_at', 'deadline_at']);
        });

        Schema::create('attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->foreignId('correct_option_id')->nullable()->constrained('question_options')->nullOnDelete();
            $table->foreignId('selected_option_id')->nullable()->constrained('question_options')->nullOnDelete();
            $table->boolean('is_flagged')->default(false);    // tanda "ragu-ragu"
            $table->boolean('is_correct')->nullable();        // diisi saat bagian dinilai
            $table->unsignedTinyInteger('audio_play_count')->default(0);
            $table->dateTime('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_section_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_questions');
        Schema::dropIfExists('attempt_sections');
        Schema::dropIfExists('exam_attempts');
    }
};
