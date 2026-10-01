<?php

namespace App\Console\Commands;

use App\Enums\LegacyKeyStatus;
use App\Models\LegacyAnswerKey;
use App\Models\LegacyExam;
use App\Models\LegacyExamFile;
use App\Models\Level;
use App\Models\Section;
use App\Models\TestType;
use App\Support\Legacy\AnswerKeyAnalyzer;
use App\Support\Legacy\SectionMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Memindahkan arsip soal dan kunci jawaban dari database lama (jlpt_jgg).
 *
 *   php artisan legacy:import --dry-run          # cek dulu, tidak ada yang disimpan
 *   php artisan legacy:import                    # impor semua jenis tes
 *   php artisan legacy:import --only=JLPT        # hanya JLPT
 *   php artisan legacy:import --files=/path/pdf  # sekaligus salin file soal
 *
 * Aman dijalankan berulang: data dicocokkan lewat ID lama (legacy_id).
 */
class ImportLegacyData extends Command
{
    protected $signature = 'legacy:import
        {--dry-run : Jalankan pemeriksaan tanpa menyimpan apa pun}
        {--only= : Batasi ke jenis tes tertentu, pisahkan dengan koma (mis. JLPT,JTEST)}
        {--files= : Folder file soal lama (default: LEGACY_PDF_PATH)}
        {--no-files : Jangan salin file soal}
        {--audio= : Folder audio choukai lama (default: LEGACY_AUDIO_PATH), subfolder ikut dicari}';

    protected $description = 'Impor paket ujian, file soal, dan kunci jawaban dari aplikasi lama';

    /** @var array<string, int> */
    private array $stats = [
        'exams' => 0, 'files' => 0, 'files_skipped_test' => 0, 'files_copied' => 0,
        'files_missing' => 0, 'keys' => 0, 'keys_invalid' => 0, 'files_kept' => 0, 'keys_discarded' => 0,
        'audio_found' => 0, 'audio_copied' => 0, 'audio_missing' => 0, 'explanations' => 0,
    ];

    /** @var list<array<int, string|int>> */
    private array $report = [];

    /** @var array<string, Level> */
    private array $levelCache = [];

    /** @var array<string, string>|null nama file audio (huruf kecil) => path lengkap */
    private ?array $audioIndex = null;

