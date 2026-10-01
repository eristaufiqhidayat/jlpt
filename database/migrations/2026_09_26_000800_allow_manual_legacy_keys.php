<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sensei bisa melengkapi kunci arsip lama dari panel; kunci baru tidak punya ID lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_answer_keys', function (Blueprint $table) {
            $table->unsignedInteger('legacy_id')->nullable()->change();
            $table->foreignId('edited_by')->nullable()->after('is_valid')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('legacy_answer_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
        });
    }
};
