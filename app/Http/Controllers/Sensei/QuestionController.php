<?php

namespace App\Http\Controllers\Sensei;

use App\Enums\ContentStatus;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\AttemptQuestion;
use App\Models\Passage;
use App\Models\Question;
use App\Support\AudioUpload;
use App\Support\LevelOptions;
use App\Support\SectionOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Bank soal bersama: semua sensei bisa melihat dan mengedit soal.
 */
class QuestionController extends Controller
{
    public const TYPE_LABELS = [
        'blank' => 'Isian (　)',
        'star' => 'Menyusun kalimat ★',
        'reading' => 'Bacaan (dokkai)',
        'listening' => 'Choukai (audio)',
        'essay' => 'Esai (dinilai manual)',
    ];

    /** Jumlah maksimal soal dalam satu pratinjau. */
    public const PREVIEW_LIMIT = 100;

    public function index(Request $request): View
    {
        $questions = $this->filtered($request)
            ->with(['section.level.testType', 'passage:id,title', 'correctOption:id,question_id,label'])
            ->withCount('options')
            ->latest('id')
            ->paginate(30)->withQueryString();

        return view('sensei.questions.index', [
            'questions' => $questions,
            'levelGroups' => LevelOptions::grouped(),
            'types' => self::TYPE_LABELS,
        ]);
    }

    /**
     * Pratinjau soal persis seperti tampilan ujian peserta (satu soal per layar).
     * Memakai filter yang sama dengan daftar soal; jawaban tidak disimpan ke mana pun.
     * Urutan mengikuti susunan ujian: per bagian, jenis soal (isian → ★ → bacaan → choukai → esai), lalu bacaan.
     */
    public function preview(Request $request): View
    {
        $filters = collect($request->only(['level_id', 'section_id', 'type', 'status', 'usage', 'q']))->filter(fn ($v) => filled($v));
        $focus = $request->integer('question') ?: null;
        $with = ['section.level.testType', 'passage', 'options'];

        if ($filters->isEmpty() && $focus) {
            $questions = Question::with($with)->whereKey($focus)->get();
            $truncated = false;
        } else {
            $questions = $this->filtered($request)->with($with)
                ->orderBy('section_id')
                ->orderByRaw("CASE type WHEN 'blank' THEN 1 WHEN 'star' THEN 2 WHEN 'reading' THEN 3 WHEN 'listening' THEN 4 ELSE 5 END")
                ->orderByRaw('COALESCE(passage_id, 0)')
                ->orderBy('number_in_passage')
                ->orderBy('id')
                ->limit(self::PREVIEW_LIMIT + 1)
                ->get();
            $truncated = $questions->count() > self::PREVIEW_LIMIT;
            $questions = $questions->take(self::PREVIEW_LIMIT)->values();

            // Soal yang diklik di daftar berada di luar hasil (mis. lewat batas): tampilkan soal itu saja.
            if ($focus && ! $questions->contains('id', $focus)) {
                $questions = Question::with($with)->whereKey($focus)->get();
                $truncated = false;
            }
        }

        $start = $focus ? max(0, (int) $questions->search(fn ($q) => $q->id === $focus)) : 0;

        return view('sensei.questions.preview', [
            'questions' => $questions,
            'start' => $start,
            'truncated' => $truncated,
            'backUrl' => route('sensei.questions.index', $filters->all()),
            'types' => self::TYPE_LABELS,
        ]);
    }

