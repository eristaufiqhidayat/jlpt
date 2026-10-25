<?php

namespace App\Http\Controllers\Sensei;

use App\Http\Controllers\Controller;
use App\Services\QuestionImporter;
use App\Support\Spreadsheet\XlsxReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use JsonException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class QuestionImportController extends Controller
{
    public function __construct(private readonly QuestionImporter $importer) {}

    public function create(): View
    {
        return view('sensei.questions.import');
    }

    public function template(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $this->importer->writeTemplate($path);

        return response()->download($path, 'template-import-soal.xlsx')->deleteFileAfterSend();
    }

    /** Upload → baca → validasi → simpan hasil sementara → tampilkan pratinjau. */
    public function preview(Request $request): View|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt'],
        ], [], ['file' => 'file Excel']);

        $file = $request->file('file');
        try {
            $rows = XlsxReader::read($file->getRealPath(), $file->getClientOriginalName(), 'Soal');
        } catch (Throwable $e) {
            return back()->with('error', 'File tidak bisa dibaca: '.$e->getMessage().' Simpan ulang sebagai .xlsx lalu coba lagi.');
        }

        $result = $this->importer->parse($rows);
        if ($result['header_error']) {
            return back()->with('error', $result['header_error']);
        }
        if (! $result['rows']) {
            return back()->with('error', 'File tidak berisi baris soal.');
        }

        try {
            $json = json_encode([
                'user_id' => $request->user()->id,
                'filename' => $file->getClientOriginalName(),
                'rows' => $result['rows'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return back()->with('error', 'Isi file mengandung karakter yang tidak bisa dibaca. Simpan ulang sebagai .xlsx atau "CSV UTF-8" lalu coba lagi.');
        }

        $this->cleanupOld();
        $token = (string) Str::uuid();
        Storage::disk('local')->put("imports/{$token}.json", $json);

        return view('sensei.questions.import-preview', $result + [
            'token' => $token,
            'filename' => $file->getClientOriginalName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'uuid'], 'skip_duplicates' => ['nullable', 'boolean']]);
        $path = "imports/{$data['token']}.json";
        $claimed = "imports/{$data['token']}.processing";
        $disk = Storage::disk('local');
        $expired = redirect()->route('sensei.questions.import')
            ->with('error', 'Data import sudah diproses atau kedaluwarsa. Cek daftar soal, atau upload ulang filenya.');

        if (! $disk->exists($path)) {
            return $expired;
        }
        $payload = json_decode((string) $disk->get($path), true);
        if (! is_array($payload) || ! isset($payload['rows'])) {
            $disk->delete($path);

            return $expired;
        }
        abort_unless(($payload['user_id'] ?? null) === $request->user()->id, 403);

        // Klaim token secara atomik: bila tombol Import terkirim dua kali, hanya satu request yang lolos.
        if (! @rename($disk->path($path), $disk->path($claimed))) {
            return $expired;
        }

        try {
            $result = $this->importer->import($payload['rows'], $request->user()->id, $request->boolean('skip_duplicates'));
        } catch (Throwable $e) {
            @rename($disk->path($claimed), $disk->path($path)); // kembalikan supaya bisa dicoba lagi
            report($e);

            return redirect()->route('sensei.questions.import')
                ->with('error', 'Import gagal, tidak ada soal yang disimpan. Coba lagi atau upload ulang filenya.');
        }
        $disk->delete($claimed);

        $message = "{$result['created']} soal dari {$payload['filename']} berhasil diimport.";
        if ($result['skipped']) {
            $byReason = collect($result['skipped'])->groupBy('reason')
                ->map(fn ($rows, $reason) => $reason.' (baris '.$rows->pluck('line')->take(20)->implode(', ')
                    .($rows->count() > 20 ? ', …' : '').')');
            $message .= ' Dilewati: '.$byReason->implode('; ').'.';
        }

        return redirect()->route('sensei.questions.index')->with('success', $message);
    }

    private function cleanupOld(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files('imports') as $f) {
            if ($disk->lastModified($f) < now()->subDay()->getTimestamp()) {
                $disk->delete($f);
            }
        }
    }
}
