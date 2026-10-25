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
use App\Models\TestType;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Role Pengawas: login bersama peserta, memantau peserta ujian; dan klasifikasi ujian sebelum kode. */
class ProctorTest extends TestCase
{
    use RefreshDatabase;

    private const LMS = 'http://jlpt.test';

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

    private function jlptExam(string $audience = 'both'): Exam
    {
        $level = Level::whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->whereHas('sections')->firstOrFail();

        return Exam::create([
            'sensei_id' => $this->user(Role::Sensei, 'sensei-'.uniqid().'@x.test')->id, 'level_id' => $level->id,
            'title' => 'Tryout '.$level->code, 'status' => ExamStatus::Open, 'audience' => $audience, 'is_paid' => false, 'price' => 0,
        ]);
    }

    /** Pengerjaan yang sedang berjalan di bagian pertama, 20 menit tersisa. */
    private function runningAttempt(Exam $exam, User $student): ExamAttempt
    {
        $section = $exam->level->sections()->orderBy('sort_order')->firstOrFail();
        $examSection = ExamSection::firstOrCreate(['exam_id' => $exam->id, 'section_id' => $section->id], ['sort_order' => 1, 'duration_minutes' => 30, 'question_count' => 0]);
        $attempt = ExamAttempt::create(['exam_id' => $exam->id, 'student_id' => $student->id, 'started_at' => now()->subMinutes(10), 'status' => AttemptStatus::Ongoing]);
        AttemptSection::create([
            'exam_attempt_id' => $attempt->id, 'exam_section_id' => $examSection->id, 'sort_order' => 1, 'duration_minutes' => 30,
            'started_at' => now()->subMinutes(10), 'deadline_at' => now()->addMinutes(20),
        ]);

        return $attempt;
    }

    public function test_proctor_logs_in_on_both_participant_login_pages(): void
    {
        $this->user(Role::Proctor, 'pengawas@x.test');

        foreach ([self::TEST, self::LMS] as $host) {
            $this->post($host.'/login', ['email' => 'pengawas@x.test', 'password' => 'rahasia123'])->assertRedirect();
            $this->assertAuthenticated();
            $this->get($host.'/')->assertRedirect($host.'/pengawas');
            $this->post($host.'/logout');
        }

        $this->assertNotNull(User::where('email', 'pengawas@x.test')->value('last_login_at'));
    }

    public function test_proctor_sees_participant_section_question_number_and_timer(): void
    {
        $proctor = $this->user(Role::Proctor, 'pengawas@x.test');
        $student = $this->user(Role::Student, 'siswa@x.test', 'Budi Santoso');
        $exam = $this->jlptExam();
        $attempt = $this->runningAttempt($exam, $student);
        $attempt->update(['current_question_no' => 7, 'last_seen_at' => now()]);

        $this->actingAs($proctor)->get(self::LMS.'/pengawas')->assertOk()
            ->assertSee('Pengawasan Ujian')->assertSee('Budi Santoso')->assertSee($exam->code)
            ->assertSee('JLPT')->assertSee($attempt->sections->first()->examSection->section->name)
            ->assertSee('Sesi 1 dari 1')->assertSee('data-deadline', false)->assertSee('pr-dot on', false);

        $this->actingAs($proctor)->getJson(self::LMS.'/pengawas/data')->assertOk()
            ->assertJsonPath('stats.ongoing', 1)->assertJsonPath('stats.online', 1)
            ->assertJson(fn ($json) => $json->where('rows', fn ($html) => str_contains($html, 'Budi Santoso'))->etc());
    }

    public function test_filter_by_classification_hides_other_types(): void
    {
        $proctor = $this->user(Role::Proctor, 'pengawas@x.test');
        $this->runningAttempt($this->jlptExam(), $this->user(Role::Student, 'siswa@x.test', 'Budi Santoso'));
        $exam = $this->jlptExam();
        $this->runningAttempt($exam, $this->user(Role::Student, 'lain@x.test', 'Ani Lestari'));
        $other = TestType::where('code', '!=', 'JLPT')->firstOrFail();
        $otherLevel = Level::where('test_type_id', $exam->level->test_type_id)->whereKeyNot($exam->level_id)->firstOrFail();

        $this->actingAs($proctor)->get(self::LMS.'/pengawas?klasifikasi=type:'.$other->id)->assertOk()->assertDontSee('Budi Santoso');
        $this->actingAs($proctor)->get(self::LMS.'/pengawas?klasifikasi=type:'.$exam->level->test_type_id)->assertOk()->assertSee('Budi Santoso');
        $this->actingAs($proctor)->get(self::LMS.'/pengawas?klasifikasi=level:'.$exam->level_id)->assertOk()->assertSee('Ani Lestari');
        $this->actingAs($proctor)->get(self::LMS.'/pengawas?klasifikasi=level:'.$otherLevel->id)->assertOk()->assertDontSee('Ani Lestari');
    }

