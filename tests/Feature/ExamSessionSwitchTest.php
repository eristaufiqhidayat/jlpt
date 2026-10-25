<?php

namespace Tests\Feature;

use App\Enums\AttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\Role;
use App\Models\AttemptSection;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamSection;
use App\Models\Level;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Peserta mengumpulkan ujian setelah akun di browser berganti (mis. login Pengawas di tab lain):
 * tetap 403, tapi halamannya menjelaskan akun yang sedang login dan cara melanjutkan.
 */
class ExamSessionSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const TEST = 'http://testonline.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['portals.lms_host' => 'jlpt.test', 'portals.test_host' => 'testonline.test']);
        $this->seed(MasterDataSeeder::class);
    }

    private function user(Role $role, string $email, string $name = 'User'): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'name' => $name, 'password' => 'rahasia123', 'is_active' => true]);
    }

    private function runningAttempt(User $student): ExamAttempt
    {
        $level = Level::whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->whereHas('sections')->firstOrFail();
        $exam = Exam::create([
            'sensei_id' => $this->user(Role::Sensei, 'sensei@x.test')->id, 'level_id' => $level->id,
            'title' => 'Tryout '.$level->code, 'status' => ExamStatus::Open, 'audience' => 'both', 'is_paid' => false, 'price' => 0,
        ]);
        $section = $level->sections()->orderBy('sort_order')->firstOrFail();
        $examSection = ExamSection::firstOrCreate(['exam_id' => $exam->id, 'section_id' => $section->id], ['sort_order' => 1, 'duration_minutes' => 30, 'question_count' => 0]);
        $attempt = ExamAttempt::create(['exam_id' => $exam->id, 'student_id' => $student->id, 'started_at' => now()->subMinutes(10), 'status' => AttemptStatus::Ongoing]);
        AttemptSection::create([
            'exam_attempt_id' => $attempt->id, 'exam_section_id' => $examSection->id, 'sort_order' => 1, 'duration_minutes' => 30,
            'started_at' => now()->subMinutes(10), 'deadline_at' => now()->addMinutes(20),
        ]);

        return $attempt;
    }

    public function test_submit_after_switching_to_proctor_shows_helpful_403(): void
    {
        $attempt = $this->runningAttempt($this->user(Role::TestTaker, 'peserta@x.test', 'Demo Peserta Tes'));
        $proctor = $this->user(Role::Proctor, 'pengawas@x.test', 'Demo Pengawas');

        $this->actingAs($proctor)->post(self::TEST.'/attempts/'.$attempt->id.'/submit')
            ->assertForbidden()
            ->assertSee('Demo Pengawas')
            ->assertSee('Pengawas')
            ->assertSee('Keluar &amp; login ulang', false);
    }

    public function test_exam_api_answers_403_json_after_account_switch(): void
    {
        $attempt = $this->runningAttempt($this->user(Role::TestTaker, 'peserta@x.test'));
        $other = $this->user(Role::TestTaker, 'lain@x.test');

        // Halaman ujian memakai status ini untuk menampilkan peringatan "sesi ujian terputus".
        $this->actingAs($other)->postJson(self::TEST.'/attempts/'.$attempt->id.'/position', ['no' => 1])->assertForbidden();
    }

    public function test_get_submit_url_redirects_to_exam_instead_of_405(): void
    {
        $student = $this->user(Role::TestTaker, 'peserta@x.test');
        $attempt = $this->runningAttempt($student);

        $this->actingAs($student)->get(self::TEST.'/attempts/'.$attempt->id.'/submit')
            ->assertRedirect(self::TEST.'/attempts/'.$attempt->id);
    }

    public function test_exam_page_contains_session_lost_notice(): void
    {
        $student = $this->user(Role::TestTaker, 'peserta@x.test', 'Demo Peserta Tes');
        $attempt = $this->runningAttempt($student);

        $this->actingAs($student)->get(self::TEST.'/attempts/'.$attempt->id)
            ->assertOk()
            ->assertSee('id="session-lost"', false)
            ->assertSee('Sesi ujian terputus');
    }
}
