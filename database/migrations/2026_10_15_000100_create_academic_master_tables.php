<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data master akademik yang dikelola Admin Pendidikan (notulen JGG Learning System):
 *
 * - curricula            : klasifikasi kelas (Basic → Minna I/II, JFT, N3, SSW per bidang). Urutan dipakai untuk lock.
 * - test_types (+kolom)  : skor maksimal & batas lulus per jenis tes, beserta siapa yang menyetujui.
 * - levels/sections      : bisa dinonaktifkan (data yang sudah dipakai tidak dihapus).
 * - violation_categories : kategori catatan batsu (pelanggaran).
 * - master_data_logs     : jejak setiap perubahan data master (siapa, kapan, nilai lama → baru).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curricula', function (Blueprint $table) {
            $table->id();
            $table->string('group', 40);                         // Kelas Basic, Kelas JFT, Kelas N3, Kelas SSW
            $table->string('name', 100);                         // Minna no Nihongo I, SSW · Kaigo
            $table->string('code', 30)->unique();                // MINNA1, SSW-KAIGO
            $table->string('scope', 150)->nullable();            // cakupan, mis. "Tata bahasa, kanji, dokkai"
            $table->unsignedSmallInteger('chapter_from')->nullable();
            $table->unsignedSmallInteger('chapter_to')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0); // urutan naik kelas (dasar lock)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('curriculum_id')->nullable()->constrained('curricula')->nullOnDelete();
        });

        Schema::table('test_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_score')->nullable();   // JLPT 180, J-Test 350, NAT-Test 180, JFT 250
            $table->unsignedSmallInteger('pass_score')->nullable();  // batas lulus simulasi, dalam skala max_score
            $table->string('score_approved_by', 100)->nullable();    // nama/jabatan yang menyetujui angka skor
            $table->date('score_approved_at')->nullable();
        });

        Schema::table('levels', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::create('violation_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('description', 150)->nullable();
            $table->string('color', 10)->default('warn');            // warna badge: warn | err | info | ok | grey
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('master_data_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 40);                       // curriculum | test-type | level | section | violation-category
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 150);
            $table->string('action', 20);                             // created | updated | activated | deactivated
            $table->json('changes')->nullable();                      // [{field, old, new}]
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_logs');
        Schema::dropIfExists('violation_categories');
        Schema::table('sections', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('levels', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('test_types', fn (Blueprint $table) => $table->dropColumn(['max_score', 'pass_score', 'score_approved_by', 'score_approved_at']));
        Schema::table('classrooms', fn (Blueprint $table) => $table->dropConstrainedForeignId('curriculum_id'));
        Schema::dropIfExists('curricula');
    }
};
