<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audio choukai per nomor (jwb_soal.flag1) dan pembahasan (jwb_soal.textbahasan) dari aplikasi lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_answer_keys', function (Blueprint $table) {
            $table->string('audio_filename')->nullable()->after('is_valid'); // nama asli, mis. 111009N5...11.mp3
            $table->string('audio_path')->nullable()->after('audio_filename'); // salinan di storage/app/private
            $table->text('explanation')->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_answer_keys', function (Blueprint $table) {
            $table->dropColumn(['audio_filename', 'audio_path', 'explanation']);
        });
    }
};
