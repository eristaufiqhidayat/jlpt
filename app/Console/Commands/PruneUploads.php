<?php

namespace App\Console\Commands;

use App\Services\ChunkedUploads;
use Illuminate\Console\Command;

/** Hapus file upload sementara yang tidak jadi disimpan ke materi. */
class PruneUploads extends Command
{
    protected $signature = 'uploads:prune {--hours=24 : Umur minimal upload sementara yang dihapus (jam)}';

    protected $description = 'Hapus upload materi sementara yang tidak disimpan';

    public function handle(ChunkedUploads $uploads): int
    {
        $count = $uploads->prune((int) $this->option('hours'));
        $this->info("{$count} upload sementara dihapus.");

        return self::SUCCESS;
    }
}
