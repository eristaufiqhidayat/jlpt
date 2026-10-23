<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Portal = domain yang sedang dibuka: LMS (siswa & staf) atau Tes Online (peserta tes umum).
 * Satu kode dan satu database; domain menentukan siapa boleh masuk serta tampilan login & menu.
 */
class Portal
{
    public const LMS = 'lms';

    public const TEST = 'test';

    /** Pemisahan aktif hanya bila domain tes diatur (PORTAL_TEST_HOST). */
    public static function enabled(): bool
    {
        return filled(config('portals.test_host'));
    }

    public static function current(?Request $request = null): string
    {
        $request ??= request();

        return self::enabled() && strcasecmp($request->getHost(), (string) config('portals.test_host')) === 0 ? self::TEST : self::LMS;
    }

    public static function isTest(?Request $request = null): bool
    {
        return self::current($request) === self::TEST;
    }

    /** Portal tempat peran ini seharusnya masuk. */
    public static function forRole(Role $role): string
    {
        return $role === Role::TestTaker ? self::TEST : self::LMS;
    }

    /** Pengawas memantau siswa LPK dan peserta tes umum, jadi boleh masuk lewat kedua domain. */
    public static function allowsRole(Role $role, ?string $portal = null): bool
    {
        return ! self::enabled() || $role === Role::Proctor || self::forRole($role) === ($portal ?? self::current());
    }

    public static function allows(User $user, ?string $portal = null): bool
    {
        return self::allowsRole($user->role, $portal);
    }

    public static function host(string $portal): string
    {
        $host = $portal === self::TEST ? config('portals.test_host') : config('portals.lms_host');

        return $host ?: (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public static function loginUrl(string $portal): string
    {
        return request()->getScheme().'://'.self::host($portal).'/login';
    }

    /** Pesan untuk akun yang masuk lewat domain yang salah, beserta tautan ke domain yang benar. */
    public static function wrongPortalNotice(User $user): array
    {
        $target = self::forRole($user->role);

        return [
            'text' => $target === self::TEST
                ? 'Akun ini untuk tes online. Silakan masuk di '.self::host($target).'.'
                : 'Akun ini untuk LMS. Silakan masuk di '.self::host($target).'.',
            'url' => self::loginUrl($target),
            'label' => 'Buka '.self::host($target),
        ];
    }
}
