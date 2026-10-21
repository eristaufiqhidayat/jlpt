<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Upload bertahap (chunk) untuk file materi.
 *
 * File besar dikirim per potongan (CHUNK_SIZE) supaya tidak terbentur post_max_size / upload_max_filesize
 * di hosting, dan bisa dijeda lalu dilanjutkan dari posisi terakhir. Selama belum disimpan, file berada di
 * storage/app/private/tmp-uploads/{id}/ (data + meta.json) dan hanya bisa dipakai pengunggahnya.
 * Saat materi disimpan, file "diklaim": dipindah ke materials/{material_id}/ dan dicatat di material_files.
 * File sementara yang tidak diklaim dibersihkan oleh perintah uploads:prune.
 */
class ChunkedUploads
{
    public const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB, di bawah batas upload bawaan kebanyakan hosting

    /** ekstensi => jenis file materi */
    public const EXTENSIONS = [
        'mp4' => 'video', 'webm' => 'video', 'mov' => 'video',
        'pdf' => 'pdf',
        'ppt' => 'ppt', 'pptx' => 'ppt',
        'mp3' => 'audio', 'm4a' => 'audio', 'wav' => 'audio', 'ogg' => 'audio',
    ];

    /** MIME hasil deteksi isi file yang diterima per ekstensi (pptx sering terdeteksi sebagai zip). */
    private const MIME_ALLOW = [
        'pptx' => ['application/zip', 'application/x-zip-compressed'],
        'ppt' => ['application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'mov' => ['video/mp4'],
        'mp3' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], // MP3 tanpa tag ID3 kadang tak terdeteksi
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
    ];

    private function disk()
    {
        return Storage::disk('local');
    }

    private function dir(string $id): string
    {
        return 'tmp-uploads/'.$id;
    }

    /** Mulai upload: cek ekstensi & ukuran sebelum satu byte pun dikirim. */
    public function start(User $user, string $name, int $size): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $type = self::EXTENSIONS[$ext] ?? null;
        if (! $type) {
            throw ValidationException::withMessages(['name' => 'Jenis file tidak didukung. Gunakan MP4/WEBM/MOV, PDF, PPT/PPTX, atau MP3/M4A/WAV/OGG.']);
        }
        [$label, , , $maxKb] = MaterialFile::TYPES[$type];
        if ($size < 1 || $size > $maxKb * 1024) {
            throw ValidationException::withMessages(['size' => "Ukuran file {$label} maksimal ".$this->human($maxKb * 1024).'.']);
        }

        $id = (string) Str::uuid();
        $this->disk()->put($this->dir($id).'/data', '');
        $this->writeMeta($id, [
            'user_id' => $user->id, 'name' => mb_substr(basename($name), 0, 255), 'ext' => $ext, 'type' => $type,
            'size' => $size, 'received' => 0, 'complete' => false, 'created_at' => now()->toIso8601String(),
        ]);

