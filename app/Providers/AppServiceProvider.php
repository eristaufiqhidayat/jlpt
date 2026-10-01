<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // @jp($teks) — teks Jepang dengan furigana {漢字|かんじ} dan garis bawah __kata__
        Blade::directive('jp', fn (string $expr) => "<?php echo \\App\\Support\\JpText::render({$expr}); ?>");

        Paginator::defaultView('partials.pagination');

        // Nama bulan/hari dalam bahasa Indonesia (translatedFormat, diffForHumans).
        \Illuminate\Support\Carbon::setLocale(config('app.locale'));
    }
}
