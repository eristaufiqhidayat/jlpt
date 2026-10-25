<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Enums\Role;
use App\Models\Question;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pratinjau soal di Pelatihan Soal dengan tampilan ujian peserta. */
class QuestionPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $sensei;

    private Section $mojiGoi;

    private Section $bunpou;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->sensei = User::factory()->create(['role' => Role::Sensei, 'is_active' => true]);
        $n4 = fn ($code) => Section::where('code', $code)->whereHas('level', fn ($q) => $q->where('code', 'N4'))->firstOrFail();
        $this->mojiGoi = $n4('moji_goi');
        $this->bunpou = $n4('bunpou_dokkai');
    }

    private function question(Section $section, string $stem, string $type = 'blank', bool $withKey = true, array $extra = []): Question
    {
        $q = Question::create($extra + [
            'section_id' => $section->id, 'type' => $type, 'stem' => $stem, 'status' => ContentStatus::Published,
            'explanation' => "Pembahasan {$stem}",
        ]);
        if ($type !== 'essay') {
            foreach ([1 => 'あめ', 2 => 'ゆき'] as $label => $content) {
                $q->options()->create(['label' => $label, 'content' => $content, 'is_correct' => $withKey && $label === 2]);
            }
        }

        return $q;
    }

    public function test_sensei_sees_questions_in_exam_layout_with_key_and_explanation(): void
    {
        $this->question($this->mojiGoi, 'soal satu');

        $this->actingAs($this->sensei)->get(route('sensei.questions.preview'))
            ->assertOk()
            ->assertSee('Mode pratinjau')
            ->assertSee('Soal No. 1 dari 1')
            ->assertSee('soal satu')
            ->assertSee('あめ')->assertSee('ゆき')
            ->assertSee('Cek jawaban')
            ->assertSee('Kunci:')
            ->assertSee('Pembahasan soal satu')
            ->assertSee('Navigasi Soal');
    }

    public function test_preview_follows_list_filters(): void
    {
        $this->question($this->mojiGoi, 'soal moji');
        $this->question($this->bunpou, 'soal bunpou');

        $this->actingAs($this->sensei)->get(route('sensei.questions.preview', ['section_id' => $this->bunpou->id]))
            ->assertOk()->assertSee('soal bunpou')->assertDontSee('soal moji');
    }

    public function test_questions_are_ordered_like_an_exam(): void
    {
        $this->question($this->bunpou, 'soal bintang', 'star');
        $this->question($this->bunpou, 'soal isian', 'blank');

        $this->actingAs($this->sensei)->get(route('sensei.questions.preview', ['section_id' => $this->bunpou->id]))
            ->assertOk()->assertSeeInOrder(['soal isian', 'soal bintang']);
    }

    public function test_clicked_question_opens_first_on_screen(): void
    {
        $this->question($this->mojiGoi, 'pertama');
        $second = $this->question($this->mojiGoi, 'kedua');

        $response = $this->actingAs($this->sensei)->get(route('sensei.questions.preview', ['section_id' => $this->mojiGoi->id, 'question' => $second->id]));

        $response->assertOk()->assertViewHas('start', 1);
    }

    public function test_single_question_from_edit_form(): void
    {
        $this->question($this->mojiGoi, 'soal-lain-xyz');
        $q = $this->question($this->mojiGoi, 'yang diedit');

        $this->actingAs($this->sensei)->get(route('sensei.questions.preview', ['question' => $q->id]))
            ->assertOk()->assertSee('yang diedit')->assertDontSee('soal-lain-xyz')->assertSee('Soal No. 1 dari 1');
    }

    public function test_problems_are_flagged_for_sensei(): void
    {
        $this->question($this->mojiGoi, 'tanpa kunci', withKey: false);

        $this->actingAs($this->sensei)->get(route('sensei.questions.preview'))
            ->assertOk()->assertSee('belum ada kunci')->assertSee('Kunci belum diisi');
    }

    public function test_essay_shows_answer_reference(): void
    {
        $this->question($this->mojiGoi, 'tulis cara baca', 'essay', extra: ['answer_reference' => 'やま']);

        $this->actingAs($this->sensei)->get(route('sensei.questions.preview'))
            ->assertOk()->assertSee('Tulis jawaban di sini')->assertSee('Contoh jawaban')->assertSee('やま');
    }

    public function test_empty_filter_shows_message(): void
    {
        $this->actingAs($this->sensei)->get(route('sensei.questions.preview', ['q' => 'tidak-ada']))
            ->assertOk()->assertSee('Tidak ada soal');
    }

    public function test_list_and_edit_form_link_to_preview(): void
    {
        $q = $this->question($this->mojiGoi, 'ada tautan');

        $this->actingAs($this->sensei)->get(route('sensei.questions.index'))
            ->assertOk()->assertSee('Pratinjau seperti peserta')
            ->assertSee(route('sensei.questions.preview', ['question' => $q->id]), false);
        $this->actingAs($this->sensei)->get(route('sensei.questions.edit', $q))
            ->assertOk()->assertSee(route('sensei.questions.preview', ['question' => $q->id]), false);
    }

    public function test_students_cannot_open_preview(): void
    {
        $this->question($this->mojiGoi, 'rahasia');
        $student = User::factory()->create(['role' => Role::Student, 'is_active' => true]);

        $this->actingAs($student)->get(route('sensei.questions.preview'))->assertForbidden();
    }
}
