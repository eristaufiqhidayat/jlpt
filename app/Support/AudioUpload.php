<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Aturan & penyimpanan upload audio (mp3, m4a, wav, ogg).
 *
 * Aturan `mimes:` Laravel menebak ekstensi dari isi file. Banyak file .m4a (rekaman Android/Zoom, brand "mp42")
 * terdeteksi sebagai video/mp4 sehingga ditebak ".mp4" dan ditolak, atau tersimpan dengan ekstensi .mp4.
 * Di sini ekstensi asli dicek terpisah dari MIME hasil deteksi isi, dan file disimpan dengan ekstensi aslinya.
 */
final class AudioUpload
{
    public const EXTENSIONS = ['mp3', 'm4a', 'wav', 'ogg'];

    /** MIME hasil deteksi isi file yang diterima (sama dengan uploader materi, ChunkedUploads). */
    public const MIME_TYPES = [
        'audio/mpeg', 'audio/mp3', 'application/octet-stream', // MP3 tanpa tag ID3 kadang tak terdeteksi
        'audio/mp4', 'audio/x-m4a', 'video/mp4',              // M4A
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
        'audio/ogg', 'video/ogg', 'application/ogg',
    ];

    /** Aturan validasi satu file audio, ukuran maksimal dalam KB. */
    public static function rules(int $maxKb): array
    {
        return [
            'file',
            'extensions:'.implode(',', self::EXTENSIONS),
            'mimetypes:'.implode(',', self::MIME_TYPES),
            'max:'.$maxKb,
        ];
    }

    /** Simpan di disk local dengan ekstensi asli (.m4a tetap .m4a, bukan .mp4 hasil tebakan). */
    public static function store(UploadedFile $file, string $dir): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = in_array($ext, self::EXTENSIONS, true) ? $ext : 'mp3';

        return $file->storeAs($dir, Str::random(40).'.'.$ext, 'local');
    }
}
