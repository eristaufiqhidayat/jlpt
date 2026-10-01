<?php

namespace Database\Seeders;

use App\Models\TestType;
use Illuminate\Database\Seeder;

/**
 * Jenis tes, level, dan bagian.
 *
 * Durasi JLPT mengikuti jadwal resmi (N3–N5). Untuk N1 dan N2, JLPT resmi menggabungkan
 * 文字・語彙・文法・読解 dalam satu sesi (N1 110 menit, N2 105 menit); di sini dipecah
 * menjadi dua bagian agar sama dengan struktur aplikasi lama. Semua durasi hanya nilai
 * awal — sensei bisa mengubahnya per ujian.
 *
 * Level untuk jenis tes selain JLPT dibuat otomatis oleh `php artisan legacy:import`
 * berdasarkan data lama, atau bisa ditambah admin dari panel.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['JLPT', 'JLPT', 1],
            ['JTEST', 'J.TEST', 2],
            ['NATTEST', 'NAT-TEST', 3],
            ['JFT', 'JFT-Basic', 4],
            ['KANJI', 'Kanji', 5],
            ['PROMETRIC', 'Prometric', 6],
            ['PROMETRICKANJI', 'Prometric Kanji', 7],
            ['INTERVIEW', 'Interview', 8],
        ];

        foreach ($types as [$code, $name, $order]) {
            TestType::updateOrCreate(['code' => $code], [
                'name' => $name,
                'sort_order' => $order,
                // Fokus tahap pertama: JLPT. Jenis lain tetap ada untuk arsip.
                'is_active' => $code === 'JLPT',
            ]);
        }

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
            $level = $type->levels()->updateOrCreate(['code' => $levelCode], [
                'name' => "JLPT {$levelCode}",
                'sort_order' => $order,
            ]);

            foreach ($sections as $i => [$code, $name]) {
                $level->sections()->updateOrCreate(['code' => $code], [
                    'name' => $name,
                    'sort_order' => $i + 1,
                    'default_duration_minutes' => $durations[$i],
                ]);
            }
        }
    }
}