        return ['id' => $id, 'type' => $type, 'chunk_size' => self::CHUNK_SIZE, 'received' => 0];
    }

    /**
     * Tambahkan satu potongan pada posisi $offset. Posisi harus sama dengan jumlah byte yang sudah diterima;
     * bila tidak (mis. potongan dikirim ulang setelah jeda), kembalikan posisi server agar klien melanjutkan dari sana.
     *
     * @return array{received: int, complete: bool, conflict?: bool}
     */
    public function append(User $user, string $id, int $offset, string $chunkPath): array
    {
        $meta = $this->meta($user, $id);
        if ($meta['complete']) {
            return ['received' => $meta['received'], 'complete' => true];
        }
        if ($offset !== $meta['received']) {
            return ['received' => $meta['received'], 'complete' => false, 'conflict' => true];
        }

        $length = filesize($chunkPath);
        if ($length < 1 || $length > self::CHUNK_SIZE || $meta['received'] + $length > $meta['size']) {
            throw ValidationException::withMessages(['chunk' => 'Potongan file tidak valid.']);
        }

        $target = $this->disk()->path($this->dir($id).'/data');
        $out = fopen($target, 'ab');
        $in = fopen($chunkPath, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        $meta['received'] += $length;
        if ($meta['received'] === $meta['size']) {
            $this->verifyContent($id, $meta);
            $meta['complete'] = true;
        }
        $this->writeMeta($id, $meta);

        return ['received' => $meta['received'], 'complete' => $meta['complete']];
    }

    public function cancel(User $user, string $id): void
    {
        $this->meta($user, $id);
        $this->disk()->deleteDirectory($this->dir($id));
    }

    /**
     * Upload selesai milik pengguna ini, dikelompokkan per jenis, untuk dihitung batasnya sebelum disimpan.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array> id => meta
     */
    public function completed(User $user, array $ids): array
    {
        $result = [];
        foreach (array_unique($ids) as $id) {
            $meta = $this->meta($user, (string) $id, throw: false);
            if (! $meta || ! $meta['complete']) {
                throw ValidationException::withMessages(['uploads' => 'Ada file yang belum selesai diunggah atau sudah kedaluwarsa. Unggah ulang file tersebut.']);
            }
            $result[$id] = $meta;
        }

        return $result;
    }

    /** Pindahkan upload selesai ke materi dan catat di material_files. */
    public function claim(User $user, array $ids, Material $material, int &$order): void
    {
        foreach ($this->completed($user, $ids) as $id => $meta) {
            $path = "materials/{$material->id}/".Str::random(40).'.'.$meta['ext'];
            $this->disk()->move($this->dir($id).'/data', $path);
            $this->disk()->deleteDirectory($this->dir($id));

            $material->files()->create([
                'type' => $meta['type'], 'path' => $path, 'original_name' => $meta['name'],
                'size' => $meta['size'], 'sort_order' => ++$order,
            ]);
        }
    }

    /** Hapus upload sementara yang tidak disimpan lebih dari $hours jam. */
    public function prune(int $hours = 24): int
    {
        $count = 0;
        foreach ($this->disk()->directories('tmp-uploads') as $dir) {
            $metaPath = $dir.'/meta.json';
            $created = $this->disk()->exists($metaPath)
                ? (json_decode($this->disk()->get($metaPath), true)['created_at'] ?? null)
                : null;
            if (! $created || now()->subHours($hours)->greaterThan($created)) {
                $this->disk()->deleteDirectory($dir);
                $count++;
            }
        }

        return $count;
    }

    // ------------------------------------------------------------------

    private function meta(User $user, string $id, bool $throw = true): ?array
    {
        $path = $this->dir($id).'/meta.json';
        $meta = Str::isUuid($id) && $this->disk()->exists($path) ? json_decode($this->disk()->get($path), true) : null;
        if (! $meta || $meta['user_id'] !== $user->id) {
            if ($throw) {
                abort(404, 'Upload tidak ditemukan atau sudah kedaluwarsa.');
            }

            return null;
        }

        return $meta;
    }

    private function writeMeta(string $id, array $meta): void
    {
        $this->disk()->put($this->dir($id).'/meta.json', json_encode($meta));
    }

    /** Isi file harus sesuai ekstensinya (mis. .pdf benar-benar PDF), bukan hanya namanya. */
    private function verifyContent(string $id, array $meta): void
    {
        $mime = MimeTypes::getDefault()->guessMimeType($this->disk()->path($this->dir($id).'/data')) ?? '';
        $extensions = MimeTypes::getDefault()->getExtensions($mime);
        $ok = in_array($meta['ext'], $extensions, true) || in_array($mime, self::MIME_ALLOW[$meta['ext']] ?? [], true);
        if (! $ok) {
            $this->disk()->deleteDirectory($this->dir($id));
            throw ValidationException::withMessages(['chunk' => "Isi file \"{$meta['name']}\" tidak sesuai dengan jenisnya ({$meta['ext']})."]);
        }
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576).' MB' : round($bytes / 1024).' KB';
    }
}
