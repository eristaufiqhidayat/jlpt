<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ContentStatus;
use App\Models\Level;
use App\Models\Question;
use App\Models\Section;
use App\Models\TestType;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Jenis Ujian" di form Ujian baru sama dengan filter di Pelatihan Soal (JLPT → N5, ...; hanya yang aktif). */
class ExamJenisUjianTest extends TestCase
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

    public function test_new_exam_uses_jenis_ujian_dropdown_grouped_by_test_type(): void
    {
        $this->actingAs($this->sensei())->get(route('sensei.exams.create'))
            ->assertOk()
            ->assertSee('Jenis Ujian')
            ->assertDontSee('Level ujian')
            ->assertSee('<optgroup label="JLPT">', false)
            ->assertSeeInOrder(['<optgroup label="JLPT">', '>N5</option>', '>N4</option>', '>N3</option>', '>N2</option>', '>N1</option>'], false)
            // Seeder: J.TEST belum aktif.
            ->assertDontSee('<optgroup label="J.TEST">', false);
    }

    public function test_active_level_without_sections_shows_notice_instead_of_form(): void
    {
        TestType::where('code', 'JTEST')->update(['is_active' => true]);
        $fg = Level::where('code', 'F-G')->firstOrFail();

        $this->actingAs($this->sensei())->get(route('sensei.exams.create', ['level_id' => $fg->id]))
            ->assertOk()
            ->assertSee('<optgroup label="J.TEST">', false)
            ->assertSee('belum punya bagian soal')
            ->assertDontSee('name="title"', false);
    }

    public function test_new_exam_rejects_level_of_inactive_test_type(): void
    {
        $level = Level::where('code', 'F-G')->firstOrFail(); // J.TEST nonaktif

        $this->actingAs($this->sensei())->post(route('sensei.exams.store'), [
            'title' => 'Coba', 'level_id' => $level->id, 'status' => 'draft', 'sections' => [1 => ['enabled' => 0]],
        ])->assertSessionHasErrors('level_id');
    }

    private function jlptN5Sections(): array
    {
        $level = Level::where('code', 'N5')->whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->firstOrFail();

        return [$level, Section::where('level_id', $level->id)->where('code', 'moji_goi')->firstOrFail(),
            Section::where('level_id', $level->id)->where('code', 'choukai')->firstOrFail()];
    }

    public function test_form_exposes_counts_per_pool_for_live_update(): void
    {
        [$level, $moji] = $this->jlptN5Sections();
        Question::create(['section_id' => $moji->id, 'type' => 'blank', 'stem' => 'tes', 'status' => ContentStatus::Published, 'usage' => 'test']);

        $html = $this->actingAs($this->sensei())->get(route('sensei.exams.create', ['level_id' => $level->id]))
            ->assertOk()
            ->assertSee('data-practice="0" data-test="1"', false)
            ->assertSee('id="pool-effective"', false)
            ->getContent();

        // Default "Siswa LPK" memakai soal latihan: jumlah soal tes disembunyikan sampai sumber soal diganti.
        $this->assertMatchesRegularExpression('/<strong data-pool="test"\s+hidden\s*>1 soal tes online<\/strong>/', $html);
        $this->assertMatchesRegularExpression('/<strong data-pool="practice"\s*>0 soal latihan<\/strong>/', $html);
    }

    public function test_saving_warns_about_sections_without_questions_for_chosen_pool(): void
    {
        [$level, $moji, $choukai] = $this->jlptN5Sections();
        // Soal hanya untuk tes online; Choukai tidak punya soal sama sekali.
        Question::create(['section_id' => $moji->id, 'type' => 'blank', 'stem' => 'tes', 'status' => ContentStatus::Published, 'usage' => 'test']);

        $this->actingAs($this->sensei())->post(route('sensei.exams.store'), [
            'title' => 'Tes umum', 'level_id' => $level->id, 'status' => 'draft', 'audience' => 'public',
            'sections' => [
                $moji->id => ['enabled' => 1, 'duration' => 20, 'count' => 5, 'order' => 1],
                $choukai->id => ['enabled' => 1, 'duration' => 30, 'count' => 5, 'order' => 2],
            ],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($msg) => str_contains($msg, $choukai->name) && ! str_contains($msg, $moji->name));
    }

    public function test_no_warning_when_every_section_has_questions(): void
    {
        [$level, $moji] = $this->jlptN5Sections();
        Question::create(['section_id' => $moji->id, 'type' => 'blank', 'stem' => 'tes', 'status' => ContentStatus::Published, 'usage' => 'test']);

        $this->actingAs($this->sensei())->post(route('sensei.exams.store'), [
            'title' => 'Tes umum', 'level_id' => $level->id, 'status' => 'draft', 'audience' => 'public',
            'sections' => [$moji->id => ['enabled' => 1, 'duration' => 20, 'count' => 5, 'order' => 1]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('warning');
    }
}
