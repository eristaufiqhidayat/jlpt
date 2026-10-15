<?php

use Illuminate\Support\Facades\Schedule;

// Kumpulkan otomatis bagian ujian yang waktunya habis (student menutup browser, dll.).
// Di server tambahkan cron: * * * * * cd /path/project && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('exams:expire-overdue')->everyMinute()->withoutOverlapping();

// Hapus upload materi sementara yang tidak jadi disimpan.
Schedule::command('uploads:prune')->dailyAt('02:00');
