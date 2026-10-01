<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Level;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MaterialController extends Controller
{
    public function index(Request $request): View
    {
        $materials = Material::with('level.testType')->withCount('completedBy')
            ->when($request->filled('level_id'), fn ($q) => $q->where('level_id', $request->integer('level_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$request->string('q').'%')->orWhere('chapter', 'like', '%'.$request->string('q').'%')))
            ->orderBy('level_id')->orderBy('chapter')->orderBy('sort_order')->paginate(30)->withQueryString();

        return view('manage.materials.index', ['materials' => $materials, 'levels' => $this->levels()]);
    }

    public function create(): View
    {
        return $this->form(new Material(['status' => 'draft', 'sort_order' => 0]));
    }

    public function store(Request $request): RedirectResponse
    {
        $material = Material::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->saveFiles($request, $material);

        return redirect()->route('manage.materials.index')->with('success', "Materi \"{$material->title}\" tersimpan.");
    }

    public function edit(Material $material): View
    {
        return $this->form($material);
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $material->update($this->validated($request, $material));
        $this->saveFiles($request, $material);

        return redirect()->route('manage.materials.index')->with('success', "Materi \"{$material->title}\" diperbarui.");
    }

    public function destroy(Material $material): RedirectResponse
    {
        foreach (['video_path', 'pdf_path', 'ppt_path'] as $c) {
            $material->$c && Storage::disk('local')->delete($material->$c);
        }
        $material->delete();

        return back()->with('success', 'Materi dihapus.');
    }

    private function form(Material $material): View
    {
        return view('manage.materials.form', [
            'material' => $material,
            'levels' => $this->levels(),
            'exams' => Exam::where('status', 'open')->orderByDesc('id')->get(['id', 'title', 'code']),
        ]);
    }

    private function levels()
    {
        return Level::with('testType')->get()->sortBy(fn ($l) => [$l->testType->is_active ? 0 : 1, $l->testType->sort_order, $l->sort_order])->values();
    }

    private function validated(Request $request, ?Material $material = null): array
    {
        $data = $request->validate([
            'level_id' => ['nullable', 'exists:levels,id'],
            'chapter' => ['nullable', 'string', 'max:150'],
            'title' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'],
            'video_url' => ['nullable', 'url', 'max:255'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'exam_id' => ['nullable', 'exists:exams,id'],
            'status' => ['required', 'in:draft,published'],
            'video' => ['nullable', 'file', 'mimes:mp4,webm,mov', 'max:512000'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
            'ppt' => ['nullable', 'file', 'mimes:ppt,pptx,pdf', 'max:102400'],
        ], [], ['title' => 'judul', 'chapter' => 'bab', 'video_url' => 'tautan video']);

        $hasContent = filled($data['video_url'] ?? null) || filled($data['description'] ?? null)
            || $request->hasFile('video') || $request->hasFile('pdf') || $request->hasFile('ppt')
            || ($material && ($material->video_path || $material->pdf_path || $material->ppt_path));
        if (! $hasContent) {
            throw ValidationException::withMessages(['video_url' => 'Isi minimal satu: tautan video, file video/PDF/PPT, atau keterangan.']);
        }
        unset($data['video'], $data['pdf'], $data['ppt']);
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    private function saveFiles(Request $request, Material $material): void
    {
        foreach (['video' => 'video_path', 'pdf' => 'pdf_path', 'ppt' => 'ppt_path'] as $input => $col) {
            if ($request->boolean("remove_$input") && $material->$col) {
                Storage::disk('local')->delete($material->$col);
                $material->$col = null;
            }
            if ($request->hasFile($input)) {
                $material->$col && Storage::disk('local')->delete($material->$col);
                $material->$col = $request->file($input)->store("materials/{$material->id}", 'local');
            }
        }
        $material->save();
    }
}
