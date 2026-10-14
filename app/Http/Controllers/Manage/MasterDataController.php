<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Curriculum;
use App\Models\Level;
use App\Models\MasterDataLog;
use App\Models\Section;
use App\Models\TestType;
use App\Models\ViolationCategory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Data master akademik (notulen JGG Learning System): klasifikasi kelas, klasifikasi ujian,
 * skor & batas lulus, dan kategori batsu.
 *
 * - Semua staf boleh melihat; hanya Admin Pendidikan yang boleh mengubah (dibatasi di routes/web.php).
 * - Tidak ada hapus: data yang sudah dipakai kelas, materi, soal, atau nilai cukup dinonaktifkan.
 *   Data nonaktif tidak muncul sebagai pilihan saat membuat kelas/ujian baru, tetapi riwayat tetap utuh.
 * - Setiap tambah/ubah/aktif/nonaktif dicatat di master_data_logs.
 */
class MasterDataController extends Controller
{
    public const ENTITIES = ['curriculum', 'test-type', 'level', 'section', 'violation-category'];

    public const TABS = [
        'kelas' => 'Klasifikasi Kelas',
        'ujian' => 'Klasifikasi Ujian',
        'skor' => 'Skor & Batas Lulus',
        'batsu' => 'Kategori Batsu',
    ];

    private const CLASSES = [
        'curriculum' => Curriculum::class,
        'test-type' => TestType::class,
        'level' => Level::class,
        'section' => Section::class,
        'violation-category' => ViolationCategory::class,
    ];

    public function index(Request $request): View
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'kelas';

        $studentsPerCurriculum = DB::table('classroom_student')
            ->join('classrooms', 'classrooms.id', '=', 'classroom_student.classroom_id')
            ->whereNotNull('classrooms.curriculum_id')
            ->where('classrooms.is_active', true)
            ->groupBy('classrooms.curriculum_id')
            ->selectRaw('classrooms.curriculum_id, COUNT(DISTINCT classroom_student.student_id) as total')
            ->pluck('total', 'curriculum_id');

        return view('manage.master.index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'canEdit' => $request->user()->isEducationAdmin(),
            'curricula' => Curriculum::withCount(['classrooms as active_classes_count' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('sort_order')->orderBy('name')->get(),
            'studentsPerCurriculum' => $studentsPerCurriculum,
            'testTypes' => TestType::with([
                'levels' => fn ($q) => $q->withCount('exams'),
                'levels.sections' => fn ($q) => $q->withCount('questions'),
            ])->orderBy('sort_order')->get(),
            'categories' => ViolationCategory::orderBy('sort_order')->orderBy('name')->get(),
            'logs' => MasterDataLog::with('user:id,name,role')->latest('id')->take(10)->get(),
        ]);
    }

    public function create(Request $request, string $entity): View
    {
        $class = self::CLASSES[$entity];
        $model = new $class;
        // Isi awal dari tautan, mis. "+ Level" di bawah satu jenis tes mengirim ?test_type_id=…
        foreach (['test_type_id', 'level_id'] as $key) {
            if ($request->filled($key) && in_array($key, $model->getFillable(), true)) {
                $model->{$key} = $request->integer($key);
            }
        }

        return $this->form($entity, $model);
    }

    public function store(Request $request, string $entity): RedirectResponse
    {
        $class = self::CLASSES[$entity];
        $model = new $class;
        $config = $this->config($entity, $model, $request);
        $data = $request->validate($this->rules($config), [], $this->attributes($config));

        $model->fill($data)->save();
        $changes = collect($config['fields'])->keys()
            ->filter(fn ($f) => filled($model->{$f}))
            ->map(fn ($f) => ['field' => $config['fields'][$f]['label'], 'old' => null, 'new' => $this->display($config['fields'][$f], $model->{$f})])
            ->values()->all();
        $this->log($request, $entity, $model, 'created', $changes);

        return redirect()->route('manage.master.index', ['tab' => $config['tab']])
            ->with('success', ucfirst($config['title'])." \"{$model->masterLabel()}\" ditambahkan.");
    }

    public function edit(string $entity, int $id): View
    {
        return $this->form($entity, $this->find($entity, $id));
    }

    public function update(Request $request, string $entity, int $id): RedirectResponse
    {
        $model = $this->find($entity, $id);
        $config = $this->config($entity, $model, $request);
        $data = $request->validate($this->rules($config), [], $this->attributes($config));

        $model->fill($data);
        $changes = collect($model->getDirty())->keys()
            ->filter(fn ($f) => isset($config['fields'][$f]))
            ->map(fn ($f) => [
                'field' => $config['fields'][$f]['label'],
                'old' => $this->display($config['fields'][$f], $model->getOriginal($f)),
                'new' => $this->display($config['fields'][$f], $model->{$f}),
            ])
            ->filter(fn ($c) => $c['old'] !== $c['new'])
            ->values()->all();

        if (! $changes) {
            return redirect()->route('manage.master.index', ['tab' => $config['tab']])->with('success', 'Tidak ada perubahan.');
        }

        $model->save();
        $this->log($request, $entity, $model, 'updated', $changes);

        return redirect()->route('manage.master.index', ['tab' => $config['tab']])
            ->with('success', ucfirst($config['title'])." \"{$model->masterLabel()}\" diperbarui.");
    }

