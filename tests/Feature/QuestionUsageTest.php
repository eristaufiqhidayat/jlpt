<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Enums\ExamStatus;
use App\Enums\Role;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\Section;
use App\Models\User;
use App\Services\ExamAttemptService;
use App\Services\QuestionImporter;
use App\Services\QuestionPicker;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Penggunaan soal (latihan / tes online / keduanya) dan sumber soal ujian. */
class QuestionUsageTest extends TestCase
{
    use RefreshDatabase;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->section = Section::where('code', 'moji_goi')->whereHas('level', fn ($q) => $q->where('code', 'N4'))->firstOrFail();
    }

    private function question(string $usage, string $stem): Question
    {
        $q = Question::create([
            'section_id' => $this->section->id, 'type' => 'blank', 'stem' => $stem,
            'status' => ContentStatus::Published, 'usage' => $usage, 'explanation' => "Pembahasan {$stem}",
        ]);
        foreach ([1, 2] as $label) {
            $q->options()->create(['label' => $label, 'content' => "pilihan {$label}", 'is_correct' => $label === 1]);
        }

        return $q;
    }

    private function exam(string $audience, ?string $pool = null): Exam
    {
        $exam = Exam::create([
            'sensei_id' => User::factory()->create(['role' => Role::Sensei])->id, 'level_id' => $this->section->level_id,
            'title' => 'Uji', 'status' => ExamStatus::Open, 'audience' => $audience, 'question_pool' => $pool,
            'is_paid' => false, 'price' => 0, 'show_result_immediately' => true,
        ]);
        ExamSection::create(['exam_id' => $exam->id, 'section_id' => $this->section->id, 'sort_order' => 1, 'duration_minutes' => 30, 'question_count' => 10]);

        return $exam;
    }

    private function seedBank(): void
    {
        $this->question('practice', 'soal latihan');
        $this->question('test', 'soal tes');
        $this->question('both', 'soal keduanya');
    }

    public function test_new_questions_default_to_practice(): void
    {
        $q = Question::create(['section_id' => $this->section->id, 'type' => 'blank', 'stem' => 'x', 'status' => ContentStatus::Draft]);

        $this->assertSame('practice', $q->fresh()->usage);
    }

    public function test_picker_only_takes_questions_from_the_requested_pool(): void
    {
        $this->seedBank();
        $picker = app(QuestionPicker::class);

        $this->assertEqualsCanonicalizing(['soal latihan', 'soal keduanya'], $picker->pick($this->section->id, 10, 'practice')->pluck('stem')->all());
        $this->assertEqualsCanonicalizing(['soal tes', 'soal keduanya'], $picker->pick($this->section->id, 10, 'test')->pluck('stem')->all());
    }

    public function test_pool_follows_audience_unless_chosen(): void
    {
        $this->assertSame('practice', $this->exam('internal')->questionPool());
        $this->assertSame('test', $this->exam('public')->questionPool());
        $this->assertSame('test', $this->exam('both')->questionPool());
        $this->assertSame('test', $this->exam('internal', 'test')->questionPool());
        $this->assertSame('practice', $this->exam('public', 'practice')->questionPool());
    }

    public function test_public_exam_uses_test_questions_and_hides_review(): void
    {
        $this->seedBank();
        $exam = $this->exam('public');
        $taker = User::factory()->create(['role' => Role::TestTaker, 'is_active' => true]);

        $attempt = app(ExamAttemptService::class)->start($taker, $exam);
        $stems = $attempt->sections->first()->questions()->with('question')->get()->pluck('question.stem')->all();
        $this->assertEqualsCanonicalizing(['soal tes', 'soal keduanya'], $stems);

        app(ExamAttemptService::class)->submitSection($attempt->sections->first());
        $this->assertFalse($exam->fresh()->showsReview());
        $this->actingAs($taker)->get(route('student.attempts.review', $attempt))
            ->assertRedirect(route('student.attempts.result', $attempt));
        $this->actingAs($taker)->get(route('student.attempts.result', $attempt))
            ->assertOk()->assertDontSee('Lihat Pembahasan');
    }

    public function test_internal_exam_uses_practice_questions_and_shows_review(): void
    {
        $this->seedBank();
        $exam = $this->exam('internal');
        $student = User::factory()->create(['role' => Role::Student, 'is_active' => true]);

        $attempt = app(ExamAttemptService::class)->start($student, $exam);
        app(ExamAttemptService::class)->submitSection($attempt->sections->first());

        $this->actingAs($student)->get(route('student.attempts.review', $attempt))
            ->assertOk()->assertSee('soal latihan')->assertSee('Pembahasan soal keduanya')->assertDontSee('soal tes');
    }

    public function test_question_switched_to_test_is_hidden_in_old_review(): void
    {
        $this->question('practice', 'soal rahasia');
        $exam = $this->exam('internal');
        $student = User::factory()->create(['role' => Role::Student, 'is_active' => true]);
        $attempt = app(ExamAttemptService::class)->start($student, $exam);
        app(ExamAttemptService::class)->submitSection($attempt->sections->first());

        Question::where('stem', 'soal rahasia')->update(['usage' => 'test']);

        $this->actingAs($student)->get(route('student.attempts.review', $attempt))
            ->assertOk()->assertDontSee('soal rahasia')->assertDontSee('Pembahasan soal rahasia')
            ->assertSee('dipakai untuk tes online');
    }

    public function test_question_form_saves_usage_and_list_filters_by_it(): void
    {
        $sensei = User::factory()->create(['role' => Role::Sensei]);
        $this->actingAs($sensei)->post(route('sensei.questions.store'), [
            'section_id' => $this->section->id, 'type' => 'blank', 'stem' => 'soal baru', 'status' => 'published', 'usage' => 'test',
            'options' => [1 => ['content' => 'a'], 2 => ['content' => 'b']], 'correct' => 1,
        ])->assertSessionHasNoErrors();
        $this->question('practice', 'soal lain');

        $this->assertSame('test', Question::where('stem', 'soal baru')->value('usage'));
        $this->actingAs($sensei)->get(route('sensei.questions.index', ['usage' => 'test']))
            ->assertOk()->assertSee('soal baru')->assertDontSee('soal lain');
    }

    public function test_import_reads_penggunaan_column(): void
    {
        $parsed = app(QuestionImporter::class)->parse([
            ['level', 'bagian', 'soal', 'pilihan_1', 'pilihan_2', 'jawaban', 'Penggunaan'],
            ['N4', 'Moji Goi', 'satu', 'a', 'b', '1', 'tes'],
            ['N4', 'Moji Goi', 'dua', 'a', 'b', '1', 'Keduanya'],
            ['N4', 'Moji Goi', 'tiga', 'a', 'b', '1', ''],
            ['N4', 'Moji Goi', 'empat', 'a', 'b', '1', 'rahasia'],
        ]);

        $this->assertSame(['test', 'both', 'practice'], array_map(fn ($r) => $r['data']['usage'], array_slice($parsed['rows'], 0, 3)));
        $this->assertSame(1, $parsed['invalid']);

        app(QuestionImporter::class)->import($parsed['rows'], User::factory()->create(['role' => Role::Sensei])->id);
        $this->assertSame(['satu' => 'test', 'dua' => 'both', 'tiga' => 'practice'], Question::orderBy('id')->pluck('usage', 'stem')->all());
    }

    public function test_import_without_penggunaan_column_defaults_to_practice(): void
    {
        $parsed = app(QuestionImporter::class)->parse([
            ['level', 'bagian', 'soal', 'pilihan_1', 'pilihan_2', 'jawaban'],
            ['N4', 'Moji Goi', 'lama', 'a', 'b', '1'],
        ]);

        $this->assertSame('practice', $parsed['rows'][0]['data']['usage']);
        $this->assertSame([], $parsed['header_warnings']);
    }
}
