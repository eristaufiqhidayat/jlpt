<?php

namespace App\Support\Legacy;

use App\Enums\LegacyKeyStatus;

/**
 * Memeriksa kunci jawaban satu file soal lama (detail_soal + jwb_soal).
 *
 * Aturan (disepakati dengan tim):
 * - A/B/C/D → 1/2/3/4. Nilai lain (Z, E, F, kosong) adalah bug input aplikasi lama → tidak valid.
 * - Nomor dobel: baris terakhir (id terbesar) yang dipakai, sisanya ditandai tidak valid.
 * - Nomor di luar 1..jumlah soal → tidak valid.
 * - File Interview tidak butuh kunci.
 * - File uji coba (≤ 1 soal, tanpa kunci, bukan Interview) dilewati.
 *
 * Kelas ini tidak bergantung pada Laravel supaya mudah diuji.
 */
final class AnswerKeyAnalyzer
{
    public const OPTION_MAP = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4];

    /** Nama bagian yang selalu dinilai manual, tidak memakai kunci. */
    public const MANUAL_SECTIONS = ['interview'];

    /**
     * Bagian yang soalnya esai (student menulis jawaban, sensei menilai).
     * File tanpa kunci di bagian ini dianggap esai; bila ada kunci A–D, diperiksa seperti biasa.
     */
    public const ESSAY_SECTIONS = ['kanji-furigana', 'furigana-kanji'];

    public static function normalizeKey(?string $raw): ?int
    {
        $key = strtoupper(trim((string) $raw));

        return self::OPTION_MAP[$key] ?? null;
    }

    public static function isManualSection(?string $sectionName): bool
    {
        return in_array(strtolower(trim((string) $sectionName)), self::MANUAL_SECTIONS, true);
    }

    public static function isEssaySection(?string $sectionName): bool
    {
        return in_array(strtolower(trim((string) $sectionName)), self::ESSAY_SECTIONS, true);
    }

    /** Baris detail_soal yang hanya data uji coba dan tidak perlu dimigrasikan. */
    public static function isTestData(int $questionCount, int $keyCount, ?string $sectionName): bool
    {
        return $questionCount <= 1 && $keyCount === 0 && ! self::isManualSection($sectionName);
    }

    /**
     * @param  int  $questionCount  detail_soal.jml_soal
     * @param  array<int, array{id:int, no_soal:int|string, jwb:?string}>  $rows  baris jwb_soal untuk file ini
     * @return array{
     *     status: LegacyKeyStatus,
     *     keys: array<int, array{legacy_id:int, number:int, raw_key:?string, option_label:?int, is_valid:bool}>,
     *     valid_count: int,
     *     issues: list<string>
     * }
     */
    public static function analyze(int $questionCount, array $rows, ?string $sectionName = null): array
    {
        if (self::isManualSection($sectionName)) {
            return ['status' => LegacyKeyStatus::NoKeyNeeded, 'keys' => [], 'valid_count' => 0, 'issues' => []];
        }

        if ($rows === [] && $questionCount > 1 && self::isEssaySection($sectionName)) {
            return ['status' => LegacyKeyStatus::NoKeyNeeded, 'keys' => [], 'valid_count' => 0, 'issues' => []];
        }

        usort($rows, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

        $issues = [];
        $keys = [];
        $latestByNumber = []; // number => index di $keys

        foreach ($rows as $row) {
            $number = (int) $row['no_soal'];
            $raw = $row['jwb'] === null ? null : trim((string) $row['jwb']);
            $label = self::normalizeKey($raw);
            $valid = $label !== null;

            if (! $valid) {
                $shown = $raw === null || $raw === '' ? '(kosong)' : $raw;
                $issues[] = "No. {$number}: kunci \"{$shown}\" tidak valid";
            }

            if ($questionCount > 0 && ($number < 1 || $number > $questionCount)) {
                $issues[] = "No. {$number}: di luar jumlah soal ({$questionCount})";
                $valid = false;
            }

            if (isset($latestByNumber[$number])) {
                $prev = $latestByNumber[$number];
                if ($keys[$prev]['is_valid']) {
                    $issues[] = "No. {$number}: kunci dobel, dipakai yang terakhir diinput";
                }
                $keys[$prev]['is_valid'] = false;
            }

            $keys[] = [
                'legacy_id' => (int) $row['id'],
                'number' => $number,
                'raw_key' => $raw,
                'option_label' => $label,
                'is_valid' => $valid,
            ];
            $latestByNumber[$number] = array_key_last($keys);
        }

        $validNumbers = [];
        foreach ($keys as $k) {
            if ($k['is_valid']) {
                $validNumbers[$k['number']] = true;
            }
        }
        $validCount = count($validNumbers);

        $missing = [];
        for ($n = 1; $n <= $questionCount; $n++) {
            if (! isset($latestByNumber[$n])) {
                $missing[] = $n;
            }
        }
        if ($missing) {
            $issues[] = 'Tanpa kunci: no. '.self::compressRanges($missing);
        }

        $hasInvalid = count(array_filter($keys, fn ($k) => ! $k['is_valid'])) > 0;

        $status = match (true) {
            $hasInvalid => LegacyKeyStatus::InvalidKeys,
            $validCount < $questionCount => LegacyKeyStatus::Incomplete,
            default => LegacyKeyStatus::Ok,
        };

        return ['status' => $status, 'keys' => $keys, 'valid_count' => $validCount, 'issues' => $issues];
    }

    /** [1,2,3,5,7,8] → "1–3, 5, 7–8" */
    public static function compressRanges(array $numbers): string
    {
        sort($numbers);
        $parts = [];
        $start = $prev = null;
        foreach ($numbers as $n) {
            if ($start === null) {
                $start = $prev = $n;
            } elseif ($n === $prev + 1) {
                $prev = $n;
            } else {
                $parts[] = $start === $prev ? "$start" : "{$start}–{$prev}";
                $start = $prev = $n;
            }
        }
        if ($start !== null) {
            $parts[] = $start === $prev ? "$start" : "{$start}–{$prev}";
        }

        return implode(', ', $parts);
    }
}
