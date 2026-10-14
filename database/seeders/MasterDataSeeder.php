<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\TestType;
use Illuminate\Database\Seeder;

/**
 * Data master awal: jenis tes, level, bagian, dan klasifikasi kelas (kurikulum).
 *
 * Isi mengikuti notulen rapat JGG Learning System (klasifikasi kelas & ujian, skor maksimal per jenis tes).
 * Aman dijalankan berulang: data yang sudah ada TIDAK ditimpa, jadi perubahan yang dibuat Admin Pendidikan
 * lewat menu Data Master (nama, durasi, status aktif, skor) tetap terjaga.
 *
 * Durasi JLPT mengikuti jadwal resmi (N3–N5). Untuk N1 dan N2, JLPT resmi menggabungkan
 * 文字・語彙・文法・読解 dalam satu sesi (N1 110 menit, N2 105 menit); di sini dipecah
 * menjadi dua bagian agar sama dengan struktur aplikasi lama. Semua durasi hanya nilai
 * awal — sensei bisa mengubahnya per ujian.
 *
 * Bagian (section) untuk jenis tes selain JLPT belum diisi karena formatnya belum ditetapkan;
 * Admin Pendidikan menambahkannya dari menu Data Master.
 */
class MasterDataSeeder extends Seeder
{
    /** Sumber angka skor maksimal. */
    private const SCORE_SOURCE = 'Notulen rapat JGG Learning System';

    public function run(): void
    {
        $this->testTypes();
        $this->jlpt();
        $this->otherLevels();
        $this->curricula();
    }

    private function testTypes(): void
    {
        // [kode, nama, urutan, aktif saat pertama dibuat, skor maksimal]
        $types = [
            ['JLPT', 'JLPT', 1, true, 180],
            ['JTEST', 'J.TEST', 2, false, 350],
            ['NATTEST', 'NAT-TEST', 3, false, 180],
            ['JFT', 'JFT-Basic', 4, false, 250],
            ['SSW', 'SSW (Tokutei Ginou)', 5, false, null],
            ['KANJI', 'Kanji', 6, false, null],
            ['PROMETRIC', 'Prometric', 7, false, null],
            ['PROMETRICKANJI', 'Prometric Kanji', 8, false, null],
            ['INTERVIEW', 'Interview', 9, false, null],
        ];

        foreach ($types as [$code, $name, $order, $active, $max]) {
            $type = TestType::firstOrCreate(['code' => $code], [
                'name' => $name,
                'sort_order' => $order,
                'is_active' => $active,
            ]);

            // Skor maksimal hanya diisi bila masih kosong, supaya angka yang sudah diubah Admin Pendidikan tidak tertimpa.
            if ($max !== null && $type->max_score === null) {
                $type->update(['max_score' => $max, 'score_approved_by' => self::SCORE_SOURCE]);
            }
        }
    }

    private function jlpt(): void
    {
        // [level, urutan, [menit Moji Goi, Bunpou Dokkai, Choukai]]
        $jlpt = [
            ['N5', 1, [20, 40, 30]],
            ['N4', 2, [25, 55, 35]],
            ['N3', 3, [30, 70, 40]],
            ['N2', 4, [35, 70, 50]],
            ['N1', 5, [40, 70, 55]],
        ];

        $sections = [
            ['moji_goi', 'Moji Goi (文字・語彙)'],
            ['bunpou_dokkai', 'Bunpou Dokkai (文法・読解)'],
            ['choukai', 'Choukai (聴解)'],
        ];

        $type = TestType::where('code', 'JLPT')->firstOrFail();

        foreach ($jlpt as [$levelCode, $order, $durations]) {
            $level = $type->levels()->firstOrCreate(['code' => $levelCode], [
                'name' => "JLPT {$levelCode}",
                'sort_order' => $order,
            ]);

            foreach ($sections as $i => [$code, $name]) {
                $level->sections()->firstOrCreate(['code' => $code], [
                    'name' => $name,
                    'sort_order' => $i + 1,
                    'default_duration_minutes' => $durations[$i],
                ]);
            }
        }
    }

    /** Level jenis tes lain sesuai klasifikasi ujian di notulen. */
    private function otherLevels(): void
    {
        $levels = [
            'JTEST' => [
                ['A-C', 'J-Test A–C (setara JLPT N1 & N2)'],
                ['D-E', 'J-Test D–E (setara JLPT N3 & N4)'],
                ['F-G', 'J-Test F–G (setara JLPT N5)'],
            ],
            'NATTEST' => [
                ['5Q', 'NAT-Test 5Q (N5)'],
                ['4Q', 'NAT-Test 4Q (N4)'],
                ['3Q', 'NAT-Test 3Q (N3)'],
                ['2Q', 'NAT-Test 2Q (N2)'],
                ['1Q', 'NAT-Test 1Q (N1)'],
            ],
            'JFT' => [
                ['A1', 'JFT-Basic A1'],
                ['A2.1', 'JFT-Basic A2.1'],
                ['A2.2', 'JFT-Basic A2.2'],
            ],
            'SSW' => [
                ['KONSTRUKSI', 'SSW Konstruksi'],
                ['PERTANIAN', 'SSW Pertanian'],
                ['PETERNAKAN', 'SSW Peternakan'],
                ['KAIGO', 'SSW Kaigo'],
                ['PANGAN', 'SSW Pengolahan Makanan'],
            ],
        ];

        foreach ($levels as $typeCode => $rows) {
            $type = TestType::where('code', $typeCode)->firstOrFail();
            foreach ($rows as $i => [$code, $name]) {
                $type->levels()->firstOrCreate(['code' => $code], ['name' => $name, 'sort_order' => $i + 1]);
            }
        }
    }

    /** Klasifikasi kelas sesuai notulen. Urutan dipakai untuk lock materi/latihan. */
    private function curricula(): void
    {
        // [kode, kelompok, nama, cakupan, bab dari, bab sampai, urutan]
        $rows = [
            ['MINNA1', 'Kelas Basic', 'Minna no Nihongo I', null, 1, 25, 1],
            ['MINNA2', 'Kelas Basic', 'Minna no Nihongo II', null, 26, 50, 2],
            ['JFT', 'Kelas JFT', 'JFT Basic', null, null, null, 3],
            ['N3', 'Kelas N3', 'N3', null, null, null, 4],
            ['SSW-KONSTRUKSI', 'Kelas SSW', 'SSW · Konstruksi', null, null, null, 5],
            ['SSW-PERTANIAN', 'Kelas SSW', 'SSW · Pertanian', null, null, null, 5],
            ['SSW-PETERNAKAN', 'Kelas SSW', 'SSW · Peternakan', null, null, null, 5],
            ['SSW-KAIGO', 'Kelas SSW', 'SSW · Kaigo', null, null, null, 5],
            ['SSW-PANGAN', 'Kelas SSW', 'SSW · Pengolahan Makanan', null, null, null, 5],
        ];

        foreach ($rows as [$code, $group, $name, $scope, $from, $to, $order]) {
            Curriculum::firstOrCreate(['code' => $code], [
                'group' => $group, 'name' => $name, 'scope' => $scope,
                'chapter_from' => $from, 'chapter_to' => $to, 'sort_order' => $order,
            ]);
        }
    }
}
