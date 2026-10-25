<?php

namespace App\Support\Spreadsheet;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Pembaca satu sheet dari file .xlsx (atau .csv) menjadi array baris.
 * Sheet yang dibaca: sheet bernama $sheetName bila ada (mis. "Soal"), selain itu sheet pertama.
 * Mendukung shared strings, inline strings, rich text, angka, dan sel kosong di tengah baris.
 */
final class XlsxReader
{
    /** @return list<list<string>> */
    public static function read(string $path, ?string $originalName = null, ?string $sheetName = null): array
    {
        $ext = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        return $ext === 'csv' ? self::readCsv($path) : self::readXlsx($path, $sheetName);
    }

    /**
     * CSV dari Excel sering tersimpan dalam encoding ANSI (Shift-JIS di Windows Jepang), bukan UTF-8.
     * Shift-JIS dikonversi otomatis; encoding lain ditolak supaya huruf Jepang tidak rusak di database.
     */
    public static function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // BOM dari Excel
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        foreach (['SJIS-win', 'EUC-JP'] as $encoding) {
            if (mb_check_encoding($content, $encoding)) {
                return mb_convert_encoding($content, 'UTF-8', $encoding);
            }
        }

        throw new RuntimeException('Encoding CSV tidak dikenali (bukan UTF-8). Simpan ulang sebagai "CSV UTF-8" atau .xlsx.');
    }

    /** @return list<list<string>> */
    private static function readCsv(string $path): array
    {
        $content = self::toUtf8((string) file_get_contents($path));
        $delimiter = substr_count(strtok($content, "\n"), ';') > substr_count(strtok($content, "\n"), ',') ? ';' : ',';

        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => trim((string) $v), $row);
        }
        fclose($fh);

        return $rows;
    }

    /** @return list<list<string>> */
    private static function readXlsx(string $path, ?string $sheetName = null): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File bukan .xlsx yang valid.');
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (self::xml($xml)->si as $si) {
                $shared[] = self::text($si);
            }
        }

        $sheetPath = self::sheetPath($zip, $sheetName);
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('Sheet tidak ditemukan di file.');
        }

        $rows = [];
        foreach (self::xml($sheetXml)->sheetData->row as $row) {
            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $idx = $ref !== '' ? self::columnIndex($ref) : $next;
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $c->v,
                };
                // Angka bulat dari Excel kadang tersimpan "3.0" / "2.9999999"
                if ($type === '' && is_numeric($value) && abs((float) $value - round((float) $value)) < 1e-9) {
                    $value = (string) (int) round((float) $value);
                }
                $cells[$idx] = trim($value);
                $next = $idx + 1;
            }
            $r = ((int) $row['r'] ?: count($rows) + 1) - 1;
            if ($cells) {
                $line = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) {
                    $line[$i] = $v;
                }
                $rows[$r] = $line;
            }
        }

        if ($rows === []) {
            return [];
        }
        ksort($rows);
        $out = [];
        for ($i = 0, $max = max(array_keys($rows)); $i <= $max; $i++) {
            $out[] = $rows[$i] ?? [];
        }

        return $out;
    }

    /** Path sheet bernama $name (tanpa beda huruf besar/kecil), atau sheet pertama bila tidak ada. */
    private static function sheetPath(ZipArchive $zip, ?string $name = null): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheets = [];
        foreach (self::xml($wb)->sheets->sheet ?? [] as $sheet) {
            $sheets[] = ['name' => (string) $sheet['name'], 'rid' => (string) $sheet->attributes($relNs)['id']];
        }
        if ($sheets === []) {
            return 'xl/worksheets/sheet1.xml';
        }

        $chosen = $sheets[0];
        if ($name !== null) {
            foreach ($sheets as $sheet) {
                if (mb_strtolower(trim($sheet['name'])) === mb_strtolower($name)) {
                    $chosen = $sheet;
                    break;
                }
            }
        }

        foreach (self::xml($rels)->Relationship as $rel) {
            if ((string) $rel['Id'] === $chosen['rid']) {
                $target = ltrim((string) $rel['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('Isi file Excel rusak.');
        }

        return $xml;
    }

    /** Teks dari <si>/<is>: <t> biasa atau rich text <r><t>, tanpa teks furigana bawaan Excel (<rPh>). */
    private static function text(?SimpleXMLElement $node): string
    {
        if (! $node) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r as $r) {
            $s .= (string) $r->t;
        }

        return $s;
    }

    public static function columnIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
