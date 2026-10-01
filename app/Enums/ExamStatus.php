<?php

namespace App\Enums;

enum ExamStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
