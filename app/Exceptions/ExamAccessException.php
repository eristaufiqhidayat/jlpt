<?php

namespace App\Exceptions;

use RuntimeException;

/** Student tidak boleh memulai/melanjutkan ujian; pesan aman ditampilkan ke user. */
class ExamAccessException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'forbidden')
    {
        parent::__construct($message);
    }
}
