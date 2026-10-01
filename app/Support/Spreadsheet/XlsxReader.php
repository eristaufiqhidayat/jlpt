<?php

namespace App\Support\Spreadsheet;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Pembaca sheet pertama dari file .xlsx (atau .csv) menjadi array baris.
 * Mendukung shared strings, inline strings, rich text, angka, dan sel kosong di tengah baris.
 */
final class XlsxReader
{
    /** @return list<list<string>> */
    public static function read(string $path, ?string $originalName = null): array
    {
        $ext = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        return $ext === 'csv' ? self::readCsv($path) : self::readXlsx($path);
    }

    /** @return list<list<string>> */
    private static function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // BOM dari Excel
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
    private static function readXlsx(string $path): array
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

        $sheetPath = self::firstSheetPath($zip);
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

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = self::xml($wb);
        $first = $workbook->sheets->sheet[0] ?? null;
        $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        foreach (self::xml($rels)->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
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
