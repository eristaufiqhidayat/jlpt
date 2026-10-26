<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Backup tabel database terpilih (checkbox) ke format SQL atau CSV.
 */
class BackupController extends Controller
{
    /** Jumlah baris per statement INSERT pada backup SQL. */
    private const INSERT_BATCH = 200;

    public function index(): View
    {
        return view('admin.backup.index', [
            'tables' => $this->tables(),
            'database' => DB::connection()->getDatabaseName(),
        ]);
    }

    public function download(Request $request): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        $names = array_column($this->tables(), 'name');

        $data = $request->validate([
            'tables' => ['required', 'array', 'min:1'],
            // Hanya nama tabel yang benar-benar ada yang diterima (aman dari injeksi nama tabel)
            'tables.*' => ['string', Rule::in($names)],
            'format' => ['required', Rule::in(['sql', 'csv'])],
            'sql_structure' => ['nullable', 'boolean'],
            'sql_data' => ['nullable', 'boolean'],
            'sql_drop' => ['nullable', 'boolean'],
            'csv_delimiter' => ['nullable', Rule::in([',', ';', 'tab'])],
            'csv_header' => ['nullable', 'boolean'],
            'csv_bom' => ['nullable', 'boolean'],
        ], [
            'tables.required' => 'Pilih minimal satu tabel.',
            'tables.*.in' => 'Ada tabel yang tidak dikenal.',
        ]);

        // Urutkan sesuai daftar tabel agar hasil backup konsisten
        $selected = array_values(array_intersect($names, $data['tables']));
        $stamp = now()->format('Ymd_His');

        if ($data['format'] === 'sql') {
            $structure = $request->boolean('sql_structure');
            $withData = $request->boolean('sql_data');
            if (! $structure && ! $withData) {
                return back()->withInput()->with('error', 'Untuk SQL, pilih struktur dan/atau data.');
            }

            return $this->sql($selected, $structure, $withData, $request->boolean('sql_drop'), $stamp);
        }

        $delimiter = match ($data['csv_delimiter'] ?? ',') {
            ';' => ';',
            'tab' => "\t",
            default => ',',
        };

