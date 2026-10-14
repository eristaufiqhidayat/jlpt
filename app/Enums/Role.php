<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case EducationAdmin = 'education_admin'; // Admin Pendidikan: data master akademik, materi & soal pakem
    case Sensei = 'sensei';
    case Student = 'student';
    case Director = 'director';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::EducationAdmin => 'Admin Pendidikan',
            self::Sensei => 'Sensei (Instruktur)',
            self::Student => 'Peserta',
            self::Director => 'Direktur',
        };
    }
}
