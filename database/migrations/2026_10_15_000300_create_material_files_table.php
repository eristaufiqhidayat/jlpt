<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu materi bisa punya beberapa file video, PDF, dan PPT.
 * File lama di kolom materials.video_path / pdf_path / ppt_path dipindah ke material_files,
 * lalu kolom lama dihapus. Lokasi file di storage tidak berubah (storage/app/private/materials/{id}/…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);                  // video | pdf | ppt
            $table->string('path');
            $table->string('original_name')->nullable(); // nama file saat diunggah, ditampilkan ke peserta
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['material_id', 'type']);
        });

        $now = now();
        foreach (DB::table('materials')->get(['id', 'video_path', 'pdf_path', 'ppt_path']) as $m) {
            foreach (['video' => $m->video_path, 'pdf' => $m->pdf_path, 'ppt' => $m->ppt_path] as $type => $path) {
                if ($path) {
                    DB::table('material_files')->insert([
                        'material_id' => $m->id, 'type' => $type, 'path' => $path, 'original_name' => basename($path),
                        'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['video_path', 'pdf_path', 'ppt_path']);
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('video_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('ppt_path')->nullable();
        });

        // Kembalikan file pertama tiap jenis ke kolom lama.
        foreach (DB::table('material_files')->orderBy('sort_order')->orderBy('id')->get() as $f) {
            $col = $f->type.'_path';
            DB::table('materials')->where('id', $f->material_id)->whereNull($col)->update([$col => $f->path]);
        }

        Schema::dropIfExists('material_files');
    }
};
