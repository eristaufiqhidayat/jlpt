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
            $rows = XlsxReader::read($file->getRealPath(), $file->getClientOriginalName());
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

        $this->cleanupOld();
        $token = (string) Str::uuid();
        Storage::disk('local')->put("imports/{$token}.json", json_encode([
            'user_id' => $request->user()->id,
            'filename' => $file->getClientOriginalName(),
            'rows' => $result['rows'],
        ], JSON_UNESCAPED_UNICODE));

        return view('sensei.questions.import-preview', $result + [
            'token' => $token,
            'filename' => $file->getClientOriginalName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'uuid']]);
        $path = "imports/{$data['token']}.json";
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return redirect()->route('sensei.questions.import')->with('error', 'Data import kedaluwarsa. Upload ulang filenya.');
        }
        $payload = json_decode($disk->get($path), true);
        abort_unless(($payload['user_id'] ?? null) === $request->user()->id, 403);

        $count = $this->importer->import($payload['rows'], $request->user()->id);
        $disk->delete($path);

        return redirect()->route('sensei.questions.index')
            ->with('success', "{$count} soal dari {$payload['filename']} berhasil diimport.");
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