    /** Aktif ↔ nonaktif. Tidak ada hapus supaya kelas, soal, dan nilai lama tetap utuh. */
    public function toggle(Request $request, string $entity, int $id): RedirectResponse
    {
        $model = $this->find($entity, $id);
        $config = $this->config($entity, $model, $request);

        $model->is_active = ! $model->is_active;
        $model->save();
        $this->log($request, $entity, $model, $model->is_active ? 'activated' : 'deactivated', []);

        $label = $model->masterLabel();

        return redirect()->route('manage.master.index', ['tab' => $config['tab']])
            ->with('success', $model->is_active ? "\"{$label}\" diaktifkan." : "\"{$label}\" dinonaktifkan. Data lama tetap tersimpan; tidak muncul lagi sebagai pilihan baru.");
    }

    // ------------------------------------------------------------------

    private function find(string $entity, int $id): Model
    {
        return self::CLASSES[$entity]::findOrFail($id);
    }

    private function form(string $entity, Model $model): View
    {
        $config = $this->config($entity, $model, request());

        return view('manage.master.form', ['entity' => $entity, 'model' => $model, 'config' => $config]);
    }

    private function log(Request $request, string $entity, Model $model, string $action, array $changes): void
    {
        MasterDataLog::create([
            'user_id' => $request->user()?->id,
            'subject_type' => $entity,
            'subject_id' => $model->getKey(),
            'subject_label' => mb_substr($model->masterLabel(), 0, 150),
            'action' => $action,
            'changes' => $changes ?: null,
        ]);
    }

    /** Nilai yang ditampilkan di log: pilihan diganti labelnya, tanggal diformat. */
    private function display(array $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (isset($field['options'])) {
            // Pilihan bisa dikelompokkan (["JLPT" => [id => label]]); ratakan tanpa mengubah key/ID.
            $flat = [];
            foreach ($field['options'] as $key => $label) {
                if (is_array($label)) {
                    $flat += $label;
                } else {
                    $flat[$key] = $label;
                }
            }

            return (string) ($flat[$value] ?? $value);
        }

        return (string) $value;
    }

    private function rules(array $config): array
    {
        return collect($config['fields'])->reject(fn ($f) => $f['readonly'] ?? false)->map->rules->all();
    }

    private function attributes(array $config): array
    {
        return collect($config['fields'])->map(fn ($f) => mb_strtolower($f['label']))->all();
    }

