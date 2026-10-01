<?php

namespace App\Enums;

enum AttemptStatus: string
{
    case Ongoing = 'ongoing';
    case PendingReview = 'pending_review'; // semua bagian selesai, masih ada esai yang belum dinilai
    case Finished = 'finished';
}
