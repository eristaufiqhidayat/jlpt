<?php

namespace App\Http\Controllers\Manage;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\ClassSession;
use App\Models\Level;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Kelas, anggota, jadwal sesi, dan absensi (sensei & admin). */
class ClassroomController extends Controller
{
    public function index(): View
    {
        return view('manage.classes.index', [
            'classrooms' => Classroom::with(['level', 'sensei:id,name'])->withCount('students')
                ->withCount(['sessions as upcoming_count' => fn ($q) => $q->where('date', '>=', today())])
                ->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Classroom(['is_active' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $classroom = Classroom::create($this->validated($request));

        return redirect()->route('manage.classes.show', $classroom)->with('success', "Kelas {$classroom->name} dibuat. Tambahkan peserta dan jadwal.");
    }

    public function show(Classroom $class): View
    {
        $class->load(['level', 'sensei:id,name', 'students.profile']);
        $sessions = $class->sessions()->with('sensei:id,name')->withCount(['attendances as present_count' => fn ($q) => $q->where('status', 'hadir')])->get();

        return view('manage.classes.show', [
            'class' => $class,
            'sessions' => $sessions,
            'available' => User::where('role', Role::Student)->where('is_active', true)
                ->whereNotIn('id', $class->students->pluck('id'))->orderBy('name')->get(['id', 'name', 'email']),
            'senseis' => $this->senseis(),
        ]);
    }

    public function edit(Classroom $class): View
    {
        return $this->form($class);
    }

    public function update(Request $request, Classroom $class): RedirectResponse
    {
        $class->update($this->validated($request));

        return redirect()->route('manage.classes.show', $class)->with('success', 'Kelas diperbarui.');
    }

    public function addStudents(Request $request, Classroom $class): RedirectResponse
    {
        $data = $request->validate(['student_ids' => ['required', 'array'], 'student_ids.*' => [Rule::exists('users', 'id')->where('role', Role::Student->value)]]);
        $class->students()->syncWithoutDetaching($data['student_ids']);

        return back()->with('success', count($data['student_ids']).' peserta ditambahkan.');
    }

    public function removeStudent(Classroom $class, User $student): RedirectResponse
    {
        $class->students()->detach($student->id);

        return back()->with('success', "{$student->name} dikeluarkan dari kelas.");
    }

    /** Tambah sesi: satu tanggal, atau berulang tiap minggu sampai tanggal tertentu. */
    public function storeSession(Request $request, Classroom $class): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'repeat_until' => ['nullable', 'date', 'after:date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'topic' => ['required', 'string', 'max:150'],
            'sensei_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', ['sensei', 'admin'])],
        ], [], ['date' => 'tanggal', 'starts_at' => 'jam mulai', 'ends_at' => 'jam selesai', 'topic' => 'topik', 'repeat_until' => 'ulangi sampai']);

        $dates = $data['repeat_until'] ?? null
            ? collect(CarbonPeriod::create($data['date'], '1 week', $data['repeat_until']))->take(52)
            : collect([\Illuminate\Support\Carbon::parse($data['date'])]);

        foreach ($dates as $d) {
            $class->sessions()->create([
                'date' => $d->toDateString(), 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                'topic' => $data['topic'], 'sensei_id' => $data['sensei_id'] ?? $class->sensei_id,
            ]);
        }

        return back()->with('success', $dates->count().' sesi ditambahkan ke jadwal.');
    }

    public function destroySession(ClassSession $session): RedirectResponse
    {
        $session->delete();

        return back()->with('success', 'Sesi dihapus.');
    }

    public function attendance(ClassSession $session): View
    {
        $session->load('classroom.students', 'attendances');

        return view('manage.classes.attendance', [
            'session' => $session,
            'records' => $session->attendances->keyBy('student_id'),
        ]);
    }

    public function saveAttendance(Request $request, ClassSession $session): RedirectResponse
    {
        $data = $request->validate(['status' => ['array'], 'status.*' => ['in:'.implode(',', array_keys(Attendance::STATUSES))]]);
        $allowed = $session->classroom->students()->pluck('users.id')->flip();

        DB::transaction(function () use ($data, $session, $allowed) {
            foreach ($data['status'] ?? [] as $studentId => $status) {
                if (isset($allowed[(int) $studentId])) {
                    Attendance::updateOrCreate(['class_session_id' => $session->id, 'student_id' => (int) $studentId], ['status' => $status]);
                }
            }
        });

        return redirect()->route('manage.classes.show', $session->classroom_id)->with('success', 'Kehadiran '.$session->date->translatedFormat('d M Y').' tersimpan.');
    }

    private function form(Classroom $class): View
    {
        return view('manage.classes.form', [
            'class' => $class,
            'levels' => Level::with('testType')->get(),
            'senseis' => $this->senseis(),
        ]);
    }

    private function senseis()
    {
        return User::whereIn('role', [Role::Sensei, Role::Admin])->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'level_id' => ['nullable', 'exists:levels,id'],
            'sensei_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', ['sensei', 'admin'])],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'nama kelas']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
