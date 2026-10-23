<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case EducationAdmin = 'education_admin'; // Admin Pendidikan: data master akademik, materi & soal pakem
    case Sensei = 'sensei';
    case Student = 'student';
    case Director = 'director';
    case TestTaker = 'test_taker'; // Peserta tes umum/berbayar: hanya di domain tes online
    case Proctor = 'proctor'; // Pengawas ujian: memantau peserta yang sedang ujian; login di halaman yang sama dengan peserta

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::EducationAdmin => 'Admin Pendidikan',
            self::Sensei => 'Sensei (Instruktur)',
            self::Student => 'Peserta',
            self::Director => 'Direktur',
            self::TestTaker => 'Peserta Tes',
            self::Proctor => 'Pengawas',
        };
    }
}
