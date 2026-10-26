<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Level;
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
}
