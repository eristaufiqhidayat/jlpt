<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Question;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Upload audio soal: .m4a diterima walau isi file terdeteksi video/mp4, dan tersimpan sebagai .m4a. */
class AudioUploadTest extends TestCase
{
    use RefreshDatabase;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(MasterDataSeeder::class);
        $this->section = Section::where('code', 'choukai')->whereHas('level', fn ($q) => $q->where('code', 'N5'))->firstOrFail();
    }

    /** Header MP4 brand "mp42" (umum pada rekaman Android/Zoom): finfo mendeteksinya video/mp4. */
    private function m4a(string $brand = 'mp42'): UploadedFile
    {
        $bytes = "\x00\x00\x00\x18ftyp{$brand}\x00\x00\x00\x00{$brand}isom".str_repeat("\x00", 64);

        return UploadedFile::fake()->createWithContent('rekaman.m4a', $bytes);
    }

    private function store(UploadedFile $audio, string $stem): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::factory()->create(['role' => Role::Sensei]))->post(route('sensei.questions.store'), [
            'section_id' => $this->section->id, 'type' => 'listening', 'stem' => $stem, 'status' => 'draft',
            'options' => [1 => ['content' => 'a'], 2 => ['content' => 'b']], 'correct' => 1,
            'audio' => $audio,
        ]);
    }

    public function test_m4a_detected_as_video_mp4_is_accepted_and_kept_as_m4a(): void
    {
        $this->store($this->m4a('mp42'), 'soal m4a android')->assertSessionHasNoErrors();

        $path = Question::where('stem', 'soal m4a android')->value('audio_path');
        $this->assertNotNull($path);
        $this->assertStringEndsWith('.m4a', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_m4a_detected_as_audio_is_accepted(): void
    {
        $this->store($this->m4a('M4A '), 'soal m4a iphone')->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.m4a', Question::where('stem', 'soal m4a iphone')->value('audio_path'));
    }

    public function test_non_audio_file_is_rejected(): void
    {
        $this->store(UploadedFile::fake()->create('catatan.pdf', 10, 'application/pdf'), 'soal pdf')
            ->assertSessionHasErrors('audio');
    }
}
