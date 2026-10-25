<?php

namespace App\Services;

use App\Enums\ContentStatus;
use App\Enums\QuestionType;
use App\Models\LegacyExamFile;
use App\Models\Passage;
use App\Models\Question;
use App\Models\Section;
use App\Models\TestType;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import soal massal dari Excel/CSV.
 *
 * Alur: parse() → pratinjau per baris (error/peringatan) → import() hanya baris yang valid.
 * Kunci jawaban boleh dikosongkan bila kolom arsip_id + no_arsip diisi: kunci diambil dari arsip lama.
 */
class QuestionImporter
{
    public const COLUMNS = [
        'jenis_tes', 'level', 'bagian', 'jenis', 'mondai', 'bacaan_id', 'urutan_bacaan', 'soal',
        'pilihan_1', 'pilihan_2', 'pilihan_3', 'pilihan_4', 'jawaban',
        'posisi_bintang', 'urutan_benar', 'pembahasan', 'contoh_jawaban', 'status', 'arsip_id', 'no_arsip',
        'penggunaan',
    ];

    private const USAGE_ALIASES = [
        '' => 'practice', 'latihan' => 'practice', 'practice' => 'practice',
        'tes' => 'test', 'test' => 'test', 'tes_online' => 'test', 'ujian' => 'test',
        'keduanya' => 'both', 'both' => 'both', 'semua' => 'both',
    ];

    private const TYPE_ALIASES = [
        'blank' => 'blank', 'isian' => 'blank', 'kosong' => 'blank',
        'star' => 'star', 'bintang' => 'star', '★' => 'star', 'susun' => 'star',
        'reading' => 'reading', 'bacaan' => 'reading', 'dokkai' => 'reading',
        'listening' => 'listening', 'choukai' => 'listening', 'audio' => 'listening',
        'essay' => 'essay', 'esai' => 'essay', 'essai' => 'essay',
    ];

    private const SECTION_ALIASES = [
        'moji goi' => 'moji_goi', 'mojigoi' => 'moji_goi', '文字・語彙' => 'moji_goi', '文字語彙' => 'moji_goi', 'goi' => 'moji_goi',
        'bunpou dokkai' => 'bunpou_dokkai', 'bunpou' => 'bunpou_dokkai', 'dokkai' => 'bunpou_dokkai', '文法・読解' => 'bunpou_dokkai',
        'choukai' => 'choukai', '聴解' => 'choukai',
        'kanji-furigana' => 'kanji_furigana', 'furigana-kanji' => 'furigana_kanji',
    ];

    /** @var array<string, ?Section> */
    private array $sectionCache = [];

