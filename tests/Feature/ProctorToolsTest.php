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

/** Alat pengawas: keluar halaman, tambah waktu, peringatan cron, jenis ujian, hak sensei, daftar siap ujian. */
class ProctorToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    private function user(Role $role, string $name = 'User'): User
    {
        return User::factory()->create(['role' => $role, 'name' => $name, 'is_active' => true]);
    }

    private function exam(string $audience = 'internal', ?User $sensei = null, ?string $pool = null): Exam
    {
        $level = Level::whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->whereHas('sections')->firstOrFail();

        return Exam::create([
            'sensei_id' => ($sensei ?? $this->user(Role::Sensei))->id, 'level_id' => $level->id, 'title' => 'Ujian '.uniqid(),
            'status' => ExamStatus::Open, 'audience' => $audience, 'question_pool' => $pool, 'is_paid' => false, 'price' => 0,
        ]);
    }

    private function running(Exam $exam, User $student, int $minutesLeft = 20): ExamAttempt
    {
        $section = $exam->level->sections()->orderBy('sort_order')->firstOrFail();
        $examSection = ExamSection::firstOrCreate(['exam_id' => $exam->id, 'section_id' => $section->id], ['sort_order' => 1, 'duration_minutes' => 30, 'question_count' => 0]);
        $attempt = ExamAttempt::create(['exam_id' => $exam->id, 'student_id' => $student->id, 'started_at' => now()->subMinutes(10), 'status' => AttemptStatus::Ongoing]);
        AttemptSection::create([
            'exam_attempt_id' => $attempt->id, 'exam_section_id' => $examSection->id, 'sort_order' => 1, 'duration_minutes' => 30,
            'started_at' => now()->subMinutes(10), 'deadline_at' => now()->addMinutes($minutesLeft),
        ]);

        return $attempt;
    }

    public function test_leaving_the_exam_page_is_counted_and_shown_to_proctor(): void
    {
        $student = $this->user(Role::Student, 'Rina Ujian');
        $attempt = $this->running($this->exam(), $student);

        $this->actingAs($student)->postJson(route('student.attempts.away', $attempt))->assertOk();
        $this->actingAs($student)->postJson(route('student.attempts.away', $attempt))->assertOk();
        $this->assertSame(2, $attempt->fresh()->focus_lost_count);

        $this->actingAs($this->user(Role::Proctor))->get(route('proctor.index'))
            ->assertOk()->assertSee('Keluar halaman 2×')->assertSee('pernah keluar dari halaman ujian')->assertSee('pr-away', false);
    }

    public function test_only_the_owner_can_report_leaving_and_not_after_finishing(): void
    {
        $attempt = $this->running($this->exam(), $this->user(Role::Student));

        $this->actingAs($this->user(Role::Student))->postJson(route('student.attempts.away', $attempt))->assertForbidden();
        $attempt->update(['status' => AttemptStatus::Finished, 'finished_at' => now()]);
        $this->actingAs($attempt->student)->postJson(route('student.attempts.away', $attempt))->assertStatus(409);
        $this->assertSame(0, $attempt->fresh()->focus_lost_count);
    }

    public function test_proctor_extends_running_section_and_student_timer_follows(): void
    {
        $student = $this->user(Role::Student);
        $attempt = $this->running($this->exam(), $student, 5);
        $proctor = $this->user(Role::Proctor, 'Bu Pengawas');

        $this->actingAs($proctor)->from(route('proctor.index'))->post(route('proctor.attempts.extend', $attempt), ['minutes' => 10])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '10 menit'));

        $section = $attempt->sections()->first();
        $this->assertEqualsWithDelta(15 * 60, $section->remainingSeconds(), 5);
        $this->assertSame(10, $attempt->fresh()->extra_minutes);

        $this->actingAs($student)->postJson(route('student.attempts.position', $attempt), ['no' => 1])
            ->assertOk()->assertJson(fn ($json) => $json->where('ok', true)->where('remaining', fn ($r) => $r > 14 * 60)->etc());
        $this->actingAs($proctor)->get(route('proctor.index'))->assertSee('+10 mnt oleh Bu Pengawas');
    }

    public function test_extending_an_overdue_section_counts_from_now(): void
    {
        $attempt = $this->running($this->exam(), $this->user(Role::Student), -3);

        $this->actingAs($this->user(Role::Proctor))->post(route('proctor.attempts.extend', $attempt), ['minutes' => 5]);

        $this->assertEqualsWithDelta(5 * 60, $attempt->sections()->first()->remainingSeconds(), 5);
    }

    public function test_extend_validates_minutes_and_finished_attempts(): void
    {
        $attempt = $this->running($this->exam(), $this->user(Role::Student));
        $proctor = $this->user(Role::Proctor);

        $this->actingAs($proctor)->post(route('proctor.attempts.extend', $attempt), ['minutes' => 0])->assertSessionHasErrors('minutes');
        $this->actingAs($proctor)->post(route('proctor.attempts.extend', $attempt), ['minutes' => 61])->assertSessionHasErrors('minutes');

        $attempt->update(['status' => AttemptStatus::Finished, 'finished_at' => now()]);
        $this->actingAs($proctor)->post(route('proctor.attempts.extend', $attempt), ['minutes' => 5])->assertSessionHas('error');
    }

    public function test_overdue_sections_show_cron_warning(): void
    {
        $this->running($this->exam(), $this->user(Role::Student), -5);

        $this->actingAs($this->user(Role::Proctor))->get(route('proctor.index'))->assertOk()->assertSee('schedule:run');
    }

    public function test_no_cron_warning_within_grace_period(): void
    {
        $this->running($this->exam(), $this->user(Role::Student), -1);

        $this->actingAs($this->user(Role::Proctor))->get(route('proctor.index'))->assertOk()->assertDontSee('schedule:run');
    }

    public function test_close_buttons_are_hidden_on_past_dates(): void
    {
        $this->exam();
        $proctor = $this->user(Role::Proctor);

        $this->actingAs($proctor)->get(route('proctor.index'))->assertSee('Tutup ujian');
        $this->actingAs($proctor)->get(route('proctor.index', ['date' => today()->subDay()->format('Y-m-d')]))
            ->assertOk()->assertDontSee('Tutup ujian');
    }

    public function test_kind_filter_separates_test_and_practice_exams(): void
    {
        $this->running($this->exam('internal'), $this->user(Role::Student, 'Siswa Latihan'));
        $this->running($this->exam('public'), $this->user(Role::TestTaker, 'Peserta Tes'));
        $this->running($this->exam('internal', pool: 'test'), $this->user(Role::Student, 'Siswa Ujian Kelas'));
        $proctor = $this->user(Role::Proctor);

        $this->actingAs($proctor)->get(route('proctor.index', ['jenis' => 'test']))->assertOk()
            ->assertSee('Peserta Tes')->assertSee('Siswa Ujian Kelas')->assertDontSee('Siswa Latihan');
        $this->actingAs($proctor)->get(route('proctor.index', ['jenis' => 'practice']))->assertOk()
            ->assertSee('Siswa Latihan')->assertDontSee('Peserta Tes')->assertDontSee('Siswa Ujian Kelas');
        $this->actingAs($proctor)->get(route('proctor.index'))->assertOk()
            ->assertSee('Siswa Latihan')->assertSee('Peserta Tes');
    }

    public function test_sensei_can_only_act_on_own_exams(): void
    {
        $sensei = $this->user(Role::Sensei);
        $own = $this->running($this->exam(sensei: $sensei), $this->user(Role::Student, 'Murid Sendiri'));
        $otherExam = $this->exam();
        $other = $this->running($otherExam, $this->user(Role::Student, 'Murid Lain'));

        $this->actingAs($sensei)->get(route('proctor.index'))->assertOk()
            ->assertSee('Murid Lain')->assertSee('Ujian sensei lain');

        $this->actingAs($sensei)->post(route('proctor.attempts.stop', $other))->assertForbidden();
        $this->actingAs($sensei)->post(route('proctor.attempts.extend', $other), ['minutes' => 5])->assertForbidden();
        $this->actingAs($sensei)->post(route('proctor.exams.close', $otherExam))->assertForbidden();
        $this->assertSame(AttemptStatus::Ongoing, $other->fresh()->status);

        $this->actingAs($sensei)->post(route('proctor.attempts.stop', $own))->assertSessionHas('success');
        $this->assertNotSame(AttemptStatus::Ongoing, $own->fresh()->status);
    }

    public function test_waiting_list_only_has_test_takers_and_students_on_exam_page(): void
    {
        $this->user(Role::TestTaker, 'Peserta Umum')->forceFill(['last_login_at' => now()])->save();
        $this->user(Role::Student, 'Siswa Baca Materi')->forceFill(['last_login_at' => now()])->save();
        $ready = $this->user(Role::Student, 'Siswa Siap');
        $ready->forceFill(['last_login_at' => now()])->save();

        $this->actingAs($ready)->get(route('student.exams'))->assertOk();
        $this->assertTrue($ready->fresh()->exam_page_seen_at->isToday());

        $this->actingAs($this->user(Role::Proctor))->get(route('proctor.index'))->assertOk()
            ->assertSee('Peserta Umum')->assertSee('Siswa Siap')->assertDontSee('Siswa Baca Materi');
    }

    public function test_data_endpoint_includes_alerts(): void
    {
        $this->running($this->exam(), $this->user(Role::Student), -5);

        $this->actingAs($this->user(Role::Proctor))->getJson(route('proctor.data'))
            ->assertOk()->assertJsonStructure(['rows', 'waiting', 'openExams', 'alerts', 'stats' => ['away']])
            ->assertJsonPath('stats.away', 0);
    }
}