    public function test_logged_in_participant_without_exam_is_listed_as_waiting(): void
    {
        $proctor = $this->user(Role::Proctor, 'pengawas@x.test');
        $this->user(Role::TestTaker, 'umum@x.test', 'Sari Peserta')->forceFill(['last_login_at' => now()])->save();

        $this->actingAs($proctor)->get(self::LMS.'/pengawas')->assertOk()->assertSee('Siap ujian, belum memasukkan kode')->assertSee('Sari Peserta');
    }

    public function test_participant_page_reports_current_question_number(): void
    {
        $student = $this->user(Role::Student, 'siswa@x.test');
        $attempt = $this->runningAttempt($this->jlptExam(), $student);

        $this->actingAs($student)->postJson(self::LMS.'/attempts/'.$attempt->id.'/position', ['no' => 4])->assertOk();
        $this->assertSame(4, $attempt->fresh()->current_question_no);
        $this->assertNotNull($attempt->fresh()->last_seen_at);

        $other = $this->user(Role::Student, 'lain@x.test');
        $this->actingAs($other)->postJson(self::LMS.'/attempts/'.$attempt->id.'/position', ['no' => 1])->assertForbidden();
    }

    public function test_participants_cannot_open_proctor_page_and_proctor_cannot_take_exams(): void
    {
        $this->actingAs($this->user(Role::Student, 'siswa@x.test'))->get(self::LMS.'/pengawas')->assertForbidden();
        $this->actingAs($this->user(Role::TestTaker, 'umum@x.test'))->get(self::TEST.'/pengawas')->assertForbidden();
        $this->actingAs($this->user(Role::Proctor, 'pengawas@x.test'))->get(self::LMS.'/ujian')->assertForbidden();
    }

    public function test_join_form_asks_classification_before_code(): void
    {
        $taker = $this->user(Role::TestTaker, 'umum@x.test');

        $this->actingAs($taker)->get(self::TEST.'/ujian')->assertOk()
            ->assertSeeInOrder(['Klasifikasi ujian', 'Kode ujian'])->assertSee('<optgroup label="JLPT">', false)->assertSee('JLPT N4');
    }

