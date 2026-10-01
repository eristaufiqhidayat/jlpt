<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Sensei = 'sensei';
    case Student = 'student';
    case Director = 'director';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Sensei => 'Sensei (Instruktur)',
            self::Student => 'Peserta',
            self::Director => 'Direktur',
        };
    }
}
