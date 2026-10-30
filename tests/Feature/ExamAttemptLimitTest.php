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

/** Batas pengerjaan per peserta (exams.max_attempts). */
class ExamAttemptLimitTest extends TestCase
{
    use RefreshDatabase;

    private const TEST = 'http://testonline.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['portals.lms_host' => 'jlpt.test', 'portals.test_host' => 'testonline.test']);
        $this->seed(MasterDataSeeder::class);
    }

    private function user(Role $role, string $email): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'is_active' => true]);
    }

    private function exam(?int $maxAttempts): Exam
    {
        $level = Level::whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->whereHas('sections')->firstOrFail();
        $exam = Exam::create([
            'sensei_id' => $this->user(Role::Sensei, 'sensei-'.uniqid().'@x.test')->id, 'level_id' => $level->id,
            'title' => 'Tes '.$level->code, 'status' => ExamStatus::Open, 'audience' => 'public', 'is_paid' => false, 'price' => 0,
            'show_result_immediately' => true, 'max_attempts' => $maxAttempts,
        ]);
        $section = $level->sections()->orderBy('sort_order')->firstOrFail();
        ExamSection::create(['exam_id' => $exam->id, 'section_id' => $section->id, 'sort_order' => 1, 'duration_minutes' => 30, 'question_count' => 5]);

        return $exam->fresh('sections');
    }

    private function attempt(Exam $exam, User $student, AttemptStatus $status): ExamAttempt
    {
        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id, 'student_id' => $student->id, 'status' => $status,
            'started_at' => now()->subHour(), 'finished_at' => $status === AttemptStatus::Ongoing ? null : now()->subMinutes(10),
            'correct_count' => 3, 'question_count' => 5, 'score' => $status === AttemptStatus::Ongoing ? null : 60,
        ]);
        AttemptSection::create([
            'exam_attempt_id' => $attempt->id, 'exam_section_id' => $exam->sections->first()->id, 'sort_order' => 1, 'duration_minutes' => 30,
            'started_at' => now()->subMinutes(20), 'deadline_at' => now()->addMinutes(10),
            'submitted_at' => $status === AttemptStatus::Ongoing ? null : now()->subMinutes(10),
        ]);

        return $attempt;
    }

    public function test_limit_reached_hides_retake_button_and_rejects_code(): void
    {
        $student = $this->user(Role::TestTaker, 'peserta@x.test');
        $exam = $this->exam(1);
        $attempt = $this->attempt($exam, $student, AttemptStatus::Finished);

        $this->actingAs($student)->get(self::TEST.'/attempts/'.$attempt->id.'/result')
            ->assertOk()->assertDontSee('Ulangi Ujian')->assertSee('Ke beranda');

        $this->actingAs($student)->from(self::TEST.'/ujian')->post(self::TEST.'/exam/join', ['code' => $exam->code])
            ->assertRedirect(self::TEST.'/ujian')
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'batas pengerjaan'));

        $this->assertSame(1, ExamAttempt::where('exam_id', $exam->id)->count());
    }

    public function test_retake_button_shows_remaining_attempts(): void
    {
        $student = $this->user(Role::TestTaker, 'peserta@x.test');
        $exam = $this->exam(2);
        $attempt = $this->attempt($exam, $student, AttemptStatus::Finished);

        $this->actingAs($student)->get(self::TEST.'/attempts/'.$attempt->id.'/result')
            ->assertOk()->assertSee('Ulangi Ujian')->assertSee('sisa 1 kali');
    }

    public function test_unlimited_exam_keeps_old_behaviour(): void
    {
        $student = $this->user(Role::TestTaker, 'peserta@x.test');
        $exam = $this->exam(null);
        $attempt = $this->attempt($exam, $student, AttemptStatus::Finished);
        $this->attempt($exam, $student, AttemptStatus::Finished);

        $this->actingAs($student)->get(self::TEST.'/attempts/'.$attempt->id.'/result')
            ->assertOk()->assertSee('Ulangi Ujian')->assertDontSee('sisa');
        $this->assertNull($exam->remainingAttemptsFor($student));
    }

    public function test_ongoing_attempt_can_still_be_resumed_at_the_limit(): void
    {
        $student = $this->user(Role::TestTaker, 'peserta@x.test');
        $exam = $this->exam(1);
        $ongoing = $this->attempt($exam, $student, AttemptStatus::Ongoing);

        $this->actingAs($student)->post(self::TEST.'/exam/join', ['code' => $exam->code])
            ->assertRedirect(self::TEST.'/attempts/'.$ongoing->id);
    }

    public function test_limit_is_per_participant(): void
    {
        $exam = $this->exam(1);
        $this->attempt($exam, $this->user(Role::TestTaker, 'a@x.test'), AttemptStatus::Finished);

        $this->assertSame(1, $exam->remainingAttemptsFor($this->user(Role::TestTaker, 'b@x.test')));
    }

    public function test_sensei_saves_max_attempts(): void
    {
        $sensei = $this->user(Role::Sensei, 'sensei@x.test');
        $level = Level::whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->whereHas('sections')->firstOrFail();
        $section = $level->sections()->orderBy('sort_order')->firstOrFail();

        $this->actingAs($sensei)->get(route('sensei.exams.create', ['level_id' => $level->id]))
            ->assertOk()->assertSee('Batas pengerjaan per peserta')->assertSee('1 kali (tidak bisa diulang)');

        $this->actingAs($sensei)->post(route('sensei.exams.store'), [
            'title' => 'Tes resmi', 'level_id' => $level->id, 'status' => 'draft', 'audience' => 'public', 'max_attempts' => 1,
            'sections' => [$section->id => ['enabled' => 1, 'duration' => 20, 'count' => 5, 'order' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Exam::where('title', 'Tes resmi')->value('max_attempts'));
    }

    public function test_new_exam_form_defaults_to_one_attempt(): void
    {
        $level = Level::whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->whereHas('sections')->firstOrFail();

        $html = $this->actingAs($this->user(Role::Sensei, 'sensei@x.test'))
            ->get(route('sensei.exams.create', ['level_id' => $level->id]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="1"\s+selected\s*>1 kali \(tidak bisa diulang\)<\/option>/', $this->maxAttemptsSelect($html));
    }

    public function test_editing_old_unlimited_exam_keeps_unlimited(): void
    {
        $exam = $this->exam(null);
        $html = $this->actingAs(User::find($exam->sensei_id))->get(route('sensei.exams.edit', $exam))->assertOk()->getContent();

        $select = $this->maxAttemptsSelect($html);
        $this->assertDoesNotMatchRegularExpression('/<option value="\d+"\s+selected/', $select);
    }

    private function maxAttemptsSelect(string $html): string
    {
        $this->assertSame(1, preg_match('/<select id="max_attempts".*?<\/select>/s', $html, $m), 'dropdown batas pengerjaan tidak ditemukan');

        return $m[0];
    }
}
