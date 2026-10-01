<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran per ujian. Student hanya bisa mulai ujian berbayar jika ada payment berstatus paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->string('order_id', 64)->unique();         // dikirim ke payment gateway
            $table->unsignedInteger('amount');
            $table->string('status', 20)->default('pending'); // pending | paid | expired | failed | refunded
            $table->string('gateway', 30)->nullable();        // midtrans | xendit | manual
            $table->string('gateway_ref')->nullable();
            $table->json('gateway_payload')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'exam_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
