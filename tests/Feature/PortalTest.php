<?php

namespace Tests\Feature;

use App\Enums\ExamStatus;
use App\Enums\Role;
use App\Models\Exam;
use App\Models\Level;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dua domain: LMS (siswa & staf) dan tes online (peserta tes umum), satu aplikasi & database. */
class PortalTest extends TestCase
{
    use RefreshDatabase;

    private const LMS = 'http://jlpt.test';

    private const TEST = 'http://testonline.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['portals.lms_host' => 'jlpt.test', 'portals.test_host' => 'testonline.test']);
    }

    private function user(Role $role, string $email): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'password' => 'rahasia123', 'is_active' => true]);
    }

    private function exam(string $audience): Exam
    {
        $this->seed(MasterDataSeeder::class);

        return Exam::create([
            'sensei_id' => $this->user(Role::Sensei, 'sensei-'.$audience.'@x.test')->id, 'level_id' => Level::first()->id,
            'title' => 'Ujian '.$audience, 'status' => ExamStatus::Open, 'audience' => $audience, 'is_paid' => false, 'price' => 0,
        ]);
    }

    public function test_login_pages_differ_per_domain(): void
    {
        $this->get(self::TEST.'/login')->assertOk()->assertSee('Tes Online JLPT – Jakaruta Global Group')->assertSee('portal-test', false);
        $this->get(self::LMS.'/login')->assertOk()->assertSee('LMS LPK Jakaruta Global Group')->assertDontSee('portal-test', false);
    }

    public function test_test_taker_logs_in_on_test_domain_only(): void
    {
        $this->user(Role::TestTaker, 'umum@x.test');

        $this->from(self::LMS.'/login')->post(self::LMS.'/login', ['email' => 'umum@x.test', 'password' => 'rahasia123'])
            ->assertRedirect(self::LMS.'/login')->assertSessionHas('wrong_portal');
        $this->assertGuest();
        $this->get(self::LMS.'/login')->assertSee('Akun ini untuk tes online')->assertSee(self::TEST.'/login');

        $this->post(self::TEST.'/login', ['email' => 'umum@x.test', 'password' => 'rahasia123'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_student_and_staff_are_refused_on_test_domain(): void
    {
        $this->user(Role::Student, 'siswa@x.test');
        $this->user(Role::Admin, 'admin@x.test');

        foreach (['siswa@x.test', 'admin@x.test'] as $email) {
            $this->from(self::TEST.'/login')->post(self::TEST.'/login', ['email' => $email, 'password' => 'rahasia123'])
                ->assertSessionHas('wrong_portal', fn ($n) => str_contains($n['text'], 'Akun ini untuk LMS'));
            $this->assertGuest();
        }
    }

    public function test_wrong_password_still_shows_generic_error(): void
    {
        $this->user(Role::Student, 'siswa@x.test');

        $this->from(self::TEST.'/login')->post(self::TEST.'/login', ['email' => 'siswa@x.test', 'password' => 'salah'])
            ->assertSessionHasErrors('email')->assertSessionMissing('wrong_portal');
    }

    public function test_session_on_wrong_domain_is_logged_out(): void
    {
        $student = $this->user(Role::Student, 'siswa@x.test');

        $this->actingAs($student)->get(self::TEST.'/ujian')->assertRedirect(self::TEST.'/login')->assertSessionHas('wrong_portal');
        $this->assertGuest();
    }

    public function test_test_taker_has_exam_menu_but_no_lms(): void
    {
        $taker = $this->user(Role::TestTaker, 'umum@x.test');

        $this->actingAs($taker)->get(self::TEST.'/')->assertRedirect(self::TEST.'/ujian');
        $this->actingAs($taker)->get(self::TEST.'/ujian')->assertOk()->assertSee('Tes Saya')->assertDontSee('Kelas Saya');
        $this->actingAs($taker)->get(self::TEST.'/materi')->assertForbidden();
        $this->actingAs($taker)->get(self::TEST.'/kelas')->assertForbidden();
    }

    public function test_exam_codes_respect_audience(): void
    {
        $internal = $this->exam('internal');
        $public = $this->exam('public');
        $both = $this->exam('both');
        $student = $this->user(Role::Student, 'siswa@x.test');
        $taker = $this->user(Role::TestTaker, 'umum@x.test');

        // Ujian tanpa bagian soal berhenti di "belum memiliki bagian soal" bila sasarannya cocok.
        $this->actingAs($taker)->from(self::TEST.'/ujian')->post(self::TEST.'/exam/join', ['code' => $internal->code])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'ujian internal siswa LPK'));
        $this->actingAs($taker)->from(self::TEST.'/ujian')->post(self::TEST.'/exam/join', ['code' => $public->code])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'belum memiliki bagian soal'));
        $this->actingAs($taker)->from(self::TEST.'/ujian')->post(self::TEST.'/exam/join', ['code' => $both->code])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'belum memiliki bagian soal'));

        $this->actingAs($student)->from(self::LMS.'/ujian')->post(self::LMS.'/exam/join', ['code' => $public->code])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'peserta tes umum'));
        $this->actingAs($student)->from(self::LMS.'/ujian')->post(self::LMS.'/exam/join', ['code' => $internal->code])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'belum memiliki bagian soal'));
    }

    public function test_demo_buttons_follow_domain(): void
    {
        config(['app.demo_login' => true]);

        $this->get(self::TEST.'/login')->assertSee('Peserta Tes')->assertDontSee('Admin Pendidikan');
        $this->get(self::LMS.'/login')->assertSee('Admin Pendidikan')->assertDontSee('Peserta Tes');
    }

    public function test_without_test_host_everyone_can_log_in_anywhere(): void
    {
        config(['portals.test_host' => null]);
        $this->user(Role::TestTaker, 'umum@x.test');

        $this->post(self::LMS.'/login', ['email' => 'umum@x.test', 'password' => 'rahasia123'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_test_taker_cannot_open_material_files(): void
    {
        $taker = $this->user(Role::TestTaker, 'umum@x.test');

        $this->actingAs($taker)->get(self::TEST.'/media/materials/1/a.pdf')->assertForbidden();
    }
}
