<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Services\ChunkedUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint upload bertahap untuk file materi (dipakai komponen uploader di form materi).
 * Alur: start → chunk berulang (offset = byte yang sudah diterima) → disimpan bersama form materi.
 */
class UploadController extends Controller
{
    public function __construct(private ChunkedUploads $uploads)
    {
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->uploads->start($request->user(), $data['name'], (int) $data['size']), 201);
    }

    public function chunk(Request $request, string $upload): JsonResponse
    {
        $data = $request->validate([
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        $result = $this->uploads->append($request->user(), $upload, (int) $data['offset'], $request->file('chunk')->getRealPath());

        // 409: posisi tidak cocok (mis. potongan dikirim ulang setelah jeda); klien melanjutkan dari "received".
        return response()->json($result, ($result['conflict'] ?? false) ? 409 : 200);
    }

    public function destroy(Request $request, string $upload): JsonResponse
    {
        $this->uploads->cancel($request->user(), $upload);

        return response()->json(['deleted' => true]);
    }
}
