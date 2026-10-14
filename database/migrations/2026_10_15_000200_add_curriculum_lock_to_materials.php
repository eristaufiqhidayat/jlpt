<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materi per kurikulum + lock (notulen 3.b, 4.a, 5.a):
 *
 * - kind = core  : materi pakem (Minna I/II, JFT, N3, SSW). Hanya Admin Pendidikan yang boleh mengubah.
 *                  Dikaitkan ke kurikulum + nomor bab; peserta hanya membuka kurikulum kelasnya dan yang sebelumnya.
 *                  Materi pakem tanpa kurikulum = "materi umum" (perilaku lama, disaring per level).
 * - kind = extra : materi tambahan milik sensei untuk satu kelas yang dipegangnya; dibuka/tutup lewat status
 *                  dan bisa diberi masa tayang (available_until).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('kind', 10)->default('core')->index();
            $table->foreignId('curriculum_id')->nullable()->constrained('curricula')->nullOnDelete();
            $table->unsignedSmallInteger('chapter_no')->nullable();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->cascadeOnDelete();
            $table->date('available_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('classroom_id');
            $table->dropConstrainedForeignId('curriculum_id');
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'chapter_no', 'available_until']);
        });
    }
};