        return $this->csv($selected, $delimiter, $request->boolean('csv_header'), $request->boolean('csv_bom'), $stamp);
    }

    // ---------------------------------------------------------------- SQL

    private function sql(array $tables, bool $structure, bool $withData, bool $drop, string $stamp): StreamedResponse
    {
        $db = DB::connection();
        $name = count($tables) === 1 ? $tables[0] : $db->getDatabaseName();
        $filename = 'backup_'.basename((string) $name).'_'.$stamp.'.sql';

        return response()->streamDownload(function () use ($db, $tables, $structure, $withData, $drop) {
            $isMysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);

            echo '-- Backup database '.$db->getDatabaseName()."\n";
            echo '-- Dibuat : '.now()->format('Y-m-d H:i:s')."\n";
            echo '-- Driver : '.$db->getDriverName()."\n";
            echo '-- Tabel  : '.implode(', ', $tables)."\n\n";
            echo $isMysql ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n" : "PRAGMA foreign_keys = OFF;\n\n";

            foreach ($tables as $table) {
                $q = $this->quoteIdent($db, $table);
                echo "-- ----------------------------------------\n-- Tabel {$q}\n-- ----------------------------------------\n";

                if ($structure) {
                    if ($drop) {
                        echo "DROP TABLE IF EXISTS {$q};\n";
                    }
                    echo $this->createStatement($db, $table).";\n\n";
                }

                if ($withData) {
                    $this->dumpInserts($db, $table);
                    echo "\n";
                }

                flush();
            }

            echo $isMysql ? "SET FOREIGN_KEY_CHECKS = 1;\n" : "PRAGMA foreign_keys = ON;\n";
        }, $filename, ['Content-Type' => 'application/sql; charset=UTF-8']);
    }

    private function createStatement(Connection $db, string $table): string
    {
        return match ($db->getDriverName()) {
            // Kolom kedua hasil SHOW CREATE TABLE berisi statement CREATE TABLE
            'mysql', 'mariadb' => (string) array_values((array) $db->selectOne('SHOW CREATE TABLE '.$this->quoteIdent($db, $table)))[1],
            'sqlite' => (string) $db->table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql'),
            default => abort(422, 'Backup struktur SQL hanya didukung untuk MySQL/MariaDB dan SQLite.'),
        };
    }

    private function dumpInserts(Connection $db, string $table): void
    {
        $pdo = $db->getPdo();
        $q = $this->quoteIdent($db, $table);
        $columns = null;
        $batch = [];

        $flush = function () use (&$batch, &$columns, $q) {
            if ($batch) {
                echo "INSERT INTO {$q} {$columns} VALUES\n".implode(",\n", $batch).";\n";
                $batch = [];
            }
        };

        foreach ($db->table($table)->cursor() as $row) {
            $row = (array) $row;
            $columns ??= '('.implode(', ', array_map(fn ($c) => $this->quoteIdent($db, $c), array_keys($row))).')';
            $batch[] = '('.implode(', ', array_map(fn ($v) => match (true) {
                $v === null => 'NULL',
                is_bool($v) => $v ? '1' : '0',
                is_int($v), is_float($v) => (string) $v,
                default => $pdo->quote((string) $v),
            }, $row)).')';

            if (count($batch) >= self::INSERT_BATCH) {
                $flush();
            }
        }
        $flush();
    }

    // ---------------------------------------------------------------- CSV

    private function csv(array $tables, string $delimiter, bool $header, bool $bom, string $stamp): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        // Satu tabel: langsung file CSV
        if (count($tables) === 1) {
            $table = $tables[0];

            return response()->streamDownload(function () use ($table, $delimiter, $header, $bom) {
                $out = fopen('php://output', 'w');
                $this->writeCsv($table, $out, $delimiter, $header, $bom);
                fclose($out);
            }, $table.'_'.$stamp.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Banyak tabel: ZIP berisi satu CSV per tabel
        if (! class_exists(ZipArchive::class)) {
            return back()->withInput()->with('error', 'Ekstensi PHP zip belum aktif, tidak bisa membuat ZIP.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'bkp');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        $temps = [];
        foreach ($tables as $table) {
            $tmp = tempnam(sys_get_temp_dir(), 'csv');
            $fh = fopen($tmp, 'w');
            $this->writeCsv($table, $fh, $delimiter, $header, $bom);
            fclose($fh);
            $zip->addFile($tmp, $table.'.csv');
            $temps[] = $tmp;
        }
        $zip->close();
        array_map('unlink', $temps);

        $database = basename((string) DB::connection()->getDatabaseName());

        return response()->download($zipPath, 'backup_csv_'.$database.'_'.$stamp.'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /** @param resource $fh */
    private function writeCsv(string $table, $fh, string $delimiter, bool $header, bool $bom): void
    {
        if ($bom) {
            fwrite($fh, "\xEF\xBB\xBF");
        }
        // Header dari skema, agar tabel kosong tetap punya baris nama kolom
        if ($header) {
            fputcsv($fh, Schema::getColumnListing($table), $delimiter, '"', '\\');
        }
        foreach (DB::table($table)->cursor() as $row) {
            fputcsv($fh, array_values((array) $row), $delimiter, '"', '\\');
        }
    }

    // ---------------------------------------------------------------- Util

    /** Daftar tabel di koneksi default: nama, jumlah baris (estimasi), ukuran. */
    private function tables(): array
    {
        $db = DB::connection();
        // Sejak Laravel 12 getTables() mengembalikan semua schema, jadi batasi ke database aktif
        $schema = match ($db->getDriverName()) {
            'mysql', 'mariadb' => $db->getDatabaseName(),
            'sqlite' => 'main',
            default => null,
        };

        return collect(Schema::getTables($schema))
            ->reject(fn ($t) => str_starts_with($t['name'], 'sqlite_'))
            ->map(fn ($t) => ['name' => $t['name'], 'size' => $t['size'] ?? null])
            ->sortBy('name')->values()->all();
    }

    private function quoteIdent(Connection $db, string $name): string
    {
        return in_array($db->getDriverName(), ['mysql', 'mariadb'], true)
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
    }
}
