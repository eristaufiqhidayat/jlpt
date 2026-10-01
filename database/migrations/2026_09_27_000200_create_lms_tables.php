<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul LMS & program Jepang (mockup JGG):
 * profil peserta, kelas & jadwal & kehadiran, materi & progres, tahapan program Jepang & dokumen,
 * sertifikat, dan pesan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('nis', 30)->nullable()->unique();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete(); // level belajar saat ini
            $table->string('program_name')->nullable(); // mis. Pemagangan (Tokutei Ginou)
            $table->date('joined_at')->nullable();
            $table->timestamps();
        });

        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);                 // mis. N4-A
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sensei_id')->nullable()->constrained('users')->nullOnDelete(); // wali kelas
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('classroom_student', function (Blueprint $table) {
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['classroom_id', 'student_id']);
        });

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sensei_id')->nullable()->constrained('users')->nullOnDelete(); // pengajar sesi
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('topic');
            $table->timestamps();
            $table->index(['date', 'classroom_id']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10); // hadir | izin | sakit | alpa
            $table->timestamps();
            $table->unique(['class_session_id', 'student_id']);
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chapter')->nullable();       // mis. Minna no Nihongo Bab 15
            $table->string('title');
            $table->string('category', 40)->nullable();  // Tata Bahasa, Kanji, Choukai, ...
            $table->text('description')->nullable();
            $table->string('video_url')->nullable();     // YouTube / tautan video
            $table->string('video_path')->nullable();    // atau file video diunggah
            $table->string('pdf_path')->nullable();
            $table->string('ppt_path')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('exam_id')->nullable()->constrained()->nullOnDelete(); // latihan soal
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('material_progress', function (Blueprint $table) {
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('completed_at');
            $table->primary(['material_id', 'student_id']);
        });

        Schema::create('program_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('stage', 20);                 // pendaftaran | belajar | jlpt | interview | mcu | visa | berangkat
            $table->string('status', 10)->default('belum'); // belum | proses | selesai
            $table->date('date')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'stage']);
        });

        Schema::create('program_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30);                  // paspor | sertifikat_jlpt | interview | mcu | visa | tiket
            $table->string('status', 10)->default('belum');
            $table->string('file_path')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'type']);
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->unique();      // JGG-JLPT-2026-0061
            $table->decimal('score', 5, 2);
            $table->string('verify_token', 40)->unique();
            $table->dateTime('issued_at');
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'read_at']);
        });
    }

    public function down(): void
    {
        foreach (['messages', 'certificates', 'program_documents', 'program_stages', 'material_progress', 'materials',
            'attendances', 'class_sessions', 'classroom_student', 'classrooms', 'student_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
