<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kurikulum "terbuka": tidak ikut lock, bisa dibuka semua peserta (disaring per level materi).
 * "Materi umum" yang sebelumnya bawaan sistem kini menjadi kurikulum terbuka di Data Master,
 * dan materi pakem tanpa kurikulum dipindah ke sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricula', function (Blueprint $table) {
            $table->boolean('is_open')->default(false)->after('sort_order');
        });

        $id = DB::table('curricula')->where('code', 'UMUM')->value('id');
        if ($id) {
            DB::table('curricula')->where('id', $id)->update(['is_open' => true]);
        } else {
            $id = DB::table('curricula')->insertGetId([
                'group' => 'Umum', 'name' => 'Materi Umum', 'code' => 'UMUM', 'scope' => 'Terbuka untuk semua peserta',
                'sort_order' => 0, 'is_open' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('materials')->where('kind', 'core')->whereNull('curriculum_id')->update(['curriculum_id' => $id]);
    }

    public function down(): void
    {
        $id = DB::table('curricula')->where('code', 'UMUM')->value('id');
        if ($id) {
            DB::table('materials')->where('curriculum_id', $id)->update(['curriculum_id' => null]);
            DB::table('curricula')->where('id', $id)->delete();
        }
        Schema::table('curricula', fn (Blueprint $table) => $table->dropColumn('is_open'));
    }
};