    /**
     * @param  list<list<string>>  $rows  baris mentah dari file, baris pertama = judul kolom
     * @return array{rows: list<array>, valid: int, invalid: int, warnings: int, duplicates: int, header_error: ?string, header_warnings: list<string>}
     */
    public function parse(array $rows): array
    {
        $rawHeader = $rows[0] ?? [];
        $header = array_map(fn ($h) => self::normalizeHeader((string) $h), $rawHeader);
        $missing = array_diff(['level', 'bagian', 'soal', 'jawaban'], $header);
        if ($missing) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0, 'warnings' => 0, 'duplicates' => 0, 'header_warnings' => [],
                'header_error' => 'Kolom wajib tidak ada: '.implode(', ', $missing).'. Gunakan template dari halaman ini.'];
        }

        // Kolom yang tidak dikenal dilaporkan, supaya salah ketik judul kolom tidak diam-diam diabaikan.
        $headerWarnings = [];
        foreach ($header as $i => $col) {
            $label = trim((string) $rawHeader[$i]);
            if ($col !== '' && ! in_array($col, self::COLUMNS, true)) {
                $headerWarnings[] = "Kolom \"{$label}\" tidak dikenal dan diabaikan";
            } elseif ($col !== '' && array_search($col, $header, true) !== $i) {
                $headerWarnings[] = "Kolom \"{$label}\" muncul dua kali; hanya yang pertama dipakai";
            }
        }

        $out = [];
        $seen = [];
        foreach (array_slice($rows, 1) as $i => $raw) {
            if (implode('', $raw) === '') {
                continue; // baris kosong
            }
            $data = [];
            foreach (self::COLUMNS as $col) {
                $pos = array_search($col, $header, true);
                $data[$col] = $pos === false ? '' : trim((string) ($raw[$pos] ?? ''));
            }
            $row = $this->validateRow($data, $i + 2); // nomor baris seperti di Excel

            // Soal yang sama persis di dalam file yang sama.
            if (! $row['errors']) {
                $key = self::duplicateKey($row['data']);
                if (isset($seen[$key])) {
                    $row['warnings'][] = "Sama dengan baris {$seen[$key]} di file ini";
                    $row['duplicate'] = true;
                } else {
                    $seen[$key] = $row['line'];
                }
            }
            $out[] = $row;
        }

        $valid = count(array_filter($out, fn ($r) => ! $r['errors']));

        return [
            'rows' => $out,
            'valid' => $valid,
            'invalid' => count($out) - $valid,
            'warnings' => count(array_filter($out, fn ($r) => ! $r['errors'] && $r['warnings'])),
            'duplicates' => count(array_filter($out, fn ($r) => ! $r['errors'] && $r['duplicate'])),
            'header_error' => null,
            'header_warnings' => $headerWarnings,
        ];
    }

    /**
     * "Pilihan 1", "pilihan-1", "PILIHAN_1", "pilihan1" → pilihan_1; "No. Arsip" → no_arsip; "soal*" → soal.
     */
    public static function normalizeHeader(string $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        $h = mb_strtolower(trim($h));
        $h = trim(preg_replace('/[^\p{L}\p{N}]+/u', '_', $h), '_');
        $h = preg_replace('/^pilihan_?([1-4])$/', 'pilihan_$1', $h);

        return self::HEADER_ALIASES[$h] ?? $h;
    }

    private const HEADER_ALIASES = [
        'kunci' => 'jawaban', 'kunci_jawaban' => 'jawaban',
        'tipe' => 'jenis', 'tipe_soal' => 'jenis', 'jenis_soal' => 'jenis',
        'id_bacaan' => 'bacaan_id', 'nomor_arsip' => 'no_arsip', 'id_arsip' => 'arsip_id',
        'dipakai_untuk' => 'penggunaan', 'pemakaian' => 'penggunaan', 'usage' => 'penggunaan',
    ];

    private static function duplicateKey(array $d): string
    {
        return json_encode([$d['section_id'], $d['type'], $d['passage_id'], $d['number_in_passage'], $d['stem'], $d['options']],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function validateRow(array $d, int $line): array
    {
        $errors = [];
        $warnings = [];

        $section = $this->findSection($d['jenis_tes'] ?: 'JLPT', $d['level'], $d['bagian']);
        if (! $section) {
            $errors[] = "Bagian \"{$d['bagian']}\" untuk level \"{$d['level']}\" tidak ditemukan";
        }

        $type = self::TYPE_ALIASES[Str::lower($d['jenis'] ?: 'isian')] ?? null;
        if (! $type) {
            $errors[] = "Jenis \"{$d['jenis']}\" tidak dikenal (isian/bintang/bacaan/choukai/esai)";
        }

        $passageId = null;
        if ($d['bacaan_id'] !== '') {
            $passage = ctype_digit($d['bacaan_id']) ? Passage::find((int) $d['bacaan_id']) : null;
            if (! $passage) {
                $errors[] = "Bacaan #{$d['bacaan_id']} tidak ditemukan";
            } elseif ($section && $passage->section_id !== $section->id) {
                $warnings[] = "Bacaan #{$passage->id} berasal dari bagian lain";
            }
            $passageId = $passage?->id;
        }

        if ($d['soal'] === '' && ! $passageId && $type !== 'listening') {
            $errors[] = 'Teks soal kosong';
        }

        $options = [];
        foreach (range(1, 4) as $n) {
            if ($d["pilihan_$n"] !== '') {
                $options[$n] = $d["pilihan_$n"];
            }
        }

        // Kunci: dari kolom jawaban, atau dari arsip lama.
        $correct = null;
        $legacyFileId = null;
        $legacyNumber = null;
        if ($d['arsip_id'] !== '') {
            $legacy = ctype_digit($d['arsip_id']) ? LegacyExamFile::where('legacy_id', (int) $d['arsip_id'])->first() : null;
            if (! $legacy) {
                $errors[] = "Arsip #{$d['arsip_id']} tidak ditemukan";
            } else {
                $legacyFileId = $legacy->id;
                $legacyNumber = ctype_digit($d['no_arsip']) ? (int) $d['no_arsip'] : null;
                if (! $legacyNumber) {
                    $errors[] = 'Isi no_arsip (nomor soal di PDF lama)';
                }
            }
        }

        // Audio choukai & pembahasan dari arsip lama (jwb_soal.flag1 / textbahasan).
        $legacyAudio = null;
        $legacyExplanation = null;
        if ($legacyFileId && $legacyNumber) {
            $archived = $legacy->answerKeys()->where('number', $legacyNumber)->latest('id')->first();
            $legacyAudio = $archived?->audio_path;
            $legacyExplanation = $archived?->explanation;
            if ($legacyAudio) {
                $warnings[] = "Audio dari arsip ikut dipasang ({$archived->audio_filename})";
            } elseif ($archived?->audio_filename) {
                $warnings[] = "Audio arsip {$archived->audio_filename} tidak ada di server; tambahkan manual";
            }
        }

        if ($type === 'essay') {
            $options = [];
        } else {
            $correct = $this->parseKey($d['jawaban']);
            if ($correct === null && $d['jawaban'] === '' && $legacyFileId && $legacyNumber) {
                $correct = $legacy->answerKeys()->where('number', $legacyNumber)->where('is_valid', true)->latest('id')->value('option_label');
                $correct
                    ? $warnings[] = "Kunci {$correct} diambil dari arsip #{$d['arsip_id']} no. {$legacyNumber}"
                    : $errors[] = "Kunci no. {$legacyNumber} di arsip #{$d['arsip_id']} kosong/tidak valid — isi kolom jawaban";
            } elseif ($correct === null) {
                $errors[] = $d['jawaban'] === '' ? 'Jawaban kosong' : "Jawaban \"{$d['jawaban']}\" harus 1–4 atau A–D";
            }

            if ($type === 'listening' && ! $options) {
                $options = [1 => '1', 2 => '2', 3 => '3', 4 => '4']; // pilihan hanya diucapkan di audio
            }
            if (count($options) < 2 && $type !== 'listening') {
                $errors[] = 'Isi minimal 2 pilihan';
            }
            if ($correct !== null && ! isset($options[$correct])) {
                $errors[] = "Jawaban {$correct} menunjuk pilihan yang kosong";
            }
        }

        if ($type === 'star' && $d['posisi_bintang'] !== '' && ! in_array($d['posisi_bintang'], ['1', '2', '3', '4'], true)) {
            $errors[] = 'posisi_bintang harus 1–4';
        }
        if ($d['urutan_benar'] !== '' && ! preg_match('/^[1-4](-[1-4]){3}$/', $d['urutan_benar'])) {
            $errors[] = 'urutan_benar ditulis seperti 2-4-1-3';
        } elseif ($d['urutan_benar'] !== '' && count(array_unique(explode('-', $d['urutan_benar']))) !== 4) {
            $errors[] = 'urutan_benar harus memakai 1, 2, 3, 4 masing-masing sekali';
        } elseif ($type === 'star' && $correct !== null && $d['urutan_benar'] !== ''
            && in_array($d['posisi_bintang'], ['1', '2', '3', '4'], true)) {
            // Jawaban soal ★ = pilihan yang berada di posisi ★ pada urutan benar.
            $expected = (int) explode('-', $d['urutan_benar'])[(int) $d['posisi_bintang'] - 1];
            if ($expected !== $correct) {
                $errors[] = "Jawaban {$correct} tidak cocok: dengan urutan {$d['urutan_benar']} dan ★ di posisi {$d['posisi_bintang']}, jawabannya {$expected}";
            }
        }

        $status = match (Str::lower($d['status'])) {
            'draft' => ContentStatus::Draft->value,
            '', 'published', 'siap', 'siap pakai', 'terbit' => ContentStatus::Published->value,
            default => null,
        };
        if (! $status) {
            $errors[] = "Status \"{$d['status']}\" harus siap/draft";
        }

        $usageKey = trim(preg_replace('/[\s\-]+/', '_', Str::lower($d['penggunaan'])), '_');
        $usage = self::USAGE_ALIASES[$usageKey] ?? null;
        if (! $usage) {
            $errors[] = "Penggunaan \"{$d['penggunaan']}\" harus latihan/tes/keduanya";
        }

        $duplicate = false;
        if (! $errors && $section && $d['soal'] !== ''
            && ($dup = $this->existingQuestionId($section->id, $passageId, $d['soal']))) {
            $warnings[] = "Soal dengan teks sama sudah ada (#{$dup})";
            $duplicate = true;
        }

        return [
            'line' => $line,
            'errors' => $errors,
            'warnings' => $warnings,
            'duplicate' => $duplicate,
            'section_label' => $section ? "{$section->level->testType->code} {$section->level->code} · {$section->name}" : null,
            'data' => [
                'section_id' => $section?->id,
                'type' => $type,
                'mondai' => $d['mondai'] ?: null,
                'passage_id' => $passageId,
                'number_in_passage' => ctype_digit($d['urutan_bacaan']) ? (int) $d['urutan_bacaan'] : null,
                'stem' => $d['soal'] ?: null,
                'star_position' => ctype_digit($d['posisi_bintang']) ? (int) $d['posisi_bintang'] : null,
                'correct_order' => $d['urutan_benar'] ?: null,
                'explanation' => $d['pembahasan'] ?: $legacyExplanation,
                'legacy_audio_path' => $legacyAudio,
                'answer_reference' => $d['contoh_jawaban'] ?: null,
                'status' => $status,
                'usage' => $usage,
                'legacy_file_id' => $legacyFileId,
                'legacy_number' => $legacyNumber,
                'options' => $options,
                'correct' => $correct,
            ],
        ];
    }

    /** Soal dengan teks sama di bagian (dan bacaan) yang sama. */
    private function existingQuestionId(int $sectionId, ?int $passageId, string $stem): ?int
    {
        return Question::where('section_id', $sectionId)
            ->when($passageId, fn ($q) => $q->where('passage_id', $passageId), fn ($q) => $q->whereNull('passage_id'))
            ->where('stem', $stem)
            ->value('id');
    }

    /**
     * Simpan baris yang valid. Data acuan (bagian, bacaan, arsip) dicek ulang karena bisa berubah
     * sejak pratinjau; baris yang acuannya hilang dilewati, bukan menggagalkan seluruh import.
     *
     * @return array{created: int, skipped: list<array{line: int, reason: string}>}
     */
    public function import(array $parsedRows, int $userId, bool $skipDuplicates = true): array
    {
        $valid = array_filter($parsedRows, fn ($r) => ! $r['errors']);

        return DB::transaction(function () use ($valid, $userId, $skipDuplicates) {
            $created = 0;
            $skipped = [];
            $seen = [];
            foreach ($valid as $row) {
                $d = $row['data'];

                if ($reason = $this->missingReference($d)) {
                    $skipped[] = ['line' => $row['line'], 'reason' => $reason];

                    continue;
                }
                if ($skipDuplicates) {
                    $key = self::duplicateKey($d);
                    $dup = isset($seen[$key])
                        || ($d['stem'] !== null && $this->existingQuestionId($d['section_id'], $d['passage_id'], $d['stem']));
                    $seen[$key] = true;
                    if ($dup) {
                        $skipped[] = ['line' => $row['line'], 'reason' => 'soal sudah ada'];

                        continue;
                    }
                }

                $question = Question::create([
                    'section_id' => $d['section_id'],
                    'passage_id' => $d['passage_id'],
                    'number_in_passage' => $d['number_in_passage'],
                    'created_by' => $userId,
                    'type' => $d['type'],
                    'mondai' => $d['mondai'],
                    'stem' => $d['stem'],
                    'star_position' => $d['star_position'],
                    'correct_order' => $d['correct_order'],
                    'explanation' => $d['explanation'],
                    'answer_reference' => $d['answer_reference'],
                    'status' => $d['status'],
                    'usage' => $d['usage'] ?? 'practice',
                    'legacy_file_id' => $d['legacy_file_id'],
                    'legacy_number' => $d['legacy_number'],
                ]);
                if (! empty($d['legacy_audio_path']) && Storage::disk('local')->exists($d['legacy_audio_path'])) {
                    $target = "questions/{$question->id}/".basename($d['legacy_audio_path']);
                    Storage::disk('local')->copy($d['legacy_audio_path'], $target);
                    $question->update(['audio_path' => $target]);
                }
                foreach ($d['options'] as $label => $content) {
                    $question->options()->create([
                        'label' => (int) $label,
                        'content' => $content,
                        'is_correct' => (int) $label === (int) $d['correct'],
                    ]);
                }
                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    private function missingReference(array $d): ?string
    {
        if (! $d['section_id'] || ! Section::whereKey($d['section_id'])->exists()) {
            return 'bagian sudah tidak ada';
        }
        if ($d['passage_id'] && ! Passage::whereKey($d['passage_id'])->exists()) {
            return "bacaan #{$d['passage_id']} sudah dihapus";
        }
        if ($d['legacy_file_id'] && ! LegacyExamFile::whereKey($d['legacy_file_id'])->exists()) {
            return 'arsip lama sudah tidak ada';
        }

        return null;
    }

    public function writeTemplate(string $path): void
    {
        $rows = [self::COLUMNS];
        $rows[] = ['JLPT', 'N4', 'Moji Goi', 'isian', '問題1', '', '', 'あしたは __雨__ が ふるでしょう。', 'あめ', 'ゆき', 'くも', 'かぜ', 'A', '', '', '', '', 'siap', '', '', 'latihan'];
        $rows[] = ['JLPT', 'N4', 'Bunpou Dokkai', 'bintang', '問題2', '', '', 'これは きのう ＿＿ ＿＿ ★ ＿＿ です。', 'デパート', 'で', 'かった', 'くつ', '3', '3', '1-2-3-4', '', '', 'siap', '', '', 'tes'];
        $rows[] = ['JLPT', 'N4', 'Bunpou Dokkai', 'isian', '問題1', '', '', '{新聞|しんぶん}（　）読みます。', 'を', 'に', 'が', 'で', '', '', '', 'を = objek', '', 'draft', '1', '1', 'keduanya'];
        $rows[] = ['KANJI', 'N5', 'Kanji-Furigana', 'esai', '', '', '', 'Tulis cara baca: __山__', '', '', '', '', '', '', '', '', 'やま', 'siap', '', '', ''];

        $guide = [
            ['Kolom', 'Wajib', 'Keterangan'],
            ['jenis_tes', 'tidak', 'Default JLPT. Lainnya: KANJI, JTEST, NATTEST, ...'],
            ['level', 'ya', 'N5, N4, N3, N2, N1 (atau level lain yang ada di sistem)'],
            ['bagian', 'ya', 'Moji Goi / Bunpou Dokkai / Choukai (lihat sheet Bagian)'],
            ['jenis', 'tidak', 'isian (default), bintang, bacaan, choukai, esai'],
            ['mondai', 'tidak', 'Label kelompok, mis. 問題1'],
            ['bacaan_id', 'tidak', 'Nomor bacaan (#) dari menu Bacaan & audio, untuk soal yang berbagi satu bacaan/audio'],
            ['urutan_bacaan', 'tidak', 'Urutan soal di dalam bacaan'],
            ['soal', 'ya*', 'Teks soal. Furigana {漢字|かんじ}, garis bawah __kata__. *Boleh kosong untuk choukai/bacaan'],
            ['pilihan_1..4', 'ya', 'Minimal 2 pilihan. Choukai boleh kosong (otomatis 1–4)'],
            ['jawaban', 'ya*', '1–4 atau A–D. *Boleh kosong bila arsip_id + no_arsip diisi (kunci diambil dari arsip lama)'],
            ['posisi_bintang', 'tidak', 'Soal bintang: posisi ★ (1–4)'],
            ['urutan_benar', 'tidak', 'Soal bintang: urutan benar, mis. 2-4-1-3. Bila diisi bersama posisi_bintang, jawaban harus = angka di posisi ★'],
            ['pembahasan', 'tidak', 'Penjelasan jawaban'],
            ['contoh_jawaban', 'tidak', 'Esai: contoh jawaban benar untuk sensei'],
            ['status', 'tidak', 'siap (default) atau draft'],
            ['arsip_id', 'tidak', 'Nomor arsip lama (#, lihat menu Arsip Lama)'],
            ['no_arsip', 'tidak', 'Nomor soal di PDF arsip lama. Audio choukai & pembahasan dari arsip ikut terpasang otomatis'],
            ['penggunaan', 'tidak', 'latihan (default), tes, atau keduanya. Soal "tes" hanya dipakai ujian bersumber soal tes online; kunci & pembahasannya tidak ditampilkan ke peserta'],
            [],
            ['Judul kolom tidak peka huruf besar/kecil; spasi atau tanda hubung boleh (mis. "Pilihan 1" = pilihan_1).'],
            ['Program membaca sheet bernama "Soal"; bila tidak ada, sheet pertama.'],
            ['CSV: simpan sebagai "CSV UTF-8" (Shift-JIS juga dikenali). Lebih aman pakai .xlsx.'],
            ['Gambar dan audio ditambahkan lewat halaman edit soal setelah import.'],
        ];

        $sections = [['jenis_tes', 'level', 'bagian', 'kode']];
        foreach (TestType::with('levels.sections')->orderBy('sort_order')->get() as $t) {
            foreach ($t->levels as $l) {
                foreach ($l->sections as $s) {
                    $sections[] = [$t->code, $l->code, $s->name, $s->code];
                }
            }
        }

        (new XlsxWriter)
            ->addSheet('Soal', $rows, [10, 7, 16, 9, 9, 9, 9, 45, 14, 14, 14, 14, 9, 9, 11, 25, 18, 8, 9, 9, 11])
            ->addSheet('Petunjuk', $guide, [16, 8, 95])
            ->addSheet('Bagian', $sections, [16, 8, 28, 18])
            ->save($path);
    }

    private function parseKey(string $v): ?int
    {
        $v = Str::upper(trim($v));

        return ['1' => 1, '2' => 2, '3' => 3, '4' => 4, 'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4][$v] ?? null;
    }

    private function findSection(string $testType, string $level, string $bagian): ?Section
    {
        $key = Str::upper($testType).'|'.Str::upper($level).'|'.Str::lower($bagian);
        if (array_key_exists($key, $this->sectionCache)) {
            return $this->sectionCache[$key];
        }

        $name = Str::lower(trim(preg_replace('/\s+/', ' ', $bagian)));
        $code = self::SECTION_ALIASES[$name] ?? Str::slug($name, '_');

        return $this->sectionCache[$key] = Section::with('level.testType')
            ->whereHas('level', fn ($q) => $q->where('code', Str::upper(trim($level)))
                ->whereHas('testType', fn ($t) => $t->where('code', Str::upper(trim($testType)))))
            ->where(fn ($q) => $q->where('code', $code)->orWhereRaw('LOWER(name) = ?', [$name])
                ->orWhereRaw('LOWER(name) LIKE ?', [$name.' (%']))
            ->first();
    }
}
