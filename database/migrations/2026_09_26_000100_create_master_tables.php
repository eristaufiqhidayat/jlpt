<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data master: jenis tes → level → bagian (section).
 *
 * Contoh: JLPT → N4 → Moji Goi / Bunpou Dokkai / Choukai.
 * Durasi di tabel sections adalah durasi standar; sensei bisa mengubahnya per ujian (exam_sections).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();   // JLPT, JTEST, NATTEST, KANJI, PROMETRIC, ...
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_type_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);             // N5..N1, A2, P5 ...
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['test_type_id', 'code']);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);             // moji_goi, bunpou_dokkai, choukai
            $table->string('name');                 // Moji Goi (文字・語彙)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('default_duration_minutes');
            $table->unsignedSmallInteger('default_question_count')->default(0);
            $table->timestamps();

            $table->unique(['level_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
        Schema::dropIfExists('levels');
        Schema::dropIfExists('test_types');
    }
};
