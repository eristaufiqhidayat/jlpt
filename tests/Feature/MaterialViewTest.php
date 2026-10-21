<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Halaman "Lihat" materi untuk staf: sensei memakai materi untuk mengajar tanpa bisa mengubah/menghapus. */
class MaterialViewTest extends TestCase
{
    use RefreshDatabase;

    private Curriculum $minna1;

    private Curriculum $minna2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->minna1 = Curriculum::where('code', 'MINNA1')->first();
        $this->minna2 = Curriculum::where('code', 'MINNA2')->first();
    }

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function core(Curriculum $curriculum, string $title): Material
    {
        $material = Material::create([
            'kind' => Material::CORE, 'curriculum_id' => $curriculum->id, 'chapter_no' => $curriculum->chapter_from,
            'title' => $title, 'description' => 'Ringkasan bab', 'status' => 'published',
        ]);
        $material->files()->create(['type' => 'pdf', 'path' => "materials/{$material->id}/a.pdf", 'original_name' => 'Modul Bab 1.pdf', 'size' => 10, 'sort_order' => 1]);

        return $material;
    }

    private function extra(User $sensei, string $title): Material
    {
        $class = Classroom::create(['name' => 'Kelas '.uniqid(), 'curriculum_id' => $this->minna1->id, 'sensei_id' => $sensei->id, 'is_active' => true]);

        return Material::create([
            'kind' => Material::EXTRA, 'classroom_id' => $class->id, 'created_by' => $sensei->id,
            'title' => $title, 'description' => 'isi', 'status' => 'published',
        ]);
    }

    public function test_sensei_can_view_any_core_material_without_lock_but_not_edit_or_delete(): void
    {
        $sensei = $this->user(Role::Sensei);
        $m2 = $this->core($this->minna2, 'Materi Minna Dua'); // kurikulum yang tidak diajar sensei ini

        $this->actingAs($sensei)->get(route('manage.materials.show', $m2))->assertOk()
            ->assertSee('Materi Minna Dua')->assertSee('Modul Bab 1.pdf')->assertSee('Ringkasan bab')
            ->assertSee('Mode lihat')->assertDontSee(route('manage.materials.edit', $m2));

        $this->actingAs($sensei)->get(route('manage.materials.edit', $m2))->assertForbidden();
        $this->actingAs($sensei)->delete(route('manage.materials.destroy', $m2))->assertForbidden();
        $this->assertModelExists($m2);
    }

    public function test_material_list_shows_view_button_and_hides_edit_for_sensei(): void
    {
        $sensei = $this->user(Role::Sensei);
        $m1 = $this->core($this->minna1, 'Materi Minna Satu');

        $this->actingAs($sensei)->get(route('manage.materials.index', ['tab' => $this->minna1->id]))->assertOk()
            ->assertSee(route('manage.materials.show', $m1))
            ->assertDontSee(route('manage.materials.edit', $m1))
            ->assertDontSee('Hapus materi ini'); // form hapus (URL-nya sama dengan Lihat, beda method)
    }

    public function test_education_admin_sees_edit_button_on_view_page(): void
    {
        $m1 = $this->core($this->minna1, 'Materi Minna Satu');

        $this->actingAs($this->user(Role::EducationAdmin))->get(route('manage.materials.show', $m1))->assertOk()
            ->assertSee(route('manage.materials.edit', $m1))->assertDontSee('Mode lihat');
    }

    public function test_sensei_can_view_own_extra_but_not_other_class_extra(): void
    {
        $sensei = $this->user(Role::Sensei);
        $other = $this->user(Role::Sensei);
        $mine = $this->extra($sensei, 'Latihan tambahan saya');
        $theirs = $this->extra($other, 'Latihan kelas lain');

        $this->actingAs($sensei)->get(route('manage.materials.show', $mine))->assertOk()
            ->assertSee(route('manage.materials.edit', $mine));
        $this->actingAs($sensei)->get(route('manage.materials.show', $theirs))->assertForbidden();
    }

    public function test_admin_can_view_and_student_cannot(): void
    {
        $m1 = $this->core($this->minna1, 'Materi Minna Satu');

        $this->actingAs($this->user(Role::Admin))->get(route('manage.materials.show', $m1))->assertOk();
        $this->actingAs($this->user(Role::Student))->get(route('manage.materials.show', $m1))->assertForbidden();
    }

    public function test_material_tabs_are_grouped_by_curriculum_group(): void
    {
        $this->core($this->minna1, 'Materi Minna Satu');

        $this->actingAs($this->user(Role::EducationAdmin))->get(route('manage.materials.index', ['tab' => $this->minna1->id]))->assertOk()
            ->assertSee('data-tab-group', false)
            ->assertSeeInOrder(['Kelas Basic', 'Minna no Nihongo I', 'Minna no Nihongo II', 'Kelas SSW', 'SSW · Kaigo', 'Materi umum'])
            ->assertSee(route('manage.materials.index', ['tab' => $this->minna2->id, 'sort' => 'bab']), false);
    }
}
