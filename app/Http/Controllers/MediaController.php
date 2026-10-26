<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Services\MaterialAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mengirim gambar/audio soal dari disk privat. Hanya file di folder "questions/" dan "passages/".
 * Audio choukai untuk student dikirim lewat AttemptController::audio (dengan batas putar).
 */
class MediaController extends Controller
{
    public function __invoke(string $path): StreamedResponse
    {
        $path = ltrim($path, '/');
        abort_if(str_contains($path, '..'), 404);
        abort_unless(str_starts_with($path, 'questions/') || str_starts_with($path, 'passages/') || str_starts_with($path, 'materials/'), 404);

        // Audio soal hanya untuk sensei/admin; student lewat rute ujian yang membatasi pemutaran.
        // Materi belajar (video/PDF/PPT) boleh dibuka semua user yang login.
        $isAudio = ! str_starts_with($path, 'materials/') && preg_match('/\.(mp3|m4a|mp4|wav|ogg)$/i', $path); // mp4: audio m4a lama yang tersimpan dengan ekstensi tebakan
        abort_if($isAudio && request()->user()->isExamTaker(), 403);

        // File materi untuk peserta mengikuti lock yang sama dengan halaman materi (materials/{id}/nama-file).
        abort_if(str_starts_with($path, 'materials/') && request()->user()->isTestTaker(), 403); // peserta tes umum tidak punya akses LMS
        if (str_starts_with($path, 'materials/') && request()->user()->isStudent()) {
            $material = Material::find((int) (explode('/', $path)[1] ?? 0));
            abort_unless($material && app(MaterialAccess::class)->canView(request()->user(), $material), 403);
        }

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
