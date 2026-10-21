<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\Level;
use App\Models\Material;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kurikulum "terbuka untuk semua" (mis. Materi Umum) diatur di Data Master dan tidak ikut lock. */
class OpenCurriculumTest extends TestCase
{
    use RefreshDatabase;

    private Curriculum $umum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->umum = Curriculum::where('code', 'UMUM')->first();
    }

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function material(Curriculum $c, string $title, array $extra = []): Material
    {
        return Material::create($extra + ['kind' => Material::CORE, 'curriculum_id' => $c->id, 'title' => $title, 'description' => 'isi', 'status' => 'published']);
    }

    public function test_materi_umum_exists_as_open_curriculum(): void
    {
        $this->assertTrue($this->umum->is_open);
        $this->actingAs($this->user(Role::EducationAdmin))->get(route('manage.master.index', ['tab' => 'kelas']))->assertOk()
            ->assertSee('Materi Umum')->assertSee('Terbuka untuk semua');
    }

    public function test_open_curriculum_is_visible_to_students_without_class_but_locked_one_is_not(): void
    {
        $open = $this->material($this->umum, 'Hiragana dasar');
        $locked = $this->material(Curriculum::where('code', 'MINNA2')->first(), 'Minna dua');
        $student = $this->user(Role::Student);

        $this->actingAs($student)->get(route('student.materials.show', $open))->assertOk();
        $this->actingAs($student)->get(route('student.materials.show', $locked))->assertForbidden();
        $this->actingAs($student)->get(route('student.materials', ['tab' => 'UMUM']))->assertOk()->assertSee('Hiragana dasar');
    }

    public function test_open_curriculum_materials_follow_student_level(): void
    {
        $n4 = Level::where('code', 'N4')->first();
        $n5 = Level::where('code', 'N5')->first();
        $student = $this->user(Role::Student);
        StudentProfile::create(['user_id' => $student->id, 'level_id' => $n4->id]);
        $forN5 = $this->material($this->umum, 'Khusus N5', ['level_id' => $n5->id]);
        $forAll = $this->material($this->umum, 'Untuk semua level');

        $this->actingAs($student)->get(route('student.materials.show', $forN5))->assertForbidden();
        $this->actingAs($student)->get(route('student.materials.show', $forAll))->assertOk();
    }

    public function test_any_curriculum_can_be_made_open_from_master_data(): void
    {
        $edu = $this->user(Role::EducationAdmin);
        $n3 = Curriculum::where('code', 'N3')->first();
        $m = $this->material($n3, 'Materi N3');
        $student = $this->user(Role::Student);
        Classroom::create(['name' => 'Minna I-A', 'curriculum_id' => Curriculum::where('code', 'MINNA1')->first()->id, 'is_active' => true])->students()->attach($student->id);

        $this->actingAs($student)->get(route('student.materials.show', $m))->assertForbidden();

        $this->actingAs($edu)->put(route('manage.master.update', ['curriculum', $n3->id]), [
            'group' => $n3->group, 'name' => $n3->name, 'code' => $n3->code, 'sort_order' => $n3->sort_order, 'is_open' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($n3->fresh()->is_open);

        $this->actingAs($student)->get(route('student.materials.show', $m))->assertOk();
    }

    public function test_core_material_without_curriculum_goes_to_materi_umum(): void
    {
        $this->actingAs($this->user(Role::EducationAdmin))->post(route('manage.materials.store'), [
            'kind' => 'core', 'title' => 'Salam dasar', 'description' => 'isi', 'status' => 'published',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->umum->id, Material::where('title', 'Salam dasar')->value('curriculum_id'));
    }
}
