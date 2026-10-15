<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Material;
use App\Models\MaterialFile;
use App\Models\User;
use App\Services\ChunkedUploads;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChunkedUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $edu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        Storage::fake('local');
        $this->edu = User::factory()->create(['role' => Role::EducationAdmin]);
    }

    private function pdfBytes(int $length = 3000): string
    {
        $head = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj << /Type /Catalog >> endobj\n";

        return $head.str_repeat('A', max(0, $length - strlen($head)));
    }

    private function json(User $user)
    {
        return $this->actingAs($user)->withHeaders(['Accept' => 'application/json']);
    }

    /** Unggah isi file lengkap dalam beberapa potongan; kembalikan id upload. */
    private function uploadInChunks(User $user, string $name, string $bytes, int $chunk = 1000): string
    {
        $id = $this->json($user)->post(route('manage.uploads.start'), ['name' => $name, 'size' => strlen($bytes)])
            ->assertCreated()->json('id');
        for ($offset = 0; $offset < strlen($bytes); $offset += $chunk) {
            $this->json($user)->post(route('manage.uploads.chunk', $id), [
                'offset' => $offset,
                'chunk' => UploadedFile::fake()->createWithContent('chunk', substr($bytes, $offset, $chunk)),
            ])->assertOk();
        }

        return $id;
    }

    public function test_start_rejects_unsupported_or_too_large_files(): void
    {
        $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'virus.exe', 'size' => 10])->assertUnprocessable();
        $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'besar.pdf', 'size' => 51 * 1024 * 1024])->assertUnprocessable();
        $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'video.mp4', 'size' => 400 * 1024 * 1024])
            ->assertCreated()->assertJson(['type' => 'video', 'chunk_size' => ChunkedUploads::CHUNK_SIZE, 'received' => 0]);
    }

    public function test_chunks_are_assembled_and_completed(): void
    {
        $bytes = $this->pdfBytes(3500);
        $id = $this->uploadInChunks($this->edu, 'materi.pdf', $bytes);

        Storage::disk('local')->assertExists("tmp-uploads/{$id}/data");
        $this->assertSame($bytes, Storage::disk('local')->get("tmp-uploads/{$id}/data"));
    }

    public function test_wrong_offset_returns_conflict_with_server_position(): void
    {
        $bytes = $this->pdfBytes(2000);
        $id = $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'a.pdf', 'size' => 2000])->json('id');
        $this->json($this->edu)->post(route('manage.uploads.chunk', $id), ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('c', substr($bytes, 0, 1000))])->assertOk();

        // Potongan pertama dikirim ulang (mis. setelah jeda): server tidak menambah, memberi tahu posisinya.
        $this->json($this->edu)->post(route('manage.uploads.chunk', $id), ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('c', substr($bytes, 0, 1000))])
            ->assertStatus(409)->assertJson(['received' => 1000, 'complete' => false]);
    }

    public function test_content_must_match_extension(): void
    {
        $fake = str_repeat('bukan pdf ', 100);
        $id = $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'palsu.pdf', 'size' => strlen($fake)])->json('id');

        $this->json($this->edu)->post(route('manage.uploads.chunk', $id), ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('c', $fake)])
            ->assertUnprocessable();
        Storage::disk('local')->assertMissing("tmp-uploads/{$id}/data");
    }

    public function test_upload_belongs_to_its_uploader_only(): void
    {
        $id = $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'a.pdf', 'size' => 100])->json('id');
        $other = User::factory()->create(['role' => Role::Sensei]);

        $this->json($other)->post(route('manage.uploads.chunk', $id), ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('c', 'x')])->assertNotFound();
        $this->json($other)->delete(route('manage.uploads.destroy', $id))->assertNotFound();
    }

    public function test_admin_and_students_cannot_upload(): void
    {
        foreach ([Role::Admin, Role::Student, Role::Director] as $role) {
            $this->json(User::factory()->create(['role' => $role]))->post(route('manage.uploads.start'), ['name' => 'a.pdf', 'size' => 10])->assertForbidden();
        }
    }

    public function test_saving_material_claims_uploaded_files(): void
    {
        $a = $this->uploadInChunks($this->edu, 'bunpou bab 18.pdf', $this->pdfBytes(2500));
        $b = $this->uploadInChunks($this->edu, 'kanji bab 18.pdf', $this->pdfBytes(1200));

        $this->actingAs($this->edu)->post(route('manage.materials.store'), [
            'kind' => 'core', 'title' => 'Bab 18', 'status' => 'published', 'uploads' => [$a, $b],
        ])->assertSessionHasNoErrors();

        $m = Material::where('title', 'Bab 18')->firstOrFail();
        $this->assertEqualsCanonicalizing(['bunpou bab 18.pdf', 'kanji bab 18.pdf'], $m->files->pluck('original_name')->all());
        $this->assertEqualsCanonicalizing([2500, 1200], $m->files->pluck('size')->all());
        foreach ($m->files as $f) {
            $this->assertSame('pdf', $f->type);
            $this->assertStringStartsWith("materials/{$m->id}/", $f->path);
            Storage::disk('local')->assertExists($f->path);
        }
        Storage::disk('local')->assertMissing("tmp-uploads/{$a}/meta.json");
    }

    public function test_unfinished_or_foreign_uploads_are_rejected_on_save(): void
    {
        $unfinished = $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'a.pdf', 'size' => 5000])->json('id');
        $foreign = $this->uploadInChunks(User::factory()->create(['role' => Role::EducationAdmin]), 'b.pdf', $this->pdfBytes(1500));

        foreach ([$unfinished, $foreign] as $id) {
            $this->actingAs($this->edu)->post(route('manage.materials.store'), [
                'kind' => 'core', 'title' => 'Coba', 'description' => 'isi', 'status' => 'published', 'uploads' => [$id],
            ])->assertSessionHasErrors('uploads');
        }
        $this->assertFalse(Material::where('title', 'Coba')->exists());
    }

    public function test_uploads_count_towards_the_limit(): void
    {
        $ids = [];
        for ($i = 0; $i <= MaterialFile::MAX_PER_TYPE; $i++) {
            $ids[] = $this->uploadInChunks($this->edu, "f{$i}.pdf", $this->pdfBytes(600), 600);
        }

        $this->actingAs($this->edu)->post(route('manage.materials.store'), [
            'kind' => 'core', 'title' => 'Kebanyakan', 'status' => 'published', 'uploads' => $ids,
        ])->assertSessionHasErrors('pdfs');
    }

    public function test_cancel_and_prune_remove_temporary_files(): void
    {
        $id = $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'a.pdf', 'size' => 100])->json('id');
        $this->json($this->edu)->delete(route('manage.uploads.destroy', $id))->assertOk();
        Storage::disk('local')->assertMissing("tmp-uploads/{$id}/meta.json");

        $old = $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'lama.pdf', 'size' => 100])->json('id');
        $this->travel(25)->hours();
        $fresh = $this->json($this->edu)->post(route('manage.uploads.start'), ['name' => 'baru.pdf', 'size' => 100])->json('id');

        $this->artisan('uploads:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing("tmp-uploads/{$old}/meta.json");
        Storage::disk('local')->assertExists("tmp-uploads/{$fresh}/meta.json");
    }

    public function test_material_form_shows_uploader(): void
    {
        $this->actingAs($this->edu)->get(route('manage.materials.create'))->assertOk()
            ->assertSee('data-uploader', false)->assertSee('Upload semua')->assertSee('js/file-uploader.js');
    }
}
