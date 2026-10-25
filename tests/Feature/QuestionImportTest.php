<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Question;
use App\Models\User;
use App\Services\QuestionImporter;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuestionImportTest extends TestCase
{
    use RefreshDatabase;

    private User $sensei;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        Storage::fake('local');
        $this->sensei = User::factory()->create(['role' => Role::Sensei]);
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows, array $extraSheetsFirst = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new XlsxWriter;
        foreach ($extraSheetsFirst as $name => $sheetRows) {
            $writer->addSheet($name, $sheetRows);
        }
        $writer->addSheet('Soal', $rows)->save($path);

        return new UploadedFile($path, 'soal.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function csv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'soal.csv', 'text/csv', null, true);
    }

    private function preview(UploadedFile $file)
    {
        return $this->actingAs($this->sensei)->post(route('sensei.questions.import.preview'), ['file' => $file]);
    }

    private function row(string $stem, array $overrides = []): array
    {
        return array_values(array_merge([
            'level' => 'N4', 'bagian' => 'Moji Goi', 'soal' => $stem,
            'p1' => 'あめ', 'p2' => 'ゆき', 'p3' => 'くも', 'p4' => 'かぜ', 'jawaban' => '1',
        ], $overrides));
    }

    private const HEADER = ['Level', 'Bagian', 'Soal', 'Pilihan 1', 'Pilihan 2', 'Pilihan 3', 'Pilihan 4', 'Jawaban'];

    public function test_headers_with_spaces_and_capitals_are_recognised(): void
    {
        $response = $this->preview($this->xlsx([self::HEADER, $this->row('あしたは __雨__ が ふるでしょう。')]));

        $response->assertOk()->assertViewHas('valid', 1)->assertViewHas('invalid', 0);
        $this->assertSame(['あめ', 'ゆき', 'くも', 'かぜ'], array_values($response->viewData('rows')[0]['data']['options']));
    }

    public function test_unknown_header_is_reported(): void
    {
        $header = [...self::HEADER, 'Pembahsan'];
        $response = $this->preview($this->xlsx([$header, [...$this->row('テスト'), 'typo']]));

        $response->assertOk();
        $this->assertStringContainsString('Pembahsan', implode(' ', $response->viewData('header_warnings')));
    }

    public function test_sheet_named_soal_is_read_even_when_not_first(): void
    {
        $file = $this->xlsx([self::HEADER, $this->row('テスト')], ['Petunjuk' => [['Kolom', 'Wajib', 'Keterangan']]]);

        $this->preview($file)->assertOk()->assertViewHas('valid', 1);
    }

    public function test_shift_jis_csv_is_converted_and_imported(): void
    {
        $utf8 = "level,bagian,soal,pilihan_1,pilihan_2,jawaban\nN4,Moji Goi,雨がふる,あめ,ゆき,1\n";
        $response = $this->preview($this->csv(mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8')));

        $response->assertOk()->assertViewHas('valid', 1);
        $this->actingAs($this->sensei)->post(route('sensei.questions.import.store'), [
            'token' => $response->viewData('token'), 'skip_duplicates' => '1',
        ])->assertRedirect(route('sensei.questions.index'));

        $this->assertSame('雨がふる', Question::sole()->stem);
    }

    public function test_csv_in_unknown_encoding_is_rejected_with_a_clear_message(): void
    {
        $this->preview($this->csv("level,bagian,soal,pilihan_1,pilihan_2,jawaban\nN4,Moji Goi,caf\xE9 x,a,b,1\n"))
            ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'CSV UTF-8'));
    }

    public function test_star_answer_must_match_order_and_position(): void
    {
        $importer = app(QuestionImporter::class);
        $header = ['level', 'bagian', 'jenis', 'soal', 'pilihan_1', 'pilihan_2', 'pilihan_3', 'pilihan_4', 'jawaban', 'posisi_bintang', 'urutan_benar'];
        $base = ['N4', 'Bunpou Dokkai', 'bintang', 'これは ＿＿ ＿＿ ★ ＿＿ です。', 'デパート', 'で', 'かった', 'くつ'];

        $ok = $importer->parse([$header, [...$base, '1', '3', '2-4-1-3']]);
        $wrong = $importer->parse([$header, [...$base, '3', '3', '2-4-1-3']]);
        $repeated = $importer->parse([$header, [...$base, '1', '3', '1-1-2-3']]);

        $this->assertSame(1, $ok['valid']);
        $this->assertStringContainsString('jawabannya 1', implode(' ', $wrong['rows'][0]['errors']));
        $this->assertSame(1, $repeated['invalid']);
    }

    public function test_duplicates_inside_file_and_in_database_are_skipped(): void
    {
        $first = $this->preview($this->xlsx([self::HEADER, $this->row('一回目'), $this->row('一回目')]));
        $first->assertViewHas('duplicates', 1);
        $this->actingAs($this->sensei)->post(route('sensei.questions.import.store'), [
            'token' => $first->viewData('token'), 'skip_duplicates' => '1',
        ]);
        $this->assertSame(1, Question::count());

        // Upload ulang file yang sama + satu soal baru: hanya soal baru yang masuk.
        $second = $this->preview($this->xlsx([self::HEADER, $this->row('一回目'), $this->row('二回目')]));
        $this->actingAs($this->sensei)->post(route('sensei.questions.import.store'), [
            'token' => $second->viewData('token'), 'skip_duplicates' => '1',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, '1 soal') && str_contains($m, 'soal sudah ada'));

        $this->assertEqualsCanonicalizing(['一回目', '二回目'], Question::pluck('stem')->all());
    }

    public function test_duplicates_are_kept_when_option_is_unchecked(): void
    {
        $preview = $this->preview($this->xlsx([self::HEADER, $this->row('同じ'), $this->row('同じ')]));
        $this->actingAs($this->sensei)->post(route('sensei.questions.import.store'), ['token' => $preview->viewData('token')]);

        $this->assertSame(2, Question::count());
    }

    public function test_submitting_the_same_preview_twice_imports_once(): void
    {
        $token = $this->preview($this->xlsx([self::HEADER, $this->row('一度だけ')]))->viewData('token');

        $this->actingAs($this->sensei)->post(route('sensei.questions.import.store'), ['token' => $token]);
        $this->actingAs($this->sensei)->post(route('sensei.questions.import.store'), ['token' => $token])
            ->assertRedirect(route('sensei.questions.import'))->assertSessionHas('error');

        $this->assertSame(1, Question::count());
    }

    public function test_row_whose_passage_was_deleted_after_preview_is_skipped(): void
    {
        $section = \App\Models\Section::where('code', 'moji_goi')->whereHas('level', fn ($q) => $q->where('code', 'N4'))->firstOrFail();
        $passage = \App\Models\Passage::create([
            'section_id' => $section->id, 'created_by' => $this->sensei->id, 'title' => 'Bacaan', 'content' => '本文', 'status' => 'published',
        ]);
        $header = [...self::HEADER, 'bacaan_id'];
        $token = $this->preview($this->xlsx([$header, [...$this->row('読む'), (string) $passage->id], $this->row('独立')]))
            ->assertViewHas('valid', 2)->viewData('token');

        $passage->delete();

        $this->actingAs($this->sensei)->post(route('sensei.questions.import.store'), ['token' => $token])
            ->assertRedirect(route('sensei.questions.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'sudah dihapus'));
        $this->assertSame(['独立'], Question::pluck('stem')->all());
    }

    public function test_reader_falls_back_to_first_sheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new XlsxWriter)->addSheet('Data', [['level'], ['N5']])->save($path);

        $this->assertSame([['level'], ['N5']], XlsxReader::read($path, 'x.xlsx', 'Soal'));
    }
}
