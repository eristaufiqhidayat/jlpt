<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\Exam;
use App\Models\Level;
use App\Models\Material;
use App\Models\MaterialFile;
use App\Models\User;
use App\Services\ChunkedUploads;
use App\Services\MaterialAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Kelola materi (notulen 4.a & 5.a).
 *
 * - Materi pakem (per kurikulum & bab): hanya Admin Pendidikan yang boleh menambah/mengubah/menghapus.
 * - Materi tambahan: sensei membuat untuk kelas yang dipegangnya, membuka/menutup lewat status, dan
 *   bisa memberi masa tayang. Admin Pendidikan boleh mengelola semua materi tambahan.
 * - Admin dan sensei lain hanya melihat.
 */
class MaterialController extends Controller
{
    private const SORTS = ['bab' => 'Nomor bab', 'terbaru' => 'Terakhir ditambah', 'judul' => 'Judul A–Z', 'selesai' => 'Paling banyak diselesaikan'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $curricula = Curriculum::orderBy('sort_order')->orderBy('name')->get();
        $counts = Material::where('kind', Material::CORE)->selectRaw('curriculum_id, COUNT(*) as total')->groupBy('curriculum_id')->pluck('total', 'curriculum_id');

        $tabs = $curricula->map(fn ($c) => ['key' => (string) $c->id, 'group' => $c->group ?: 'Lainnya', 'label' => $c->name, 'count' => $counts[$c->id] ?? 0, 'inactive' => ! $c->is_active])
            ->filter(fn ($t) => ! $t['inactive'] || $t['count'] > 0)->values();
        // Materi lama tanpa kurikulum (seharusnya sudah dipindah ke kurikulum terbuka "Materi Umum").
        $legacy = Material::where('kind', Material::CORE)->whereNull('curriculum_id')->count();
        if ($legacy > 0) {
            $tabs->push(['key' => 'umum', 'group' => null, 'label' => 'Tanpa kurikulum', 'count' => $legacy, 'inactive' => false]);
        }
        $tabs->push(['key' => 'tambahan', 'group' => null, 'label' => 'Materi tambahan', 'count' => $this->extrasQuery($user)->count(), 'inactive' => false]);
        $tab = $tabs->firstWhere('key', (string) $request->query('tab')) ?? $tabs->first();
        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'bab';

        $query = match ($tab['key']) {
            'tambahan' => $this->extrasQuery($user),
            'umum' => Material::where('kind', Material::CORE)->whereNull('curriculum_id'),
            default => Material::where('kind', Material::CORE)->where('curriculum_id', (int) $tab['key']),
        };

        $materials = $query->with(['level.testType', 'curriculum:id,name', 'classroom:id,name', 'author:id,name', 'files:id,material_id,type'])->withCount('completedBy')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$request->string('q').'%')
                ->orWhere('chapter', 'like', '%'.$request->string('q').'%')->orWhere('chapter_no', (int) $request->query('q') ?: -1)))
            ->when($sort === 'bab', fn ($q) => $q->orderBy('chapter_no')->orderBy('chapter')->orderBy('sort_order')->orderBy('id'))
            ->when($sort === 'terbaru', fn ($q) => $q->latest('id'))
            ->when($sort === 'judul', fn ($q) => $q->orderBy('title'))
            ->when($sort === 'selesai', fn ($q) => $q->orderByDesc('completed_by_count'))
            ->paginate(30)->withQueryString();

        return view('manage.materials.index', [
            'materials' => $materials,
            'tabs' => $tabs,
            'tab' => $tab,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'canCreateCore' => $user->isEducationAdmin(),
            'canCreateExtra' => $user->isEducationAdmin() || $user->isSensei(),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isEducationAdmin() || $user->isSensei(), 403, 'Materi pakem hanya bisa ditambah Admin Pendidikan; materi tambahan oleh sensei.');

        $kind = $user->isSensei() || $request->query('kind') === Material::EXTRA ? Material::EXTRA : Material::CORE;
        $material = new Material(['status' => 'draft', 'sort_order' => 0, 'kind' => $kind]);
        if ($kind === Material::CORE && ctype_digit((string) $request->query('tab'))) {
            $material->curriculum_id = (int) $request->query('tab');
        }

        return $this->form($material, $user);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isEducationAdmin() || $user->isSensei(), 403);

        $kind = $user->isSensei() ? Material::EXTRA : ($request->input('kind') === Material::EXTRA ? Material::EXTRA : Material::CORE);
        $material = Material::create($this->validated($request, $user, $kind) + ['kind' => $kind, 'created_by' => $user->id]);
        $this->saveFiles($request, $material);

        return redirect()->route('manage.materials.index', ['tab' => $this->tabOf($material)])
            ->with('success', "Materi \"{$material->title}\" tersimpan.");
    }

    /**
     * Lihat materi untuk mengajar: tampilan sama seperti peserta (video, PDF, PPT, ringkasan), tanpa lock.
     * Semua staf boleh melihat materi pakem; materi tambahan hanya yang terlihat di daftarnya
     * (sensei: miliknya atau kelas yang dipegang). Tombol Edit hanya muncul bagi yang berhak mengubah.
     */
    public function show(Request $request, Material $material): View
    {
        $user = $request->user();
        if ($material->isExtra()) {
            abort_unless($this->extrasQuery($user)->whereKey($material->id)->exists(), 403, 'Materi tambahan ini milik kelas lain.');
        }

        $siblings = Material::where('kind', $material->kind)
            ->when($material->isExtra(), fn ($q) => $q->where('classroom_id', $material->classroom_id),
                fn ($q) => $q->where('curriculum_id', $material->curriculum_id)->where('chapter_no', $material->chapter_no)->where('chapter', $material->chapter))
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'title', 'category', 'status']);

        return view('manage.materials.show', [
            'material' => $material->load('files', 'exam', 'level', 'curriculum', 'classroom', 'author:id,name'),
            'siblings' => $siblings,
            'editable' => $material->isEditableBy($user),
            'tab' => $this->tabOf($material),
        ]);
    }

    public function edit(Request $request, Material $material): View
    {
        abort_unless($material->isEditableBy($request->user()), 403, $this->denyMessage($material));

        return $this->form($material, $request->user());
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $user = $request->user();
        abort_unless($material->isEditableBy($user), 403, $this->denyMessage($material));

        $material->update($this->validated($request, $user, $material->kind, $material));
        $this->saveFiles($request, $material);

        return redirect()->route('manage.materials.index', ['tab' => $this->tabOf($material)])
            ->with('success', "Materi \"{$material->title}\" diperbarui.");
    }

    public function destroy(Request $request, Material $material): RedirectResponse
    {
        abort_unless($material->isEditableBy($request->user()), 403, $this->denyMessage($material));

        Storage::disk('local')->delete($material->files()->pluck('path')->all());
        $tab = $this->tabOf($material);
        $material->delete();

        return redirect()->route('manage.materials.index', ['tab' => $tab])->with('success', 'Materi dihapus.');
    }

    // ------------------------------------------------------------------

    /** Materi tambahan yang terlihat: sensei hanya miliknya & kelas yang dipegang; staf lain semua. */
    private function extrasQuery(User $user)
    {
        return Material::where('kind', Material::EXTRA)->when($user->isSensei(), fn ($q) => $q->where(fn ($w) => $w
            ->where('created_by', $user->id)->orWhereIn('classroom_id', MaterialAccess::taughtClassroomIds($user))));
    }

    private function tabOf(Material $material): string
    {
        return $material->isExtra() ? 'tambahan' : ($material->curriculum_id ? (string) $material->curriculum_id : 'umum');
    }

    private function denyMessage(Material $material): string
    {
        return $material->isExtra()
            ? 'Materi tambahan hanya bisa diubah sensei pembuatnya atau Admin Pendidikan.'
            : 'Materi pakem hanya bisa diubah Admin Pendidikan.';
    }

    /** Kelas yang boleh dipilih untuk materi tambahan. */
    private function allowedClassrooms(User $user, ?Material $material = null)
    {
        return Classroom::query()
            ->when($user->isSensei(), fn ($q) => $q->whereIn('id', MaterialAccess::taughtClassroomIds($user)))
            ->where(fn ($q) => $q->where('is_active', true)->when($material?->classroom_id, fn ($w) => $w->orWhere('id', $material->classroom_id)))
            ->orderBy('name')->get(['id', 'name']);
    }

    private function form(Material $material, User $user): View
    {
        return view('manage.materials.form', [
            'material' => $material,
            'levels' => $this->levels(),
            'curricula' => Curriculum::where(fn ($q) => $q->where('is_active', true)->orWhere('id', $material->curriculum_id))
                ->orderBy('sort_order')->orderBy('name')->get(),
            'classrooms' => $this->allowedClassrooms($user, $material),
            'canChooseKind' => $user->isEducationAdmin() && ! $material->exists,
            'exams' => Exam::where('status', 'open')->orderByDesc('id')->get(['id', 'title', 'code']),
        ]);
    }

    private function levels()
    {
        return Level::with('testType')->get()->sortBy(fn ($l) => [$l->testType->is_active ? 0 : 1, $l->testType->sort_order, $l->sort_order])->values();
    }

    private function validated(Request $request, User $user, string $kind, ?Material $material = null): array
    {
        $isExtra = $kind === Material::EXTRA;
        $data = $request->validate([
            'curriculum_id' => $isExtra ? ['nullable'] : ['nullable', 'exists:curricula,id'],
            'chapter_no' => ['nullable', 'integer', 'min:1', 'max:999'],
            'classroom_id' => $isExtra ? ['required', Rule::in($this->allowedClassrooms($user, $material)->pluck('id')->all())] : ['nullable'],
            'available_until' => ['nullable', 'date', $material?->available_until ? 'date' : 'after_or_equal:today'],
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
            ...$this->fileRules(),
            'remove_files' => ['nullable', 'array'],
            'remove_files.*' => ['integer'],
            'uploads' => ['nullable', 'array', 'max:'.(MaterialFile::MAX_PER_TYPE * count(MaterialFile::TYPES))],
            'uploads.*' => ['string'],
        ], [
            'classroom_id.required' => 'Pilih kelas tujuan materi tambahan.',
            'classroom_id.in' => 'Materi tambahan hanya untuk kelas yang Anda pegang.',
            'available_until.after_or_equal' => 'Tanggal tayang terakhir tidak boleh sebelum hari ini.',
        ], ['title' => 'judul', 'chapter' => 'judul bab', 'chapter_no' => 'nomor bab', 'video_url' => 'tautan video',
            'curriculum_id' => 'kurikulum', 'classroom_id' => 'kelas', 'available_until' => 'tayang sampai']);

        // Nomor bab harus di dalam cakupan kurikulum, mis. Minna I = Bab 1–25.
        if (! $isExtra && ! empty($data['chapter_no']) && ! empty($data['curriculum_id'])) {
            $c = Curriculum::find($data['curriculum_id']);
            if ($c->chapter_from && ($data['chapter_no'] < $c->chapter_from || ($c->chapter_to && $data['chapter_no'] > $c->chapter_to))) {
                throw ValidationException::withMessages(['chapter_no' => "Nomor bab untuk {$c->name} harus {$c->chapterRange()}."]);
            }
        }

        // Jumlah file per jenis setelah hapus + tambah tidak boleh melebihi batas.
        $removed = collect($data['remove_files'] ?? [])->map(fn ($id) => (int) $id);
        $kept = $material ? $material->files()->whereNotIn('id', $removed)->get(['id', 'type']) : collect();
        // File dari uploader bertahap (sudah di server, belum diklaim).
        $uploaded = collect(app(ChunkedUploads::class)->completed($user, $data['uploads'] ?? []));
        foreach (MaterialFile::TYPES as $type => [$label]) {
            $total = $kept->where('type', $type)->count() + count($request->file($type.'s', []))
                + $uploaded->where('type', $type)->count();
            if ($total > MaterialFile::MAX_PER_TYPE) {
                throw ValidationException::withMessages([$type.'s' => "Maksimal ".MaterialFile::MAX_PER_TYPE." file {$label} per materi."]);
            }
        }

        $hasNewFiles = $uploaded->isNotEmpty() || collect(array_keys(MaterialFile::TYPES))->contains(fn ($t) => $request->hasFile($t.'s'));
        $hasContent = filled($data['video_url'] ?? null) || filled($data['description'] ?? null) || $hasNewFiles || $kept->isNotEmpty();
        if (! $hasContent) {
            throw ValidationException::withMessages(['video_url' => 'Isi minimal satu: tautan video, file video/PDF/PPT, atau keterangan.']);
        }
        unset($data['videos'], $data['pdfs'], $data['ppts'], $data['remove_files'], $data['uploads']);
        $data['sort_order'] = $data['sort_order'] ?? 0;
        if ($isExtra) {
            $data['curriculum_id'] = null;
        } else {
            $data['curriculum_id'] = $data['curriculum_id'] ?? Curriculum::defaultOpenId();
            $data['classroom_id'] = null;
        }

        return $data;
    }

    /** Aturan validasi file: videos[], pdfs[], ppts[] — beberapa file per jenis. */
    private function fileRules(): array
    {
        $rules = [];
        foreach (MaterialFile::TYPES as $type => [, , $mimes, $maxKb]) {
            $rules[$type.'s'] = ['nullable', 'array', 'max:'.MaterialFile::MAX_PER_TYPE];
            $rules[$type.'s.*'] = ['file', 'mimes:'.$mimes, 'max:'.$maxKb];
        }

        return $rules;
    }

    /** Hapus file yang dicentang, lalu simpan semua file baru (urutan sesuai pilihan). */
    private function saveFiles(Request $request, Material $material): void
    {
        $remove = $material->files()->whereIn('id', (array) $request->input('remove_files', []))->get();
        Storage::disk('local')->delete($remove->pluck('path')->all());
        $material->files()->whereIn('id', $remove->pluck('id'))->delete();

        $order = (int) $material->files()->max('sort_order');
        app(ChunkedUploads::class)->claim($request->user(), (array) $request->input('uploads', []), $material, $order);
        foreach (array_keys(MaterialFile::TYPES) as $type) {
            foreach ($request->file($type.'s', []) as $file) {
                $material->files()->create([
                    'type' => $type,
                    'path' => $file->store("materials/{$material->id}", 'local'),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'size' => $file->getSize(),
                    'sort_order' => ++$order,
                ]);
            }
        }
    }
}
