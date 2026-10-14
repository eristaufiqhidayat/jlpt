<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Pesan sederhana: admin/sensei mengirim ke peserta atau satu kelas; semua bisa membalas pesan yang diterima.
 */
class MessageController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('messages.index', [
            'messages' => $user->inbox()->with('sender:id,name,role')->latest()->paginate(20),
            'sent' => Message::with('recipient:id,name')->where('sender_id', $user->id)->latest()->take(10)->get(),
        ]);
    }

    public function show(Request $request, Message $message): View
    {
        $user = $request->user();
        abort_unless($message->recipient_id === $user->id || $message->sender_id === $user->id, 403);
        if ($message->recipient_id === $user->id && ! $message->read_at) {
            $message->update(['read_at' => now()]);
        }

        return view('messages.show', ['message' => $message->load('sender', 'recipient')]);
    }

    public function create(Request $request): View
    {
        $this->ensureCanBroadcast($request->user());

        return view('messages.create', [
            'students' => User::where('role', Role::Student)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'classrooms' => Classroom::where('is_active', true)->withCount('students')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'target' => ['required', 'in:student,classroom,reply'],
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'reply_to' => ['nullable', 'integer', 'exists:messages,id'],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ], [], ['subject' => 'judul', 'body' => 'isi pesan']);

        $recipients = match ($data['target']) {
            'reply' => $this->replyRecipient($user, (int) ($data['reply_to'] ?? 0)),
            'student' => tap(collect([$data['recipient_id'] ?? null])->filter(), fn () => $this->ensureCanBroadcast($user)),
            'classroom' => tap(Classroom::findOrFail($data['classroom_id'] ?? 0)->students()->pluck('users.id'), fn () => $this->ensureCanBroadcast($user)),
        };
        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['recipient_id' => 'Pilih penerima pesan.']);
        }

        foreach ($recipients as $id) {
            Message::create(['sender_id' => $user->id, 'recipient_id' => $id, 'subject' => $data['subject'], 'body' => $data['body']]);
        }

        return redirect()->route('messages.index')->with('success', 'Pesan terkirim ke '.$recipients->count().' penerima.');
    }

    private function replyRecipient(User $user, int $messageId)
    {
        $original = Message::findOrFail($messageId);
        abort_unless($original->recipient_id === $user->id && $original->sender_id, 403);

        return collect([$original->sender_id]);
    }

    private function ensureCanBroadcast(User $user): void
    {
        abort_unless($user->isAcademicAdmin() || $user->isSensei(), 403);
    }
}