    /** Filter daftar soal (dipakai daftar dan pratinjau). */
    private function filtered(Request $request): Builder
    {
        return Question::query()
            ->when($request->filled('level_id'), fn ($q) => $q->whereHas('section', fn ($s) => $s->where('level_id', $request->integer('level_id'))))
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->integer('section_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('usage'), fn ($q) => $q->where('usage', $request->string('usage')))
            ->when($request->filled('q'), fn ($q) => $q->where('stem', 'like', '%'.$request->string('q').'%'));
    }

    public function create(Request $request): View
    {
        $question = new Question([
            'section_id' => $request->integer('section_id') ?: null,
            'passage_id' => $request->integer('passage_id') ?: null,
            'type' => $request->query('type', QuestionType::Blank->value),
            'status' => ContentStatus::Draft,
        ]);

        return $this->form($question);
    }

    public function store(Request $request): RedirectResponse
    {
        $question = DB::transaction(function () use ($request) {
            $question = Question::create($this->validated($request) + ['created_by' => $request->user()->id]);
            $this->saveFiles($request, $question);
            $this->saveOptions($request, $question);

            return $question;
        });

        $next = $request->input('after') === 'new'
            ? route('sensei.questions.create', ['section_id' => $question->section_id, 'passage_id' => $question->passage_id, 'type' => $question->type->value])
            : route('sensei.questions.index', ['section_id' => $question->section_id]);

        return redirect($next)->with('success', "Soal #{$question->id} tersimpan.");
    }

    public function edit(Question $question): View
    {
        return $this->form($question->load('options'));
    }

    public function update(Request $request, Question $question): RedirectResponse
    {
        DB::transaction(function () use ($request, $question) {
            $question->update($this->validated($request, $question));
            $this->saveFiles($request, $question);
            $this->saveOptions($request, $question);
        });

        return redirect()->route('sensei.questions.index', ['level_id' => $question->section()->value('level_id')])
            ->with('success', "Soal #{$question->id} diperbarui. Ujian yang sudah berjalan tetap memakai kunci lama.");
    }

    public function destroy(Question $question): RedirectResponse
    {
        // Soal yang sudah pernah dipakai ujian tidak dihapus, hanya diarsipkan.
        if (AttemptQuestion::where('question_id', $question->id)->exists()) {
            $question->update(['status' => ContentStatus::Archived]);

            return back()->with('success', "Soal #{$question->id} sudah pernah dipakai ujian, jadi diarsipkan (tidak dihapus).");
        }

        Storage::disk('local')->deleteDirectory("questions/{$question->id}");
        $question->delete();

        return back()->with('success', "Soal #{$question->id} dihapus.");
    }

    private function form(Question $question): View
    {
        $options = $question->options->keyBy('label');

        return view('sensei.questions.form', [
            'question' => $question,
            'options' => collect(range(1, 4))->mapWithKeys(fn ($i) => [$i => $options->get($i)]),
            'sections' => SectionOptions::grouped(),
            'passages' => Passage::with('section.level')->where('status', '!=', ContentStatus::Archived)
                ->latest('id')->limit(300)->get(),
            'types' => self::TYPE_LABELS,
        ]);
    }

    private function validated(Request $request, ?Question $question = null): array
    {
        $data = $request->validate([
            'section_id' => ['required', 'exists:sections,id'],
            'type' => ['required', new Enum(QuestionType::class)],
            'passage_id' => ['nullable', 'exists:passages,id'],
            'number_in_passage' => ['nullable', 'integer', 'min:1', 'max:99'],
            'mondai' => ['nullable', 'string', 'max:50'],
            'stem' => ['nullable', 'string', 'max:5000'],
            'star_position' => ['nullable', 'integer', 'min:1', 'max:4'],
            'correct_order' => ['nullable', 'regex:/^[1-4](-[1-4]){3}$/'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'answer_reference' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', new Enum(ContentStatus::class)],
            'usage' => ['nullable', Rule::in(array_keys(Question::USAGES))],
            'image' => ['nullable', 'image', 'max:4096'],
            'audio' => ['nullable', ...AudioUpload::rules(20480)],
            'options' => ['array'],
            'options.*.content' => ['nullable', 'string', 'max:1000'],
            'options.*.image' => ['nullable', 'image', 'max:2048'],
            'correct' => ['nullable', Rule::in([1, 2, 3, 4])],
        ], [
            'correct_order.regex' => 'Urutan benar ditulis seperti 2-4-1-3.',
        ], [
            'section_id' => 'bagian', 'type' => 'jenis soal', 'stem' => 'teks soal', 'correct' => 'jawaban benar',
        ]);

        $isEssay = $data['type'] === QuestionType::Essay->value;
        $hasStemOrMedia = filled($data['stem'] ?? null) || $request->hasFile('image') || $request->hasFile('audio')
            || ($question && ($question->image_path || $question->audio_path)) || filled($data['passage_id'] ?? null);
        if (! $hasStemOrMedia) {
            throw ValidationException::withMessages(['stem' => 'Isi teks soal, atau lampirkan gambar/audio/bacaan.']);
        }

        if (! $isEssay) {
            $filled = $this->filledOptionLabels($request, $question);
            if (count($filled) < 2) {
                throw ValidationException::withMessages(['options' => 'Isi minimal 2 pilihan jawaban (teks atau gambar).']);
            }
            if (! in_array((int) ($data['correct'] ?? 0), $filled, true)) {
                throw ValidationException::withMessages(['correct' => 'Pilih jawaban benar dari pilihan yang sudah diisi.']);
            }
        }

        // Pilihan jawaban dan file disimpan terpisah.
        unset($data['options'], $data['correct'], $data['image'], $data['audio']);
        $data['passage_id'] = $data['passage_id'] ?? null;
        $data['usage'] = $data['usage'] ?? $question?->usage ?? 'practice';

        return $data;
    }

    /** @return list<int> label pilihan yang terisi (teks, gambar baru, atau gambar lama yang tidak dihapus) */
    private function filledOptionLabels(Request $request, ?Question $question): array
    {
        $existing = $question?->options()->pluck('image_path', 'label') ?? collect();
        $labels = [];
        foreach (range(1, 4) as $i) {
            $hasText = filled($request->input("options.$i.content"));
            $hasNewImage = $request->hasFile("options.$i.image");
            $hasOldImage = filled($existing[$i] ?? null) && ! $request->boolean("options.$i.remove_image");
            if ($hasText || $hasNewImage || $hasOldImage) {
                $labels[] = $i;
            }
        }

        return $labels;
    }

    private function saveOptions(Request $request, Question $question): void
    {
        if ($question->type === QuestionType::Essay) {
            foreach ($question->options as $option) {
                $option->image_path && Storage::disk('local')->delete($option->image_path);
            }
            $question->options()->delete();

            return;
        }

        $filled = $this->filledOptionLabels($request, $question);
        $correct = (int) $request->input('correct');

        foreach (range(1, 4) as $i) {
            $option = $question->options()->firstOrNew(['label' => $i]);

            if (! in_array($i, $filled, true)) {
                $option->exists && $option->image_path && Storage::disk('local')->delete($option->image_path);
                $option->exists && $option->delete();

                continue;
            }

            $image = $option->image_path;
            if ($request->boolean("options.$i.remove_image") && $image) {
                Storage::disk('local')->delete($image);
                $image = null;
            }
            if ($request->hasFile("options.$i.image")) {
                $image && Storage::disk('local')->delete($image);
                $image = $this->storeFile($request->file("options.$i.image"), $question);
            }

            $option->fill([
                'content' => $request->input("options.$i.content"),
                'image_path' => $image,
                'is_correct' => $i === $correct,
            ])->save();
        }
    }

    private function saveFiles(Request $request, Question $question): void
    {
        foreach (['image' => 'image_path', 'audio' => 'audio_path'] as $input => $column) {
            if ($request->boolean("remove_$input") && $question->$column) {
                Storage::disk('local')->delete($question->$column);
                $question->$column = null;
            }
            if ($request->hasFile($input)) {
                $question->$column && Storage::disk('local')->delete($question->$column);
                $question->$column = $input === 'audio'
                    ? AudioUpload::store($request->file($input), "questions/{$question->id}")
                    : $this->storeFile($request->file($input), $question);
            }
        }
        $question->save();
    }

    private function storeFile(UploadedFile $file, Question $question): string
    {
        return $file->store("questions/{$question->id}", 'local');
    }
}
