<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\Level;
use App\Models\MasterDataLog;
use App\Models\Section;
use App\Models\TestType;
use App\Models\User;
use App\Models\ViolationCategory;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    // ---------------- Seeder ----------------

    public function test_seeder_follows_meeting_notes(): void
    {
        $this->assertSame(180, TestType::where('code', 'JLPT')->value('max_score'));
        $this->assertSame(350, TestType::where('code', 'JTEST')->value('max_score'));
        $this->assertSame(180, TestType::where('code', 'NATTEST')->value('max_score'));
        $this->assertSame(250, TestType::where('code', 'JFT')->value('max_score'));
        $this->assertNull(TestType::where('code', 'SSW')->value('max_score'));

        $this->assertEqualsCanonicalizing(['A-C', 'D-E', 'F-G'], TestType::where('code', 'JTEST')->first()->levels->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['5Q', '4Q', '3Q', '2Q', '1Q'], TestType::where('code', 'NATTEST')->first()->levels->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['A1', 'A2.1', 'A2.2'], TestType::where('code', 'JFT')->first()->levels->pluck('code')->all());
        $this->assertCount(5, TestType::where('code', 'SSW')->first()->levels);

        $this->assertSame(10, Curriculum::count()); // 9 kurikulum + Materi Umum (terbuka)
        $minna1 = Curriculum::where('code', 'MINNA1')->first();
        $this->assertSame([1, 25, 1], [$minna1->chapter_from, $minna1->chapter_to, $minna1->sort_order]);
        $this->assertSame([26, 50], [Curriculum::where('code', 'MINNA2')->value('chapter_from'), Curriculum::where('code', 'MINNA2')->value('chapter_to')]);
    }

    public function test_seeder_can_rerun_without_overwriting_admin_changes(): void
    {
        $jlpt = TestType::where('code', 'JLPT')->first();
        $jlpt->update(['max_score' => 175, 'is_active' => false]);
        $section = Section::where('code', 'choukai')->first();
        $section->update(['default_duration_minutes' => 99]);

        $this->seed(MasterDataSeeder::class);

        $this->assertSame(175, $jlpt->fresh()->max_score);
        $this->assertFalse($jlpt->fresh()->is_active);
        $this->assertSame(99, (int) $section->fresh()->default_duration_minutes);
        $this->assertSame(10, Curriculum::count()); // 9 kurikulum + Materi Umum (terbuka)
    }

    // ---------------- Akses per peran ----------------

    public function test_every_staff_role_can_view_all_tabs(): void
    {
        foreach ([Role::EducationAdmin, Role::Admin, Role::Sensei, Role::Director] as $role) {
            $user = $this->user($role);
            foreach (['kelas' => 'Minna no Nihongo I', 'ujian' => 'NAT-TEST', 'skor' => '350', 'batsu' => 'Kategori'] as $tab => $text) {
                $this->actingAs($user)->get(route('manage.master.index', ['tab' => $tab]))
                    ->assertOk()->assertSee($text);
            }
        }
    }

    public function test_students_cannot_view_master_data(): void
    {
        $this->actingAs($this->user(Role::Student))->get(route('manage.master.index'))->assertForbidden();
    }

    public function test_only_education_admin_sees_edit_buttons(): void
    {
        $this->actingAs($this->user(Role::EducationAdmin))->get(route('manage.master.index'))->assertSee('+ Tambah kurikulum');
        $this->actingAs($this->user(Role::Admin))->get(route('manage.master.index'))
            ->assertDontSee('+ Tambah kurikulum')->assertSee('mode baca');
    }

    public function test_other_roles_cannot_change_master_data(): void
    {
        $level = Level::first();
        foreach ([Role::Admin, Role::Sensei, Role::Director, Role::Student] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get(route('manage.master.create', 'curriculum'))->assertForbidden();
            $this->actingAs($user)->post(route('manage.master.store', 'curriculum'), $this->curriculumData())->assertForbidden();
            $this->actingAs($user)->post(route('manage.master.toggle', ['level', $level->id]))->assertForbidden();
        }
        $this->assertTrue($level->fresh()->is_active);
        $this->assertSame(0, MasterDataLog::count());
    }

    public function test_education_admin_dashboard_opens_master_data(): void
    {
        $this->actingAs($this->user(Role::EducationAdmin))->get(route('dashboard'))
            ->assertRedirect(route('manage.master.index'));
    }

    public function test_education_admin_can_use_academic_menus(): void
    {
        $user = $this->user(Role::EducationAdmin);
        foreach (['manage.materials.index', 'manage.classes.index', 'sensei.questions.index', 'sensei.exams.index', 'sensei.grading.index', 'reports'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    }

    // ---------------- Tambah, ubah, nonaktifkan ----------------

    private function curriculumData(array $override = []): array
    {
        return $override + [
            'group' => 'Kelas SSW', 'name' => 'SSW · Perhotelan', 'code' => 'SSW-HOTEL',
            'scope' => 'Kosakata layanan tamu', 'chapter_from' => null, 'chapter_to' => null, 'sort_order' => 5,
        ];
    }

    public function test_education_admin_adds_a_curriculum_and_it_is_logged(): void
    {
        $admin = $this->user(Role::EducationAdmin);

        $this->actingAs($admin)->post(route('manage.master.store', 'curriculum'), $this->curriculumData())
            ->assertRedirect(route('manage.master.index', ['tab' => 'kelas']));

        $curriculum = Curriculum::where('code', 'SSW-HOTEL')->firstOrFail();
        $this->assertTrue((bool) $curriculum->fresh()->is_active);

        $log = MasterDataLog::latest('id')->first();
        $this->assertSame(['curriculum', $curriculum->id, 'created', $admin->id], [$log->subject_type, $log->subject_id, $log->action, $log->user_id]);
        $this->assertContains(['field' => 'Kode', 'old' => null, 'new' => 'SSW-HOTEL'], $log->changes);
    }

    public function test_curriculum_code_must_be_unique_and_chapters_ordered(): void
    {
        $admin = $this->user(Role::EducationAdmin);

        $this->actingAs($admin)->post(route('manage.master.store', 'curriculum'), $this->curriculumData(['code' => 'MINNA1']))
            ->assertSessionHasErrors('code');
        $this->actingAs($admin)->post(route('manage.master.store', 'curriculum'), $this->curriculumData(['code' => 'minna 3']))
            ->assertSessionHasErrors('code');
        $this->actingAs($admin)->post(route('manage.master.store', 'curriculum'), $this->curriculumData(['chapter_from' => 26, 'chapter_to' => 10]))
            ->assertSessionHasErrors('chapter_to');
    }

    public function test_updating_scores_logs_old_and_new_values(): void
    {
        $admin = $this->user(Role::EducationAdmin);
        $jtest = TestType::where('code', 'JTEST')->first();

        $this->actingAs($admin)->put(route('manage.master.update', ['test-type', $jtest->id]), [
            'name' => $jtest->name, 'sort_order' => $jtest->sort_order, 'code' => 'HACKED',
            'max_score' => 350, 'pass_score' => 200, 'score_approved_by' => 'Direktur', 'score_approved_at' => '2026-10-02',
            'from' => 'skor',
        ])->assertRedirect(route('manage.master.index', ['tab' => 'skor']));

        $jtest->refresh();
        $this->assertSame('JTEST', $jtest->code, 'Kode jenis tes tidak boleh berubah lewat form.');
        $this->assertSame([350, 200, 'Direktur', '2026-10-02'], [$jtest->max_score, $jtest->pass_score, $jtest->score_approved_by, $jtest->score_approved_at->toDateString()]);

        $log = MasterDataLog::latest('id')->first();
        $this->assertSame('updated', $log->action);
        $this->assertContains(['field' => 'Batas lulus simulasi', 'old' => null, 'new' => '200'], $log->changes);
        $this->assertContains(['field' => 'Disetujui oleh', 'old' => 'Notulen rapat JGG Learning System', 'new' => 'Direktur'], $log->changes);
    }

    public function test_pass_score_cannot_exceed_max_score_and_needs_approval(): void
    {
        $admin = $this->user(Role::EducationAdmin);
        $ssw = TestType::where('code', 'SSW')->first();
        $base = ['name' => $ssw->name, 'sort_order' => $ssw->sort_order];

        $this->actingAs($admin)->put(route('manage.master.update', ['test-type', $ssw->id]), $base + ['max_score' => 100, 'pass_score' => 150, 'score_approved_by' => 'Direktur'])
            ->assertSessionHasErrors('pass_score');
        $this->actingAs($admin)->put(route('manage.master.update', ['test-type', $ssw->id]), $base + ['max_score' => 100])
            ->assertSessionHasErrors('score_approved_by');
        $this->assertNull($ssw->fresh()->max_score);
    }

    public function test_saving_without_changes_does_not_log(): void
    {
        $admin = $this->user(Role::EducationAdmin);
        $cat = ViolationCategory::create(['name' => 'Terlambat', 'color' => 'warn', 'sort_order' => 1]);

        $this->actingAs($admin)->put(route('manage.master.update', ['violation-category', $cat->id]), [
            'name' => 'Terlambat', 'description' => null, 'color' => 'warn', 'sort_order' => 1,
        ])->assertSessionHas('success', 'Tidak ada perubahan.');
        $this->assertSame(0, MasterDataLog::count());
    }

    public function test_level_code_is_unique_within_a_test_type(): void
    {
        $admin = $this->user(Role::EducationAdmin);
        $nat = TestType::where('code', 'NATTEST')->first();
        $jtest = TestType::where('code', 'JTEST')->first();

        $this->actingAs($admin)->post(route('manage.master.store', 'level'), ['test_type_id' => $nat->id, 'code' => '4Q', 'name' => 'Dobel', 'sort_order' => 9])
            ->assertSessionHasErrors('code');
        $this->actingAs($admin)->post(route('manage.master.store', 'level'), ['test_type_id' => $jtest->id, 'code' => '4Q', 'name' => 'J-Test 4Q', 'sort_order' => 9])
            ->assertSessionHasNoErrors();
    }

    public function test_education_admin_adds_a_section_with_level_prefilled(): void
    {
        $admin = $this->user(Role::EducationAdmin);
        $level = Level::where('code', '4Q')->first();

        $this->actingAs($admin)->get(route('manage.master.create', ['section', 'level_id' => $level->id]))
            ->assertOk()->assertSee('value="'.$level->id.'" selected', false);

        $this->actingAs($admin)->post(route('manage.master.store', 'section'), [
            'level_id' => $level->id, 'code' => 'choukai', 'name' => 'Choukai (聴解)',
            'default_duration_minutes' => 30, 'default_question_count' => 0, 'sort_order' => 3,
        ])->assertRedirect(route('manage.master.index', ['tab' => 'ujian']));

        $this->assertDatabaseHas('sections', ['level_id' => $level->id, 'code' => 'choukai', 'default_duration_minutes' => 30]);
    }

    public function test_toggle_deactivates_instead_of_deleting_and_is_logged(): void
    {
        $admin = $this->user(Role::EducationAdmin);
        $curriculum = Curriculum::where('code', 'SSW-KONSTRUKSI')->first();

        $this->actingAs($admin)->post(route('manage.master.toggle', ['curriculum', $curriculum->id]))->assertRedirect();
        $this->assertFalse($curriculum->fresh()->is_active);
        $this->assertDatabaseHas('curricula', ['id' => $curriculum->id]);

        $this->actingAs($admin)->post(route('manage.master.toggle', ['curriculum', $curriculum->id]));
        $this->assertTrue($curriculum->fresh()->is_active);
        $this->assertSame(['deactivated', 'activated'], MasterDataLog::orderBy('id')->pluck('action')->all());

        $this->actingAs($admin)->get(route('manage.master.index'))->assertSee('Diaktifkan: SSW · Konstruksi');
    }

    public function test_unknown_entity_returns_404(): void
    {
        $this->actingAs($this->user(Role::EducationAdmin))->get('/manage/master/users/create')->assertNotFound();
    }

    // ---------------- Dampak ke kelas & ujian ----------------

    public function test_inactive_curriculum_is_hidden_from_new_class_form(): void
    {
        $admin = $this->user(Role::Admin);
        Curriculum::where('code', 'SSW-KONSTRUKSI')->update(['is_active' => false]);

        $this->actingAs($admin)->get(route('manage.classes.create'))
            ->assertOk()->assertSee('Minna no Nihongo I')->assertDontSee('SSW · Konstruksi');

        $minna1 = Curriculum::where('code', 'MINNA1')->first();
        $this->actingAs($admin)->post(route('manage.classes.store'), ['name' => 'Minna I-C', 'curriculum_id' => $minna1->id, 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame($minna1->id, Classroom::where('name', 'Minna I-C')->value('curriculum_id'));

        $this->actingAs($this->user(Role::EducationAdmin))->get(route('manage.master.index'))->assertOk();
    }

    public function test_inactive_level_is_not_offered_for_new_exams(): void
    {
        $user = $this->user(Role::EducationAdmin);
        $n1 = Level::where('code', 'N1')->whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))->first();
        $n1->update(['is_active' => false]);

        $this->actingAs($user)->get(route('sensei.exams.create'))->assertOk()
            ->assertViewHas('levels', fn ($levels) => ! $levels->contains('id', $n1->id) && $levels->contains('code', 'N4'));
    }
}
