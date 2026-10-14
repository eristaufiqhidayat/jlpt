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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialLockTest extends TestCase
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

    private function classroom(?Curriculum $curriculum, ?User $sensei = null, string $name = 'Kelas'): Classroom
    {
        return Classroom::create(['name' => $name.' '.uniqid(), 'curriculum_id' => $curriculum?->id, 'sensei_id' => $sensei?->id, 'is_active' => true]);
    }

    private function studentIn(?Curriculum $curriculum): User
    {
        $student = $this->user(Role::Student);
        $this->classroom($curriculum)->students()->attach($student->id);

        return $student;
    }

    private function core(?Curriculum $curriculum, string $title, array $extra = []): Material
    {
        return Material::create($extra + [
            'kind' => Material::CORE, 'curriculum_id' => $curriculum?->id, 'chapter_no' => $curriculum?->chapter_from,
            'title' => $title, 'description' => 'isi', 'status' => 'published',
        ]);
    }

    // ---------------- Lock untuk peserta ----------------

    public function test_student_sees_own_curriculum_and_next_one_is_locked(): void
    {
        $m1 = $this->core($this->minna1, 'Materi Minna Satu');
        $m2 = $this->core($this->minna2, 'Materi Minna Dua');
        $student = $this->studentIn($this->minna1);

        $this->actingAs($student)->get(route('student.materials'))->assertOk()
            ->assertSee('Materi Minna Satu')->assertDontSee('Materi Minna Dua')->assertSee('🔒 Minna no Nihongo II');
        $this->actingAs($student)->get(route('student.materials', ['tab' => 'MINNA2']))->assertOk()
            ->assertSee('belum terbuka')->assertDontSee('Materi Minna Dua');

        $this->actingAs($student)->get(route('student.materials.show', $m1))->assertOk();
        $this->actingAs($student)->get(route('student.materials.show', $m2))->assertForbidden();
        $this->actingAs($student)->post(route('student.materials.complete', $m2))->assertForbidden();
        $this->assertSame(0, $student->completedMaterials()->count());
    }

    public function test_moving_up_a_class_unlocks_next_curriculum_and_keeps_previous(): void
    {
        $m1 = $this->core($this->minna1, 'Materi Minna Satu');
        $m2 = $this->core($this->minna2, 'Materi Minna Dua');
        $student = $this->studentIn($this->minna1);
        $this->classroom($this->minna2)->students()->attach($student->id);

        $this->actingAs($student)->get(route('student.materials.show', $m1))->assertOk();
        $this->actingAs($student)->get(route('student.materials.show', $m2))->assertOk();
        $this->actingAs($student)->get(route('student.materials'))->assertOk()->assertSee('Materi Minna Dua');
    }

    public function test_student_in_minna_two_can_still_revisit_minna_one(): void
    {
        $m1 = $this->core($this->minna1, 'Materi Minna Satu');
        $student = $this->studentIn($this->minna2);

        $this->actingAs($student)->get(route('student.materials.show', $m1))->assertOk();
    }

    public function test_locked_groups_are_shown_as_one_tab(): void
    {
        $student = $this->studentIn($this->minna1);

        $this->actingAs($student)->get(route('student.materials'))->assertOk()
            ->assertSee('🔒 SSW')->assertDontSee('SSW · Kaigo');
    }

    public function test_student_without_class_only_sees_general_materials(): void
    {
        $general = $this->core(null, 'Materi Umum Hiragana');
        $m1 = $this->core($this->minna1, 'Materi Minna Satu');
        $student = $this->user(Role::Student);

        $this->actingAs($student)->get(route('student.materials', ['tab' => 'umum']))->assertOk()
            ->assertSee('Materi Umum Hiragana')->assertSee('belum terdaftar di kelas');
        $this->actingAs($student)->get(route('student.materials.show', $general))->assertOk();
        $this->actingAs($student)->get(route('student.materials.show', $m1))->assertForbidden();
    }

    public function test_general_materials_follow_profile_level_and_ignore_url_parameter(): void
    {
        $n4 = Level::where('code', 'N4')->first();
        $n5 = Level::where('code', 'N5')->first();
        $student = $this->user(Role::Student);
        StudentProfile::create(['user_id' => $student->id, 'level_id' => $n4->id]);
        $other = $this->core(null, 'Materi Khusus N5', ['level_id' => $n5->id]);

        $this->actingAs($student)->get(route('student.materials', ['level_id' => $n5->id, 'tab' => 'umum']))->assertDontSee('Materi Khusus N5');
        $this->actingAs($student)->get(route('student.materials.show', $other))->assertForbidden();
    }

    public function test_draft_material_is_hidden(): void
    {
        $draft = $this->core($this->minna1, 'Materi Draft', ['status' => 'draft']);
        $student = $this->studentIn($this->minna1);

        $this->actingAs($student)->get(route('student.materials.show', $draft))->assertForbidden();
    }

    public function test_material_files_follow_the_lock(): void
    {
        Storage::fake('local');
        $m2 = $this->core($this->minna2, 'Materi Minna Dua');
        Storage::disk('local')->put("materials/{$m2->id}/bab26.pdf", '%PDF-1.4');
        $m2->update(['pdf_path' => "materials/{$m2->id}/bab26.pdf"]);

        $this->actingAs($this->studentIn($this->minna1))->get(route('media', $m2->pdf_path))->assertForbidden();
        $this->actingAs($this->studentIn($this->minna2))->get(route('media', $m2->pdf_path))->assertOk();
        $this->actingAs($this->user(Role::Sensei))->get(route('media', $m2->pdf_path))->assertOk();
    }

    public function test_extra_material_is_only_for_its_class_and_respects_status_and_end_date(): void
    {
        $sensei = $this->user(Role::Sensei);
        $class = $this->classroom($this->minna1, $sensei);
        $member = $this->user(Role::Student);
        $class->students()->attach($member->id);
        $outsider = $this->studentIn($this->minna1);

        $extra = Material::create(['kind' => Material::EXTRA, 'classroom_id' => $class->id, 'title' => 'Kartu Kanji Kelas',
            'description' => 'isi', 'status' => 'published', 'created_by' => $sensei->id, 'available_until' => today()->addDay()]);

        $this->actingAs($member)->get(route('student.materials', ['tab' => 'tambahan']))->assertOk()->assertSee('Kartu Kanji Kelas');
        $this->actingAs($member)->get(route('student.materials.show', $extra))->assertOk();
        $this->actingAs($outsider)->get(route('student.materials.show', $extra))->assertForbidden();

        $extra->update(['available_until' => today()->subDay()]);
        $this->actingAs($member)->get(route('student.materials.show', $extra))->assertForbidden();

        $extra->update(['available_until' => null, 'status' => 'draft']);
        $this->actingAs($member)->get(route('student.materials.show', $extra))->assertForbidden();
    }

    public function test_student_dashboard_still_works(): void
    {
        $this->core($this->minna1, 'Materi Minna Satu');
        $this->actingAs($this->studentIn($this->minna1))->get(route('dashboard'))->assertOk();
    }

    // ---------------- Hak ubah staf ----------------

    public function test_only_education_admin_creates_core_materials_within_chapter_range(): void
    {
        $edu = $this->user(Role::EducationAdmin);
        $base = ['kind' => 'core', 'curriculum_id' => $this->minna1->id, 'title' => 'Bab 30 salah', 'description' => 'isi', 'status' => 'published'];

        $this->actingAs($edu)->post(route('manage.materials.store'), $base + ['chapter_no' => 30])->assertSessionHasErrors('chapter_no');
        $this->actingAs($edu)->post(route('manage.materials.store'), ['chapter_no' => 18, 'title' => 'Bab 18'] + $base)->assertSessionHasNoErrors();

        $m = Material::where('title', 'Bab 18')->firstOrFail();
        $this->assertSame([Material::CORE, $this->minna1->id, 18], [$m->kind, $m->curriculum_id, $m->chapter_no]);
    }

    public function test_sensei_cannot_create_or_edit_core_materials(): void
    {
        $sensei = $this->user(Role::Sensei);
        $core = $this->core($this->minna1, 'Materi Pakem');

        // Sensei selalu membuat materi tambahan; tanpa kelas tujuan ditolak, dan tidak ada materi pakem yang tercipta.
        $this->actingAs($sensei)->post(route('manage.materials.store'), [
            'kind' => 'core', 'curriculum_id' => $this->minna1->id, 'title' => 'Coba pakem', 'description' => 'isi', 'status' => 'published',
        ])->assertSessionHasErrors('classroom_id');
        $this->assertFalse(Material::where('title', 'Coba pakem')->exists());

        $this->actingAs($sensei)->get(route('manage.materials.edit', $core))->assertForbidden();
        $this->actingAs($sensei)->put(route('manage.materials.update', $core), ['title' => 'Diubah', 'description' => 'x', 'status' => 'published'])->assertForbidden();
        $this->actingAs($sensei)->delete(route('manage.materials.destroy', $core))->assertForbidden();
        $this->assertSame('Materi Pakem', $core->fresh()->title);
    }

    public function test_sensei_manages_extra_materials_only_for_own_classes(): void
    {
        $sensei = $this->user(Role::Sensei);
        $other = $this->user(Role::Sensei);
        $own = $this->classroom($this->minna1, $sensei, 'Milik');
        $notOwn = $this->classroom($this->minna1, $other, 'Lain');
        $data = ['title' => 'Rangkuman Bab 18', 'description' => 'isi', 'status' => 'published'];

        $this->actingAs($sensei)->post(route('manage.materials.store'), $data + ['classroom_id' => $notOwn->id])->assertSessionHasErrors('classroom_id');
        $this->actingAs($sensei)->post(route('manage.materials.store'), $data + ['classroom_id' => $own->id, 'available_until' => today()->addWeek()->toDateString()])
            ->assertSessionHasNoErrors();

        $extra = Material::where('title', 'Rangkuman Bab 18')->firstOrFail();
        $this->assertSame([Material::EXTRA, $own->id, null], [$extra->kind, $extra->classroom_id, $extra->curriculum_id]);

        // Tutup akses lewat status
        $this->actingAs($sensei)->put(route('manage.materials.update', $extra), $data + ['classroom_id' => $own->id, 'status' => 'draft'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $extra->fresh()->status);

        $this->actingAs($other)->get(route('manage.materials.edit', $extra))->assertForbidden();
        $this->actingAs($this->user(Role::EducationAdmin))->get(route('manage.materials.edit', $extra))->assertOk();
    }

    public function test_admin_can_only_view_materials(): void
    {
        $admin = $this->user(Role::Admin);
        $core = $this->core($this->minna1, 'Materi Pakem');

        $this->actingAs($admin)->get(route('manage.materials.index', ['tab' => $this->minna1->id, 'sort' => 'judul']))->assertOk()
            ->assertSee('Materi Pakem')->assertSee('Mode baca')->assertDontSee('+ Materi pakem');
        $this->actingAs($admin)->get(route('manage.materials.create'))->assertForbidden();
        $this->actingAs($admin)->get(route('manage.materials.edit', $core))->assertForbidden();
    }

    public function test_material_index_tabs_render_for_staff(): void
    {
        $edu = $this->user(Role::EducationAdmin);
        $this->core(null, 'Materi Umum');
        foreach (['umum', 'tambahan', (string) $this->minna1->id] as $tab) {
            $this->actingAs($edu)->get(route('manage.materials.index', ['tab' => $tab, 'q' => 'Materi']))->assertOk();
        }
        $this->actingAs($edu)->get(route('manage.materials.create', ['kind' => 'extra']))->assertOk()->assertSee('Jenis materi');
    }
}
