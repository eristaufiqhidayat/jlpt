<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\Exam;
use App\Models\Material;
use App\Models\Message;
use App\Models\ProgramDocument;
use App\Models\ProgramStage;
use App\Models\StudentProfile;
use App\Models\Level;
use App\Models\Passage;
use App\Models\Question;
use App\Models\User;
use App\Models\ViolationCategory;
use Illuminate\Database\Seeder;

/**
 * Data contoh untuk development (hanya dijalankan di APP_ENV=local):
 * sensei@example.com / student@example.com / pendidikan@example.com (password: password),
 * beberapa soal N4, dan satu ujian gratis berkode DEMON4.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $sensei = User::firstOrCreate(['email' => 'sensei@example.com'], [
            'name' => 'Demo Sensei', 'password' => 'password', 'role' => Role::Sensei,
        ]);
        $student = User::firstOrCreate(['email' => 'student@example.com'], [
            'name' => 'Demo Student', 'password' => 'password', 'role' => Role::Student,
        ]);
        User::firstOrCreate(['email' => 'direktur@example.com'], [
            'name' => 'Demo Direktur', 'password' => 'password', 'role' => Role::Director,
        ]);
        User::firstOrCreate(['email' => 'pendidikan@example.com'], [
            'name' => 'Demo Admin Pendidikan', 'password' => 'password', 'role' => Role::EducationAdmin,
        ]);

        // Contoh kategori batsu (pelanggaran); isi sebenarnya ditentukan Admin Pendidikan.
        foreach ([['Terlambat', 'Datang lewat 10 menit tanpa kabar', 'warn'], ['Alpa', 'Tidak hadir tanpa izin', 'err'],
            ['Ponsel', 'Memakai ponsel saat kelas atau ujian', 'info'], ['Seragam', 'Tidak memakai seragam/kartu peserta', 'grey']] as $i => [$name, $desc, $color]) {
            ViolationCategory::firstOrCreate(['name' => $name], ['description' => $desc, 'color' => $color, 'sort_order' => $i + 1]);
        }

        $level = Level::whereHas('testType', fn ($q) => $q->where('code', 'JLPT'))
            ->where('code', 'N4')->with('sections')->firstOrFail();
        $sections = $level->sections->keyBy('code');

        // Moji Goi: cara baca kanji yang digarisbawahi
        $this->question($sections['moji_goi']->id, $sensei->id, QuestionType::Blank,
            'あしたは __雨__ が ふるでしょう。', ['あめ', 'ゆき', 'くも', 'かぜ'], 1, '問題1');
        $this->question($sections['moji_goi']->id, $sensei->id, QuestionType::Blank,
            'まいあさ __駅__ まで あるきます。', ['えき', 'みせ', 'いえ', 'まち'], 1, '問題1');
        $this->question($sections['moji_goi']->id, $sensei->id, QuestionType::Blank,
            'この かばんは とても（　　）です。', ['おもい', 'ながい', 'はやい', 'ひくい'], 1, '問題3');

        // Bunpou: isian partikel dan soal ★
        $this->question($sections['bunpou_dokkai']->id, $sensei->id, QuestionType::Blank,
            'わたしは まいにち バス（　　）がっこうへ いきます。', ['で', 'を', 'に', 'が'], 1, '問題1');
        $star = $this->question($sections['bunpou_dokkai']->id, $sensei->id, QuestionType::Star,
            'これは きのう ＿＿ ＿＿ ★ ＿＿ です。', ['デパート', 'で', 'かった', 'くつ'], 3, '問題2');
        $star->update(['star_position' => 3, 'correct_order' => '1-2-3-4']);

        // Dokkai: dua soal berbagi satu bacaan
        $passage = Passage::create([
            'section_id' => $sections['bunpou_dokkai']->id,
            'created_by' => $sensei->id,
            'title' => 'メモ',
            'content' => "リンさんへ\nあしたの ごご 3じに としょかんの まえで あいましょう。\nほんを 2さつ もって きて ください。\nたなか",
            'status' => ContentStatus::Published,
        ]);
        $this->question($sections['bunpou_dokkai']->id, $sensei->id, QuestionType::Reading,
            'リンさんは あした なんじに どこへ いきますか。',
            ['3じに としょかんへ', '3じに がっこうへ', '2じに としょかんへ', '2じに えきへ'], 1, '問題4', $passage->id, 1);
        $this->question($sections['bunpou_dokkai']->id, $sensei->id, QuestionType::Reading,
            'リンさんは なにを もって いきますか。',
            ['ほんを 2さつ', 'ほんを 3さつ', 'ノートを 2さつ', 'なにも もって いかない'], 1, '問題4', $passage->id, 2);

        // Choukai: tanpa audio di data demo; pilihan hanya teks
        $this->question($sections['choukai']->id, $sensei->id, QuestionType::Listening,
            '（音声）おとこの ひとは このあと なにを しますか。', ['かいものに いく', 'ひるごはんを たべる', 'しゅくだいを する', 'でんわを する'], 2, '問題1');

        $exam = Exam::firstOrCreate(['code' => 'DEMON4'], [
            'sensei_id' => $sensei->id,
            'level_id' => $level->id,
            'title' => 'Latihan JLPT N4 (demo)',
            'status' => ExamStatus::Open,
            'is_paid' => false,
        ]);

        foreach ([['moji_goi', 3], ['bunpou_dokkai', 4], ['choukai', 1]] as $i => [$code, $count]) {
            $exam->sections()->firstOrCreate(['section_id' => $sections[$code]->id], [
                'sort_order' => $i + 1,
                'duration_minutes' => $sections[$code]->default_duration_minutes,
                'question_count' => $count,
            ]);
        }

        $this->lms($sensei, $student, $level, $exam);
    }

    /** Kelas, jadwal, absensi, materi, Program Jepang, dan pesan contoh. */
    private function lms(User $sensei, User $student, Level $level, Exam $exam): void
    {
        StudentProfile::updateOrCreate(['user_id' => $student->id], [
            'nis' => '2026001', 'level_id' => $level->id, 'program_name' => 'Tokutei Ginou – Kaigo', 'joined_at' => now()->subMonths(4)->toDateString(),
        ]);
        $minna1 = Curriculum::where('code', 'MINNA1')->first();
        $minna2 = Curriculum::where('code', 'MINNA2')->first();
        $class = Classroom::firstOrCreate(['name' => 'N4-A'], ['level_id' => $level->id, 'sensei_id' => $sensei->id, 'is_active' => true]);
        if ($minna1 && ! $class->curriculum_id) {
            $class->update(['curriculum_id' => $minna1->id]); // peserta demo di Minna I → Minna II terkunci
        }
        $class->students()->syncWithoutDetaching([$student->id]);

        if (! $class->sessions()->exists()) {
            foreach ([-7 => 'Bunpou: ～てもいい', 0 => 'Kanji Bab 6', 7 => 'Choukai latihan 1'] as $offset => $topic) {
                $session = $class->sessions()->create([
                    'sensei_id' => $sensei->id, 'date' => today()->addDays($offset)->toDateString(),
                    'starts_at' => '09:00', 'ends_at' => '10:30', 'topic' => $topic,
                ]);
                if ($offset < 0) {
                    $session->attendances()->create(['student_id' => $student->id, 'status' => 'hadir']);
                }
            }
        }

        if (! Material::exists()) {
            $items = [
                [5, 'Partikel', 'Partikel で dan に', 'Bunpou', "で menandai tempat kegiatan, に menandai tujuan/keberadaan.\n{図書館|としょかん}で べんきょうします。", $exam->id],
                [5, 'Partikel', 'Partikel を dan が', 'Bunpou', 'を menandai objek; が menandai subjek/yang disukai.', null],
                [6, 'Kanji', 'Kanji waktu: 時・分・半', 'Kanji', '{時|じ}、{分|ふん}、{半|はん}', null],
            ];
            foreach ($items as $i => [$chapterNo, $chapter, $title, $category, $desc, $examId]) {
                Material::create([
                    'curriculum_id' => $minna1?->id, 'chapter_no' => $chapterNo,
                    'level_id' => $level->id, 'chapter' => $chapter, 'title' => $title, 'category' => $category,
                    'description' => $desc, 'duration_minutes' => 15, 'sort_order' => $i, 'exam_id' => $examId,
                    'status' => 'published', 'created_by' => $sensei->id,
                ]);
            }
        }

        // Contoh materi Minna II (terkunci untuk peserta demo) dan materi tambahan sensei untuk kelas N4-A.
        Material::firstOrCreate(['title' => 'Bentuk biasa (普通形)'], [
            'kind' => Material::CORE, 'curriculum_id' => $minna2?->id, 'chapter_no' => 26, 'chapter' => '～んです',
            'category' => 'Bunpou', 'description' => '行く → 行くんです', 'duration_minutes' => 15, 'status' => 'published', 'created_by' => $sensei->id,
        ]);
        Material::firstOrCreate(['title' => 'Kartu kanji minggu ini'], [
            'kind' => Material::EXTRA, 'classroom_id' => $class->id, 'category' => 'Kanji', 'description' => '{読|よ}む・{書|か}く・{聞|き}く',
            'available_until' => today()->addWeeks(2)->toDateString(), 'status' => 'published', 'created_by' => $sensei->id,
        ]);

        foreach (['pendaftaran' => 'selesai', 'belajar' => 'proses'] as $stage => $status) {
            ProgramStage::updateOrCreate(['student_id' => $student->id, 'stage' => $stage], ['status' => $status, 'date' => now()->subMonths(4)->toDateString()]);
        }
        ProgramDocument::updateOrCreate(['student_id' => $student->id, 'type' => 'paspor'], ['status' => 'proses']);

        Message::firstOrCreate(['sender_id' => $sensei->id, 'recipient_id' => $student->id, 'subject' => 'Selamat datang di kelas N4-A'], [
            'body' => "Halo! Jadwal kelas ada di menu Kelas Saya. Jangan lupa kerjakan latihan DEMON4.\n頑張ってください！",
        ]);
    }

    /** @param list<string> $options */
    private function question(
        int $sectionId, int $userId, QuestionType $type, string $stem, array $options,
        int $correct, string $mondai, ?int $passageId = null, ?int $number = null,
    ): Question {
        $question = Question::create([
            'section_id' => $sectionId,
            'passage_id' => $passageId,
            'number_in_passage' => $number,
            'created_by' => $userId,
            'type' => $type,
            'mondai' => $mondai,
            'stem' => $stem,
            'status' => ContentStatus::Published,
        ]);

        foreach ($options as $i => $content) {
            $question->options()->create([
                'label' => $i + 1,
                'content' => $content,
                'is_correct' => $i + 1 === $correct,
            ]);
        }

        return $question;
    }
}
