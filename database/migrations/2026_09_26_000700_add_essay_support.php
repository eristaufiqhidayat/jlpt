<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soal esai (mis. tes Kanji: menulis bacaan/kanji), dinilai manual oleh sensei.
 *
 * - questions.answer_reference : contoh jawaban benar, hanya untuk sensei saat menilai.
 * - attempt_questions.answer_text, graded_by, graded_at, feedback : jawaban tertulis student dan penilaiannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->text('answer_reference')->nullable()->after('explanation');
        });

        Schema::table('attempt_questions', function (Blueprint $table) {
            $table->text('answer_text')->nullable()->after('selected_option_id');
            $table->foreignId('graded_by')->nullable()->after('is_correct')->constrained('users')->nullOnDelete();
            $table->dateTime('graded_at')->nullable()->after('graded_by');
            $table->text('feedback')->nullable()->after('graded_at');
        });
    }

    public function down(): void
    {
        Schema::table('attempt_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('graded_by');
            $table->dropColumn(['answer_text', 'graded_at', 'feedback']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('answer_reference');
        });
    }
};
