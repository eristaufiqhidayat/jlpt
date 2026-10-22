<?php

namespace App\Http\Controllers\Manage;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\ProgramDocument;
use App\Models\ProgramStage;
use App\Models\User;
use App\Support\ProgramJepang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Monitoring program Jepang per peserta. Sensei & admin mengubah; direktur hanya melihat. */
class ProgramController extends Controller
{
    public function index(Request $request): View
    {
        $students = User::where('role', Role::Student)
            ->with(['profile.level', 'programStages', 'classrooms:id,name'])
            ->when($request->filled('classroom_id'), fn ($q) => $q->whereHas('classrooms', fn ($c) => $c->where('classrooms.id', $request->integer('classroom_id'))))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhereHas('profile', fn ($p) => $p->where('nis', 'like', '%'.$request->string('q').'%'))))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('manage.program.index', [
            'students' => $students,
            'classrooms' => Classroom::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, User $student): View
    {
        abort_unless($student->isStudent(), 404);
        $student->load(['profile.level', 'programStages', 'programDocuments', 'classrooms']);

        return view('program.show', [
            'student' => $student,
            'stages' => $student->programStages->keyBy('stage'),
            'documents' => $student->programDocuments->keyBy('type'),
            'progress' => ProgramJepang::progress($student->programStages),
            'editable' => ! $request->user()->isDirector(),
        ]);
    }

    public function update(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->isStudent() && ! $request->user()->isDirector(), 403);
        $statuses = implode(',', array_keys(ProgramJepang::STATUSES));
        $data = $request->validate([
            'stages' => ['array'],
            'stages.*.status' => ['required', 'in:'.$statuses],
            'stages.*.date' => ['nullable', 'date'],
            'stages.*.note' => ['nullable', 'string', 'max:255'],
            'documents' => ['array'],
            'documents.*.status' => ['required', 'in:'.$statuses],
            'documents.*.note' => ['nullable', 'string', 'max:255'],
            'files' => ['array'],
            'files.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        DB::transaction(function () use ($data, $request, $student) {
            foreach ($data['stages'] ?? [] as $stage => $row) {
                if (isset(ProgramJepang::STAGES[$stage])) {
                    ProgramStage::updateOrCreate(['student_id' => $student->id, 'stage' => $stage],
                        ['status' => $row['status'], 'date' => $row['date'] ?? null, 'note' => $row['note'] ?? null, 'updated_by' => $request->user()->id]);
                }
            }
            foreach ($data['documents'] ?? [] as $type => $row) {
                if (! isset(ProgramJepang::DOCUMENTS[$type])) {
                    continue;
                }
                $doc = ProgramDocument::firstOrNew(['student_id' => $student->id, 'type' => $type]);
                if ($request->hasFile("files.$type")) {
                    $doc->file_path && Storage::disk('local')->delete($doc->file_path);
                    $doc->file_path = $request->file("files.$type")->store("program/{$student->id}", 'local');
                }
                $doc->fill(['status' => $row['status'], 'note' => $row['note'] ?? null, 'updated_by' => $request->user()->id])->save();
            }
        });

        return back()->with('success', 'Data program Jepang '.$student->name.' diperbarui.');
    }

    public function document(Request $request, ProgramDocument $document): StreamedResponse
    {
        $user = $request->user();
        abort_unless($document->student_id === $user->id || ! $user->isExamTaker(), 403);
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->response($document->file_path);
    }
}