    /**
     * Definisi tiap jenis data master: judul, tab asal, dan kolom form beserta aturan validasinya.
     *
     * @return array{title: string, tab: string, intro: string, fields: array<string, array>}
     */
    private function config(string $entity, Model $model, Request $request): array
    {
        $id = $model->getKey();

        return match ($entity) {
            'curriculum' => [
                'title' => 'kurikulum',
                'tab' => 'kelas',
                'intro' => 'Klasifikasi kelas dipakai saat membuat kelas. Urutan menentukan lock: peserta harus naik kelas untuk membuka kurikulum berikutnya.',
                'fields' => [
                    'group' => ['label' => 'Kelompok', 'type' => 'text', 'list' => ['Kelas Basic', 'Kelas JFT', 'Kelas N3', 'Kelas SSW'],
                        'rules' => ['required', 'string', 'max:40'], 'hint' => 'mis. Kelas Basic, Kelas SSW'],
                    'name' => ['label' => 'Nama kurikulum', 'type' => 'text', 'rules' => ['required', 'string', 'max:100'], 'hint' => 'mis. Minna no Nihongo I, SSW · Kaigo'],
                    'code' => ['label' => 'Kode', 'type' => 'text', 'rules' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('curricula', 'code')->ignore($id)],
                        'hint' => 'Huruf besar, angka, dan tanda minus. mis. MINNA1, SSW-KAIGO'],
                    'scope' => ['label' => 'Cakupan', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:150'], 'hint' => 'Opsional, mis. tata bahasa, kanji, dokkai'],
                    'chapter_from' => ['label' => 'Bab dari', 'type' => 'number', 'rules' => ['nullable', 'integer', 'min:1', 'max:999']],
                    'chapter_to' => ['label' => 'Bab sampai', 'type' => 'number', 'rules' => ['nullable', 'integer', 'min:1', 'max:999', 'gte:chapter_from', 'required_with:chapter_from']],
                    'sort_order' => ['label' => 'Urutan', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:999'],
                        'hint' => 'Angka kecil dibuka lebih dulu. Kurikulum sejajar (mis. bidang SSW) boleh memakai angka yang sama.'],
                ],
            ],
            'test-type' => [
                'title' => 'jenis tes',
                'tab' => $request->query('from') === 'skor' || $request->input('from') === 'skor' ? 'skor' : 'ujian',
                'intro' => 'Skor maksimal dan batas lulus diisi setelah angkanya disetujui manajemen. Setiap perubahan tercatat di log.',
                'fields' => [
                    'code' => ['label' => 'Kode', 'type' => 'text', 'readonly' => $model->exists,
                        'rules' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9]+$/', Rule::unique('test_types', 'code')->ignore($id)],
                        'hint' => $model->exists ? 'Kode tidak bisa diubah karena dipakai impor data lama.' : 'Huruf besar dan angka, mis. JTEST'],
                    'name' => ['label' => 'Nama', 'type' => 'text', 'rules' => ['required', 'string', 'max:255']],
                    'sort_order' => ['label' => 'Urutan tampil', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:999']],
                    'max_score' => ['label' => 'Skor maksimal', 'type' => 'number', 'rules' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_with:pass_score'],
                        'hint' => 'Tiap soal 1 poin; nilai akhir dikoreksi manual ke skala ini. mis. JLPT 180, J-Test 350.'],
                    'pass_score' => ['label' => 'Batas lulus simulasi', 'type' => 'number', 'rules' => ['nullable', 'integer', 'min:1', 'max:1000', 'lte:max_score'],
                        'hint' => 'Dalam skala skor maksimal. Kosongkan bila belum ditentukan.'],
                    'score_approved_by' => ['label' => 'Disetujui oleh', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:100', 'required_with:max_score'],
                        'hint' => 'Nama/jabatan yang menyetujui angka skor, mis. Direktur'],
                    'score_approved_at' => ['label' => 'Tanggal disetujui', 'type' => 'date', 'rules' => ['nullable', 'date', 'before_or_equal:today']],
                ],
            ],
            'level' => [
                'title' => 'level',
                'tab' => 'ujian',
                'intro' => 'Level di bawah satu jenis tes, mis. JLPT N4, NAT-Test 4Q, SSW Kaigo.',
                'fields' => [
                    'test_type_id' => ['label' => 'Jenis tes', 'type' => 'select', 'options' => TestType::orderBy('sort_order')->pluck('name', 'id')->all(),
                        'rules' => ['required', 'exists:test_types,id']],
                    'code' => ['label' => 'Kode level', 'type' => 'text', 'rules' => ['required', 'string', 'max:20',
                        Rule::unique('levels', 'code')->where('test_type_id', $request->input('test_type_id', $model->test_type_id))->ignore($id)],
                        'hint' => 'mis. N4, 4Q, A2.1, KAIGO'],
                    'name' => ['label' => 'Nama', 'type' => 'text', 'rules' => ['required', 'string', 'max:255'], 'hint' => 'mis. NAT-Test 4Q (N4)'],
                    'sort_order' => ['label' => 'Urutan', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:999']],
                ],
            ],
            'section' => [
                'title' => 'bagian',
                'tab' => 'ujian',
                'intro' => 'Bagian ujian dan durasi standarnya. Sensei masih bisa mengubah durasi per ujian.',
                'fields' => [
                    'level_id' => ['label' => 'Level', 'type' => 'select', 'options' => $this->levelOptions(), 'rules' => ['required', 'exists:levels,id']],
                    'code' => ['label' => 'Kode bagian', 'type' => 'text', 'rules' => ['required', 'string', 'max:30', 'alpha_dash',
                        Rule::unique('sections', 'code')->where('level_id', $request->input('level_id', $model->level_id))->ignore($id)],
                        'hint' => 'Huruf kecil dan garis bawah, mis. moji_goi, choukai'],
                    'name' => ['label' => 'Nama bagian', 'type' => 'text', 'rules' => ['required', 'string', 'max:255'], 'hint' => 'mis. Choukai (聴解)'],
                    'default_duration_minutes' => ['label' => 'Durasi standar (menit)', 'type' => 'number', 'rules' => ['required', 'integer', 'min:1', 'max:600']],
                    'default_question_count' => ['label' => 'Jumlah soal standar', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:500'],
                        'hint' => '0 = ditentukan saat membuat ujian'],
                    'sort_order' => ['label' => 'Urutan', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:999']],
                ],
            ],
            'violation-category' => [
                'title' => 'kategori batsu',
                'tab' => 'batsu',
                'intro' => 'Kategori yang dipilih sensei saat mencatat pelanggaran peserta.',
                'fields' => [
                    'name' => ['label' => 'Nama kategori', 'type' => 'text', 'rules' => ['required', 'string', 'max:50', Rule::unique('violation_categories', 'name')->ignore($id)]],
                    'description' => ['label' => 'Contoh / keterangan', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:150']],
                    'color' => ['label' => 'Warna label', 'type' => 'select', 'options' => ViolationCategory::COLORS, 'rules' => ['required', Rule::in(array_keys(ViolationCategory::COLORS))]],
                    'sort_order' => ['label' => 'Urutan', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:999']],
                ],
            ],
        };
    }

    /** Pilihan level dikelompokkan per jenis tes: ["JLPT" => [id => "N4 – JLPT N4"], …]. */
    private function levelOptions(): array
    {
        return TestType::with('levels')->orderBy('sort_order')->get()
            ->mapWithKeys(fn ($type) => [$type->name => $type->levels->mapWithKeys(fn ($l) => [$l->id => "{$l->code} – {$l->name}"])->all()])
            ->filter()->all();
    }
}