    public function test_join_requires_matching_classification(): void
    {
        $exam = $this->jlptExam('public');
        $taker = $this->user(Role::TestTaker, 'umum@x.test');
        $otherLevel = Level::where('test_type_id', $exam->level->test_type_id)->whereKeyNot($exam->level_id)->firstOrFail();

        $this->actingAs($taker)->from(self::TEST.'/ujian')->post(self::TEST.'/exam/join', ['with_type' => 1, 'code' => $exam->code])
            ->assertSessionHasErrors('level_id');
        $this->actingAs($taker)->from(self::TEST.'/ujian')->post(self::TEST.'/exam/join', ['with_type' => 1, 'level_id' => $otherLevel->id, 'code' => $exam->code])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'tidak sesuai dengan klasifikasi') && str_contains($e, 'JLPT '.$exam->level->code));
        // Klasifikasi cocok: lanjut ke pemeriksaan berikutnya (ujian ini belum punya bagian soal).
        $this->actingAs($taker)->from(self::TEST.'/ujian')->post(self::TEST.'/exam/join', ['with_type' => 1, 'level_id' => $exam->level_id, 'code' => $exam->code])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'belum memiliki bagian soal'));
    }

    public function test_question_bank_menu_is_renamed(): void
    {
        $this->actingAs($this->user(Role::Sensei, 'sensei@x.test'))->get(self::LMS.'/sensei/questions')->assertOk()
            ->assertSee('Pelatihan Soal')->assertDontSee('Bank Soal');
    }

    public function test_proctor_can_stop_one_participant(): void
    {
        $proctor = $this->user(Role::Proctor, 'pengawas@x.test', 'Pak Pengawas');
        $student = $this->user(Role::Student, 'siswa@x.test', 'Budi Santoso');
        $attempt = $this->runningAttempt($this->jlptExam(), $student);

        $this->actingAs($proctor)->from(self::LMS.'/pengawas')
            ->post(self::LMS.'/pengawas/attempts/'.$attempt->id.'/stop', ['reason' => 'Membuka ponsel'])
            ->assertRedirect(self::LMS.'/pengawas')->assertSessionHas('success');

        $attempt->refresh();
        $this->assertSame(AttemptStatus::Finished, $attempt->status);
        $this->assertSame($proctor->id, $attempt->stopped_by);
        $this->assertSame('Membuka ponsel', $attempt->stop_reason);
        $this->assertSame('proctor', $attempt->sections()->first()->submitted_by);

        // Halaman ujian peserta mendapat 409 dan diarahkan ke hasil yang menyebut penghentian.
        $this->actingAs($student)->postJson(self::LMS.'/attempts/'.$attempt->id.'/position', ['no' => 1])->assertStatus(409);
        $this->actingAs($student)->get(self::LMS.'/attempts/'.$attempt->id)->assertRedirect();
        $this->actingAs($student)->get(self::LMS.'/attempts/'.$attempt->id.'/result')->assertOk()->assertSee('dihentikan oleh pengawas')->assertSee('Membuka ponsel');

        $this->actingAs($proctor)->get(self::LMS.'/pengawas')->assertSee('Dihentikan')->assertSee('Pak Pengawas');
        // Menghentikan lagi tidak mengubah apa-apa.
        $this->actingAs($proctor)->post(self::LMS.'/pengawas/attempts/'.$attempt->id.'/stop')->assertSessionHas('error');
    }

    public function test_proctor_can_close_exam_and_stop_everyone_still_working(): void
    {
        $proctor = $this->user(Role::Proctor, 'pengawas@x.test');
        $exam = $this->jlptExam();
        $a1 = $this->runningAttempt($exam, $this->user(Role::Student, 's1@x.test'));
        $a2 = $this->runningAttempt($exam, $this->user(Role::TestTaker, 's2@x.test'));
        $otherExam = $this->jlptExam();
        $other = $this->runningAttempt($otherExam, $this->user(Role::Student, 's3@x.test'));

        $this->actingAs($proctor)->get(self::LMS.'/pengawas')->assertSee('Ujian yang dibuka')->assertSee('Tutup ujian');

        $this->actingAs($proctor)->from(self::LMS.'/pengawas')->post(self::LMS.'/pengawas/exams/'.$exam->id.'/close')
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 peserta'));

        $this->assertSame(ExamStatus::Closed, $exam->fresh()->status);
        $this->assertSame(AttemptStatus::Finished, $a1->fresh()->status);
        $this->assertSame(AttemptStatus::Finished, $a2->fresh()->status);
        $this->assertSame('Ujian ditutup pengawas', $a1->fresh()->stop_reason);
        $this->assertSame(AttemptStatus::Ongoing, $other->fresh()->status);

        // Kode ujian yang ditutup tidak bisa dipakai lagi.
        $this->actingAs($this->user(Role::Student, 's4@x.test'))->from(self::LMS.'/ujian')
            ->post(self::LMS.'/exam/join', ['code' => $exam->code])->assertSessionHas('error', fn ($e) => str_contains($e, 'tidak dibuka'));
    }

    public function test_participants_cannot_stop_or_close(): void
    {
        $student = $this->user(Role::Student, 'siswa@x.test');
        $exam = $this->jlptExam();
        $attempt = $this->runningAttempt($exam, $student);

        $this->actingAs($student)->post(self::LMS.'/pengawas/attempts/'.$attempt->id.'/stop')->assertForbidden();
        $this->actingAs($student)->post(self::LMS.'/pengawas/exams/'.$exam->id.'/close')->assertForbidden();
        $this->assertSame(AttemptStatus::Ongoing, $attempt->fresh()->status);
    }
}