    public function handle(): int
    {
        $legacy = DB::connection('legacy');

        try {
            $legacy->getPdo();
        } catch (Throwable $e) {
            $this->error('Tidak bisa terhubung ke database lama: '.$e->getMessage());
            $this->line('Periksa LEGACY_DB_* di file .env.');

            return self::FAILURE;
        }

        $only = collect(explode(',', (string) $this->option('only')))
            ->map(fn ($v) => strtoupper(trim($v)))->filter()->values();

        $filesPath = $this->option('no-files') ? null : ($this->option('files') ?: config('legacy.pdf_path'));
        if ($filesPath && ! is_dir($filesPath)) {
            $this->warn("Folder file soal tidak ditemukan: {$filesPath} — file tidak akan disalin.");
            $filesPath = null;
        }

        $audioPath = $this->option('audio') ?: config('legacy.audio_path');
        if ($audioPath && ! is_dir($audioPath)) {
            $this->warn("Folder audio tidak ditemukan: {$audioPath} — audio tidak akan disalin.");
        } elseif ($audioPath) {
            $this->audioIndex = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($audioPath, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (preg_match('/\.(mp3|m4a|wav|ogg)$/i', $f->getFilename())) {
                    $this->audioIndex[strtolower($f->getFilename())] ??= $f->getPathname();
                }
            }
            $this->info('Audio lama ditemukan di folder: '.count($this->audioIndex).' file');
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN — tidak ada data atau file yang disimpan.');
        }

        $allExams = $legacy->table('m_exam')->orderBy('id')->get();
        // Relasi file → paket selalu dicari dari semua paket, supaya filter --only
        // tidak membuat file jenis tes lain terlihat seperti file tanpa paket.
        $examsByKode = $allExams->groupBy('kode_exam');
        $exams = $only->isEmpty()
            ? $allExams
            : $allExams->filter(fn ($e) => $only->contains(strtoupper(trim($e->jenis_tes))));

        DB::beginTransaction();
        try {
            $this->importExams($exams);
            $this->importFiles($legacy, $examsByKode, $only, $filesPath, $dryRun);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Impor dibatalkan, tidak ada data yang tersimpan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printSummary($dryRun);

        return self::SUCCESS;
    }

    private function importExams(Collection $exams): void
    {
        $this->info("Paket ujian (m_exam): {$exams->count()} baris");

        foreach ($exams as $row) {
            LegacyExam::updateOrCreate(['legacy_id' => $row->id], [
                'kode_exam' => $row->kode_exam,
                'jenis_tes' => $this->clean($row->jenis_tes),
                'kelas' => $this->clean($row->kelas),
                'ulevel' => $this->clean($row->ulevel),
                'nama_tes' => $this->clean($row->nama_tes),
                'access_key' => $this->clean($row->knc) ?: null,
                'status' => $this->clean($row->status),
                'level_id' => $this->findLevel($row->jenis_tes, $row->kelas)?->id,
                'legacy_created_at' => $row->tgl_input,
            ]);
            $this->stats['exams']++;
        }
    }

    private function importFiles($legacy, Collection $examsByKode, Collection $only, ?string $filesPath, bool $dryRun): void
    {
        $files = $legacy->table('detail_soal')->orderBy('id_soal')->get();

        // Kunci hanya milik file bila id_soal DAN kode_exam sama dengan baris detail_soal.
        // Kunci tanpa pasangan (file sudah dihapus / diupload ulang / beda paket) dibuang.
        $validPairs = $files->mapWithKeys(fn ($f) => [$f->id_soal.'|'.trim($f->kode_exam) => true]);
        $allKeys = $legacy->table('jwb_soal')
            ->select('id', 'id_soal', 'kode_exam', 'no_soal', 'jwb', 'flag1', 'textbahasan')
            ->orderBy('id')->get();
        $matched = $allKeys->filter(fn ($k) => isset($validPairs[$k->id_soal.'|'.trim($k->kode_exam)]));
        $this->stats['keys_discarded'] = $allKeys->count() - $matched->count();
        $keysByFile = $matched->groupBy('id_soal');

        $this->info("File soal (detail_soal): {$files->count()} baris. Kunci (jwb_soal): {$allKeys->count()} baris, "
            ."{$matched->count()} cocok dengan file, {$this->stats['keys_discarded']} tanpa pasangan dibuang.");
        $bar = $this->output->createProgressBar($files->count());

        foreach ($files as $file) {
            $bar->advance();

            $relatedExams = $examsByKode->get($file->kode_exam, collect());

            // Paket di m_exam sudah terhapus: tebak jenis tes dari kode_exam (N1–N5 + bagian JLPT → JLPT).
            $guessedType = $relatedExams->isEmpty()
                ? SectionMapper::guessTestTypeFromKodeExam($file->kode_exam, $file->ket_soal)
                : null;
            $jenisTes = $relatedExams->first()->jenis_tes ?? $guessedType;

            if ($only->isNotEmpty() && ! $only->contains(strtoupper(trim((string) $jenisTes)))) {
                continue; // jenis tes lain, atau kode_exam tidak dikenal
            }

            $questionCount = (int) $file->jml_soal;
            $keyRows = $keysByFile->get($file->id_soal, collect())
                ->map(fn ($k) => ['id' => $k->id, 'no_soal' => $k->no_soal, 'jwb' => $k->jwb])->all();

            if (AnswerKeyAnalyzer::isTestData($questionCount, count($keyRows), $file->ket_soal)) {
                $this->stats['files_skipped_test']++;
                $this->addReport($file, 'dilewati', 'Data uji coba (1 soal, tanpa kunci)');
                continue;
            }

            $analysis = AnswerKeyAnalyzer::analyze($questionCount, $keyRows, $file->ket_soal);
            $issues = $analysis['issues'];

            if ($relatedExams->isEmpty()) {
                $issues[] = $guessedType
                    ? "Paket tidak ada di m_exam; diasumsikan {$guessedType} dari kode_exam"
                    : 'kode_exam tidak ditemukan di m_exam';
            }
            $levels = $relatedExams->pluck('kelas')->map(fn ($v) => trim($v))->unique()->values();
            if ($levels->count() > 1) {
                $issues[] = 'Dipakai beberapa level: '.$levels->implode(', ');
            }

            $section = $this->findSection(
                $jenisTes,
                $levels->first() ?? SectionMapper::levelFromKodeExam($file->kode_exam),
                $file->ket_soal,
                (int) $file->waktu,
            );
            if (! $section) {
                $issues[] = 'Jenis tes/level tidak dikenali, file belum terhubung ke bagian';
            }

            [$path, $mime] = $this->copyFile($file, $filesPath, $dryRun, $issues);

            // Kunci yang sudah diperbaiki sensei di panel tidak boleh tertimpa impor ulang.
            $existing = LegacyExamFile::where('legacy_id', $file->id_soal)->first();
            if ($existing && $existing->answerKeys()->whereNotNull('edited_by')->exists()) {
                $existing->update([
                    'file_path' => $path ?? $existing->file_path,
                    'file_mime' => $mime ?? $existing->file_mime,
                    'section_id' => $existing->section_id ?? $section?->id,
                ]);
                // Kunci tetap milik sensei; audio & pembahasan tetap dilengkapi.
                foreach ($keysByFile->get($file->id_soal, collect()) as $raw) {
                    $key = $existing->answerKeys()->where('legacy_id', $raw->id)->first();
                    if (! $key) {
                        continue;
                    }
                    [$audioName, $audioPath] = $this->legacyAudio($raw->flag1, $file->kode_exam, $dryRun);
                    $key->update([
                        'audio_filename' => $key->audio_filename ?? $audioName,
                        'audio_path' => $key->audio_path ?? $audioPath,
                        'explanation' => $key->explanation ?? (trim(strip_tags((string) $raw->textbahasan)) ?: null),
                    ]);
                }
                $this->stats['files']++;
                $this->stats['files_kept']++;

                continue;
            }

            $record = LegacyExamFile::updateOrCreate(['legacy_id' => $file->id_soal], [
                'kode_exam' => $file->kode_exam,
                'section_name' => $this->clean($file->ket_soal),
                'section_id' => $section?->id,
                'stored_filename' => $this->clean($file->data_soal),
                'original_filename' => $this->clean($file->flag3) ?: null,
                'file_path' => $path,
                'file_mime' => $mime,
                'question_count' => $questionCount,
                'duration_minutes' => (int) $file->waktu,
                'key_count' => count($analysis['keys']),
                'valid_key_count' => $analysis['valid_count'],
                'key_status' => $analysis['status'],
                'issues' => $issues ?: null,
                'legacy_created_at' => $file->tglinput,
            ]);

            $record->answerKeys()->delete();
            $extras = $keysByFile->get($file->id_soal, collect())->keyBy('id');
            $rows = [];
            foreach ($analysis['keys'] as $k) {
                $raw = $extras->get($k['legacy_id']);
                [$audioName, $audioPath] = $this->legacyAudio($raw?->flag1, $file->kode_exam, $dryRun);
                $explanation = trim(strip_tags((string) ($raw?->textbahasan ?? ''))) ?: null;
                $explanation && $this->stats['explanations']++;
                $rows[] = $k + [
                    'legacy_exam_file_id' => $record->id,
                    'audio_filename' => $audioName,
                    'audio_path' => $audioPath,
                    'explanation' => $explanation,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                LegacyAnswerKey::insert($chunk);
            }

            $this->stats['files']++;
            $this->stats['keys'] += count($analysis['keys']);
            $this->stats['keys_invalid'] += count(array_filter($analysis['keys'], fn ($k) => ! $k['is_valid']));

            if ($issues) {
                $this->addReport($file, $analysis['status']->label(), implode('; ', $issues));
            }
        }

        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Salin file lama (.html yang isinya PDF) ke storage private dengan ekstensi yang benar.
     *
     * @return array{0: ?string, 1: ?string}
     */
    /**
     * Audio choukai per nomor dari jwb_soal.flag1. Mengembalikan [nama asli, path salinan].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function legacyAudio(?string $flag, string $kodeExam, bool $dryRun): array
    {
        $name = basename(trim((string) $flag));
        if ($name === '' || ! preg_match('/\.(mp3|m4a|wav|ogg)$/i', $name)) {
            return [null, null];
        }
        $this->stats['audio_found']++;

        if ($this->audioIndex === null) {
            return [$name, null]; // folder audio tidak diatur: simpan namanya saja
        }

        // Aplikasi lama menyimpan file dengan akhiran "flag1" (nama kolomnya):
        //   flag1 = 111009N520070107304311.mp3  →  file = 111009N520070107304311flag1.mp3
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $source = null;
        foreach ([$name, "{$stem}flag1.{$ext}"] as $candidate) {
            if ($source = $this->audioIndex[strtolower($candidate)] ?? null) {
                $name = basename($source);
                break;
            }
        }
        if (! $source) {
            $this->stats['audio_missing']++;

            return [$name, null];
        }

        $path = sprintf('%s/%s/audio/%s', config('legacy.directory'), $kodeExam, $name);
        if (! $dryRun && ! Storage::disk(config('legacy.disk'))->exists($path)) {
            $stream = fopen($source, 'rb');
            Storage::disk(config('legacy.disk'))->put($path, $stream);
            is_resource($stream) && fclose($stream);
        }
        $this->stats['audio_copied']++;

        return [$name, $path];
    }

    private function copyFile(object $file, ?string $filesPath, bool $dryRun, array &$issues): array
    {
        if (! $filesPath) {
            return [null, null];
        }

        $source = rtrim($filesPath, '/').'/'.basename((string) $file->data_soal);
        if (! is_file($source)) {
            $this->stats['files_missing']++;
            $issues[] = 'File tidak ditemukan: '.basename($source);

            return [null, null];
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source) ?: 'application/octet-stream';
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'audio/mpeg' => 'mp3',
            default => pathinfo((string) $file->flag3, PATHINFO_EXTENSION) ?: 'bin',
        };
        if ($mime !== 'application/pdf') {
            $issues[] = "Isi file bukan PDF ({$mime})";
        }

        $path = sprintf('%s/%s/%d.%s', config('legacy.directory'), $file->kode_exam, $file->id_soal, $ext);

        if (! $dryRun) {
            $stream = fopen($source, 'rb');
            Storage::disk(config('legacy.disk'))->put($path, $stream);
            is_resource($stream) && fclose($stream);
        }
        $this->stats['files_copied']++;

        return [$path, $mime];
    }

    /**
     * Cari level; bila jenis tes dikenal tetapi levelnya belum ada di master (mis. JTEST, NATTEST),
     * level dibuat otomatis supaya arsip tetap terhubung.
     */
    private function findLevel(?string $jenisTes, ?string $kelas): ?Level
    {
        $type = SectionMapper::testTypeCode($jenisTes);
        $kelas = strtoupper(trim((string) $kelas));
        if (! $type || $kelas === '') {
            return null;
        }

        $testType = TestType::where('code', $type)->first();
        if (! $testType) {
            return null;
        }

        return $this->levelCache["{$type}|{$kelas}"] ??= $testType->levels()->firstOrCreate(
            ['code' => $kelas],
            ['name' => "{$testType->name} {$kelas}", 'sort_order' => 0],
        );
    }

    /**
     * Cari bagian di level tersebut; bila belum ada, dibuat dengan durasi dari file lama.
     */
    private function findSection(?string $jenisTes, ?string $kelas, ?string $ketSoal, int $duration): ?Section
    {
        $level = $this->findLevel($jenisTes, $kelas);
        $name = trim(preg_replace('/\s+/', ' ', (string) $ketSoal));
        if (! $level || $name === '') {
            return null;
        }

        $code = SectionMapper::sectionCode($name) ?? Str::slug($name, '_');

        return $level->sections()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'sort_order' => SectionMapper::sortOrder($code),
                'default_duration_minutes' => max(1, $duration),
            ],
        );
    }

    private function clean(?string $value): string
    {
        return trim((string) $value);
    }

    private function addReport(object $file, string $status, string $note): void
    {
        $this->report[] = [$file->id_soal, $file->kode_exam, trim($file->ket_soal), (int) $file->jml_soal, $status, $note];
    }

    private function printSummary(bool $dryRun): void
    {
        $this->table(['Hasil', 'Jumlah'], [
            ['Paket ujian (legacy_exams)', $this->stats['exams']],
            ['File soal diimpor', $this->stats['files']],
            ['File uji coba dilewati', $this->stats['files_skipped_test']],
            ['File disalin ke storage', $this->stats['files_copied']],
            ['File tidak ditemukan di folder', $this->stats['files_missing']],
            ['Kunci jawaban diimpor', $this->stats['keys']],
            ['Kunci tanpa pasangan id_soal + kode_exam (dibuang)', $this->stats['keys_discarded']],
            ['Kunci tidak valid (perlu dicek sensei)', $this->stats['keys_invalid']],
            ['File dengan kunci hasil perbaikan sensei (tidak ditimpa)', $this->stats['files_kept']],
            ['Audio choukai tercatat (flag1)', $this->stats['audio_found']],
            ['Audio disalin ke storage', $this->stats['audio_copied']],
            ['Audio tidak ditemukan di folder', $this->stats['audio_missing']],
            ['Pembahasan (textbahasan) terisi', $this->stats['explanations']],
        ]);

        $counts = LegacyExamFile::query()
            ->selectRaw('key_status, COUNT(*) as total')->groupBy('key_status')->pluck('total', 'key_status');
        if (! $dryRun && $counts->isNotEmpty()) {
            $this->table(['Status kunci per file', 'Jumlah'], collect(LegacyKeyStatus::cases())
                ->map(fn ($s) => [$s->label(), $counts[$s->value] ?? 0])->all());
        }

        if ($this->report) {
            $csv = "id_soal,kode_exam,bagian,jml_soal,status,catatan\n";
            foreach ($this->report as $row) {
                $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row))."\n";
            }
            $name = 'legacy-import-report-'.now()->format('Ymd-His').'.csv';
            Storage::disk('local')->put($name, $csv);
            $this->info(count($this->report).' file perlu ditinjau → storage/app/private/'.$name);
        }
    }
}
