<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\Material;
use App\Models\MaterialFile;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialFilesTest extends TestCase
{
    use RefreshDatabase;

    private Curriculum $minna1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        Storage::fake('local');
        $this->minna1 = Curriculum::where('code', 'MINNA1')->first();
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    public function test_core_material_accepts_several_files_of_each_type(): void
    {
        $edu = User::factory()->create(['role' => Role::EducationAdmin]);

        $this->actingAs($edu)->post(route('manage.materials.store'), [
            'kind' => 'core', 'curriculum_id' => $this->minna1->id, 'chapter_no' => 18, 'title' => 'Bab 18', 'status' => 'published',
            'pdfs' => [$this->pdf('bunpou.pdf'), $this->pdf('kanji.pdf'), $this->pdf('latihan.pdf')],
            'ppts' => [UploadedFile::fake()->create('slide-1.pptx', 200), UploadedFile::fake()->create('slide-2.pptx', 200)],
            'videos' => [UploadedFile::fake()->create('kaiwa.mp4', 500, 'video/mp4'), UploadedFile::fake()->create('choukai.mp4', 500, 'video/mp4')],
        ])->assertSessionHasNoErrors();

        $m = Material::where('title', 'Bab 18')->firstOrFail();
        $this->assertSame(['pdf' => 3, 'ppt' => 2, 'video' => 2], $m->files->countBy('type')->sortKeys()->all());
        $this->assertEqualsCanonicalizing(['bunpou.pdf', 'kanji.pdf', 'latihan.pdf'], $m->filesOfType('pdf')->pluck('original_name')->all());
        foreach ($m->files as $f) {
            Storage::disk('local')->assertExists($f->path);
            $this->assertStringStartsWith("materials/{$m->id}/", $f->path);
        }
    }

    public function test_files_can_be_added_and_removed_on_update(): void
    {
        $edu = User::factory()->create(['role' => Role::EducationAdmin]);
        $this->actingAs($edu)->post(route('manage.materials.store'), [
            'kind' => 'core', 'curriculum_id' => $this->minna1->id, 'title' => 'Bab 5', 'status' => 'published',
            'pdfs' => [$this->pdf('lama-1.pdf'), $this->pdf('lama-2.pdf')],
        ]);
        $m = Material::where('title', 'Bab 5')->firstOrFail();
        $old = $m->filesOfType('pdf')->firstWhere('original_name', 'lama-1.pdf');

        $this->actingAs($edu)->put(route('manage.materials.update', $m), [
            'curriculum_id' => $this->minna1->id, 'title' => 'Bab 5', 'status' => 'published',
            'remove_files' => [$old->id], 'pdfs' => [$this->pdf('baru.pdf')],
        ])->assertSessionHasNoErrors();

        $names = $m->fresh()->filesOfType('pdf')->pluck('original_name')->all();
        $this->assertEqualsCanonicalizing(['lama-2.pdf', 'baru.pdf'], $names);
        Storage::disk('local')->assertMissing($old->path);
    }

    public function test_sensei_extra_material_accepts_several_files(): void
    {
        $sensei = User::factory()->create(['role' => Role::Sensei]);
        $class = Classroom::create(['name' => 'Minna I-B', 'curriculum_id' => $this->minna1->id, 'sensei_id' => $sensei->id, 'is_active' => true]);

        $this->actingAs($sensei)->post(route('manage.materials.store'), [
            'classroom_id' => $class->id, 'title' => 'Kartu kanji', 'status' => 'published',
            'pdfs' => [$this->pdf('kartu-1.pdf'), $this->pdf('kartu-2.pdf')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Material::where('title', 'Kartu kanji')->firstOrFail()->files()->count());
    }

    public function test_file_count_per_type_is_limited(): void
    {
        $edu = User::factory()->create(['role' => Role::EducationAdmin]);
        $pdfs = array_map(fn ($i) => $this->pdf("f{$i}.pdf"), range(1, MaterialFile::MAX_PER_TYPE + 1));

        $this->actingAs($edu)->post(route('manage.materials.store'), [
            'kind' => 'core', 'title' => 'Terlalu banyak', 'status' => 'published', 'pdfs' => $pdfs,
        ])->assertSessionHasErrors('pdfs');
        $this->assertFalse(Material::where('title', 'Terlalu banyak')->exists());
    }

    public function test_wrong_file_type_is_rejected(): void
    {
        $edu = User::factory()->create(['role' => Role::EducationAdmin]);

        $this->actingAs($edu)->post(route('manage.materials.store'), [
            'kind' => 'core', 'title' => 'Salah jenis', 'status' => 'published',
            'pdfs' => [UploadedFile::fake()->create('virus.exe', 10)],
        ])->assertSessionHasErrors('pdfs.0');
    }

    public function test_deleting_material_removes_all_files(): void
    {
        $edu = User::factory()->create(['role' => Role::EducationAdmin]);
        $this->actingAs($edu)->post(route('manage.materials.store'), [
            'kind' => 'core', 'title' => 'Dihapus', 'status' => 'published', 'pdfs' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')],
        ]);
        $m = Material::where('title', 'Dihapus')->firstOrFail();
        $paths = $m->files->pluck('path');

        $this->actingAs($edu)->delete(route('manage.materials.destroy', $m))->assertRedirect();
        $this->assertSame(0, MaterialFile::count());
        foreach ($paths as $p) {
            Storage::disk('local')->assertMissing($p);
        }
    }

    public function test_student_sees_every_file(): void
    {
        $edu = User::factory()->create(['role' => Role::EducationAdmin]);
        $this->actingAs($edu)->post(route('manage.materials.store'), [
            'kind' => 'core', 'curriculum_id' => $this->minna1->id, 'title' => 'Bab 7', 'status' => 'published',
            'pdfs' => [$this->pdf('bunpou-bab7.pdf'), $this->pdf('kanji-bab7.pdf')],
            'videos' => [UploadedFile::fake()->create('a.mp4', 100, 'video/mp4'), UploadedFile::fake()->create('b.mp4', 100, 'video/mp4')],
        ]);
        $m = Material::where('title', 'Bab 7')->firstOrFail();
        $student = User::factory()->create(['role' => Role::Student]);
        Classroom::create(['name' => 'Kelas A', 'curriculum_id' => $this->minna1->id, 'is_active' => true])->students()->attach($student->id);

        $res = $this->actingAs($student)->get(route('student.materials.show', $m))->assertOk()
            ->assertSee('bunpou-bab7.pdf')->assertSee('kanji-bab7.pdf');
        $this->assertSame(2, substr_count($res->getContent(), '<video'));
        $this->actingAs($student)->get(route('media', $m->filesOfType('pdf')->first()->path))->assertOk();
    }
}
