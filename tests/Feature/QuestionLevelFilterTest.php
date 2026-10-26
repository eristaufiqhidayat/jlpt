<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Enums\Role;
use App\Models\Level;
use App\Models\Question;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Filter "Jenis Ujian" di Pelatihan Soal: level dikelompokkan per jenis tes (JLPT → JLPT N5, ...). */
class QuestionLevelFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    private function sensei(): User
    {
        return User::factory()->create(['role' => Role::Sensei, 'email' => 'sensei@x.test', 'is_active' => true]);
    }

    private function questionIn(string $levelCode, string $stem): Question
    {
        $section = Section::where('code', 'moji_goi')->whereHas('level', fn ($q) => $q->where('code', $levelCode))->firstOrFail();
        $q = Question::create([
            'section_id' => $section->id, 'type' => 'blank', 'stem' => $stem,
            'status' => ContentStatus::Published, 'usage' => 'both', 'explanation' => 'Pembahasan',
        ]);
        foreach ([1, 2] as $label) {
            $q->options()->create(['label' => $label, 'content' => "pilihan {$label}", 'is_correct' => $label === 1]);
        }

        return $q;
    }

    public function test_filter_is_named_jenis_ujian_and_grouped_by_test_type(): void
    {
        $n5 = Level::where('code', 'N5')->whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->firstOrFail();

        $this->actingAs($this->sensei())->get(route('sensei.questions.index'))
            ->assertOk()
            ->assertSee('Jenis Ujian')
            ->assertSee('<optgroup label="JLPT">', false)
            ->assertSee('value="'.$n5->id.'"', false)
            ->assertSeeInOrder(['JLPT N5', 'JLPT N4', 'JLPT N3', 'JLPT N2', 'JLPT N1']);
    }

    public function test_filter_by_level_shows_only_that_level(): void
    {
        $this->questionIn('N5', 'soal level lima');
        $this->questionIn('N4', 'soal level empat');
        $n5 = Level::where('code', 'N5')->firstOrFail();

        $this->actingAs($this->sensei())->get(route('sensei.questions.index', ['level_id' => $n5->id]))
            ->assertOk()
            ->assertSee('soal level lima')
            ->assertDontSee('soal level empat');
    }
}
