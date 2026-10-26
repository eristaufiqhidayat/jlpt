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

/** Form soal: "Jenis Ujian" sama dengan Pelatihan Soal, "Bagian" mengikuti jenis ujian yang dipilih. */
class QuestionFormJenisUjianTest extends TestCase
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

    private function jlpt(string $code): Level
    {
        return Level::where('code', $code)->whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->firstOrFail();
    }

    public function test_create_form_shows_jenis_ujian_and_sections_of_selected_level_only(): void
    {
        $n4 = $this->jlpt('N4');
        $n5MojiGoi = Section::where('level_id', $this->jlpt('N5')->id)->where('code', 'moji_goi')->firstOrFail();
        $n4MojiGoi = Section::where('level_id', $n4->id)->where('code', 'moji_goi')->firstOrFail();

        $response = $this->actingAs($this->sensei())->get(route('sensei.questions.create', ['level_id' => $n4->id]))
            ->assertOk()
            ->assertSee('Jenis Ujian')
            ->assertSee('<optgroup label="JLPT">', false)
            ->assertSeeInOrder(['<optgroup label="JLPT">', '>N5</option>', '>N4</option>'], false)
            ->assertSee('<option value="'.$n4->id.'" selected>N4</option>', false)
            ->assertDontSee('JLPT N4 · ', false); // format lama "JLPT N4 · Moji Goi"

        // Cek isi dropdown "Bagian" saja (id bagian bisa sama dengan id level di dropdown Jenis Ujian).
        $sectionSelect = $this->selectHtml($response->getContent(), 'section_id');
        $this->assertStringContainsString('<option value="'.$n4MojiGoi->id.'"', $sectionSelect);
        $this->assertStringNotContainsString('<option value="'.$n5MojiGoi->id.'"', $sectionSelect);
    }

    /** Potongan HTML satu <select id="..."> sampai </select>. */
    private function selectHtml(string $html, string $id): string
    {
        $start = strpos($html, '<select id="'.$id.'"');
        $this->assertNotFalse($start, "select #{$id} tidak ditemukan");

        return substr($html, $start, strpos($html, '</select>', $start) - $start);
    }

    public function test_edit_form_preselects_level_and_section_of_question(): void
    {
        $section = Section::where('level_id', $this->jlpt('N3')->id)->where('code', 'choukai')->firstOrFail();
        $question = Question::create([
            'section_id' => $section->id, 'type' => 'listening', 'stem' => 'soal n3',
            'status' => ContentStatus::Draft, 'usage' => 'practice',
        ]);

        $response = $this->actingAs($this->sensei())->get(route('sensei.questions.edit', $question))
            ->assertOk()
            ->assertSee('<option value="'.$section->level_id.'" selected>N3</option>', false);

        $this->assertStringContainsString('<option value="'.$section->id.'" selected>', $this->selectHtml($response->getContent(), 'section_id'));
    }

    public function test_question_still_saves_with_section(): void
    {
        $section = Section::where('level_id', $this->jlpt('N5')->id)->where('code', 'moji_goi')->firstOrFail();

        $this->actingAs($this->sensei())->post(route('sensei.questions.store'), [
            'level_id' => $section->level_id, 'section_id' => $section->id,
            'type' => 'blank', 'stem' => 'soal baru n5', 'status' => 'draft',
            'options' => [1 => ['content' => 'a'], 2 => ['content' => 'b']], 'correct' => 1,
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('sensei.questions.index', ['level_id' => $section->level_id]));

        $this->assertSame($section->id, Question::where('stem', 'soal baru n5')->value('section_id'));
    }
}
