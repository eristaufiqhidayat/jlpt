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
     * @return array{rows: list<array>, valid: int, invalid: int, warnings: int, header_error: ?string}
     */
    public function parse(array $rows): array
    {
        $header = array_map(fn ($h) => Str::snake(Str::lower(trim((string) $h))), $rows[0] ?? []);
        $missing = array_diff(['level', 'bagian', 'soal', 'jawaban'], $header);
        if ($missing) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0, 'warnings' => 0,
                'header_error' => 'Kolom wajib tidak ada: '.implode(', ', $missing).'. Gunakan template dari halaman ini.'];
        }

        $out = [];
        foreach (array_slice($rows, 1) as $i => $raw) {
            if (implode('', $raw) === '') {
                continue; // baris kosong
            }
            $data = [];
            foreach (self::COLUMNS as $col) {
                $pos = array_search($col, $header, true);
                $data[$col] = $pos === false ? '' : trim((string) ($raw[$pos] ?? ''));
            }
            $out[] = $this->validateRow($data, $i + 2); // nomor baris seperti di Excel
        }

        $valid = count(array_filter($out, fn ($r) => ! $r['errors']));

        return [
            'rows' => $out,
            'valid' => $valid,
            'invalid' => count($out) - $valid,
            'warnings' => count(array_filter($out, fn ($r) => ! $r['errors'] && $r['warnings'])),
            'header_error' => null,
        ];
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
        }

        $status = match (Str::lower($d['status'])) {
            'draft' => ContentStatus::Draft->value,
            '', 'published', 'siap', 'siap pakai', 'terbit' => ContentStatus::Published->value,
            default => null,
        };
        if (! $status) {
            $errors[] = "Status \"{$d['status']}\" harus siap/draft";
        }

        if (! $errors && $section && $d['soal'] !== ''
            && ($dup = Question::where('section_id', $section->id)->where('stem', $d['soal'])->value('id'))) {
            $warnings[] = "Soal dengan teks sama sudah ada (#{$dup})";
        }

        return [
            'line' => $line,
            'errors' => $errors,
            'warnings' => $warnings,
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
                'legacy_file_id' => $legacyFileId,
                'legacy_number' => $legacyNumber,
                'options' => $options,
                'correct' => $correct,
            ],
        ];
    }

    /** Simpan baris yang valid. @return int jumlah soal dibuat */
    public function import(array $parsedRows, int $userId): int
    {
        $valid = array_filter($parsedRows, fn ($r) => ! $r['errors']);

        return DB::transaction(function () use ($valid, $userId) {
            foreach ($valid as $row) {
                $d = $row['data'];
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
            }

            return count($valid);
        });
    }

    public function writeTemplate(string $path): void
    {
        $rows = [self::COLUMNS];
        $rows[] = ['JLPT', 'N4', 'Moji Goi', 'isian', '問題1', '', '', 'あしたは __雨__ が ふるでしょう。', 'あめ', 'ゆき', 'くも', 'かぜ', 'A', '', '', '', '', 'siap', '', ''];
        $rows[] = ['JLPT', 'N4', 'Bunpou Dokkai', 'bintang', '問題2', '', '', 'これは きのう ＿＿ ＿＿ ★ ＿＿ です。', 'デパート', 'で', 'かった', 'くつ', '3', '3', '1-2-3-4', '', '', 'siap', '', ''];
        $rows[] = ['JLPT', 'N4', 'Bunpou Dokkai', 'isian', '問題1', '', '', '{新聞|しんぶん}（　）読みます。', 'を', 'に', 'が', 'で', '', '', '', 'を = objek', '', 'draft', '1', '1'];
        $rows[] = ['KANJI', 'N5', 'Kanji-Furigana', 'esai', '', '', '', 'Tulis cara baca: __山__', '', '', '', '', '', '', '', '', 'やま', 'siap', '', ''];

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
            ['urutan_benar', 'tidak', 'Soal bintang: urutan benar, mis. 2-4-1-3'],
            ['pembahasan', 'tidak', 'Penjelasan jawaban'],
            ['contoh_jawaban', 'tidak', 'Esai: contoh jawaban benar untuk sensei'],
            ['status', 'tidak', 'siap (default) atau draft'],
            ['arsip_id', 'tidak', 'Nomor arsip lama (#, lihat menu Arsip Lama)'],
            ['no_arsip', 'tidak', 'Nomor soal di PDF arsip lama. Audio choukai & pembahasan dari arsip ikut terpasang otomatis'],
            [],
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
            ->addSheet('Soal', $rows, [10, 7, 16, 9, 9, 9, 9, 45, 14, 14, 14, 14, 9, 9, 11, 25, 18, 8, 9, 9])
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
