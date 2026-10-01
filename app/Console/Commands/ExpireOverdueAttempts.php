<?php

namespace App\Console\Commands;

use App\Models\AttemptSection;
use App\Services\ExamAttemptService;
use Illuminate\Console\Command;

/**
 * Mengumpulkan otomatis bagian ujian yang waktunya habis,
 * termasuk milik student yang menutup browser. Dijalankan scheduler tiap menit.
 */
class ExpireOverdueAttempts extends Command
{
    protected $signature = 'exams:expire-overdue';

    protected $description = 'Kumpulkan otomatis bagian ujian yang waktunya sudah habis';

    public function handle(ExamAttemptService $service): int
    {
        $count = 0;

        AttemptSection::query()
            ->whereNull('submitted_at')
            ->whereNotNull('started_at')
            ->where('deadline_at', '<', now()->subSeconds(ExamAttemptService::GRACE_SECONDS))
            ->with('attempt')
            ->chunkById(100, function ($sections) use ($service, &$count) {
                foreach ($sections as $section) {
                    $service->expireOverdueSections($section->attempt);
                    $count++;
                }
            });

        $this->info("{$count} bagian dikumpulkan otomatis.");

        return self::SUCCESS;
    }
}
