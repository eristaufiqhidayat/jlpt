<?php

namespace App\Http\Controllers\Sensei;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Models\Passage;
use App\Support\AudioUpload;
use App\Support\SectionOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Bacaan (dokkai) atau audio (choukai) yang dipakai bersama oleh beberapa soal.
 */
class PassageController extends Controller
{
    public function index(Request $request): View
    {
        $passages = Passage::with('section.level.testType')->withCount('questions')
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->integer('section_id')))
            ->latest('id')->paginate(30)->withQueryString();

        return view('sensei.passages.index', ['passages' => $passages, 'sections' => SectionOptions::grouped()]);
    }

    public function create(): View
    {
        return view('sensei.passages.form', [
            'passage' => new Passage(['status' => ContentStatus::Published]),
            'sections' => SectionOptions::grouped(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $passage = Passage::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->saveFiles($request, $passage);

        return redirect()->route('sensei.questions.create', ['section_id' => $passage->section_id, 'passage_id' => $passage->id, 'type' => 'reading'])
            ->with('success', "Bacaan #{$passage->id} tersimpan. Sekarang tambahkan soal-soalnya.");
    }

    public function edit(Passage $passage): View
    {
        return view('sensei.passages.form', [
            'passage' => $passage->load('questions'),
            'sections' => SectionOptions::grouped(),
        ]);
    }

    public function update(Request $request, Passage $passage): RedirectResponse
    {
        $passage->update($this->validated($request, $passage));
        $this->saveFiles($request, $passage);

        return redirect()->route('sensei.passages.index')->with('success', "Bacaan #{$passage->id} diperbarui.");
    }

    private function validated(Request $request, ?Passage $passage = null): array
    {
        $data = $request->validate([
            'section_id' => ['required', 'exists:sections,id'],
            'title' => ['nullable', 'string', 'max:150'],
            'content' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', new Enum(ContentStatus::class)],
            'image' => ['nullable', 'image', 'max:4096'],
            'audio' => ['nullable', ...AudioUpload::rules(40960)],
        ], [], ['section_id' => 'bagian', 'content' => 'isi bacaan']);

        $hasSomething = filled($data['content'] ?? null) || $request->hasFile('image') || $request->hasFile('audio')
            || ($passage && ($passage->image_path || $passage->audio_path));
        if (! $hasSomething) {
            throw ValidationException::withMessages(['content' => 'Isi teks bacaan, atau lampirkan gambar/audio.']);
        }

        unset($data['image'], $data['audio']);

        return $data;
    }

    private function saveFiles(Request $request, Passage $passage): void
    {
        foreach (['image' => 'image_path', 'audio' => 'audio_path'] as $input => $column) {
            if ($request->boolean("remove_$input") && $passage->$column) {
                Storage::disk('local')->delete($passage->$column);
                $passage->$column = null;
            }
            if ($request->hasFile($input)) {
                $passage->$column && Storage::disk('local')->delete($passage->$column);
                $passage->$column = $input === 'audio'
                    ? AudioUpload::store($request->file($input), "passages/{$passage->id}")
                    : $request->file($input)->store("passages/{$passage->id}", 'local');
            }
        }
        $passage->save();
    }
}
