<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BackupLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'name' => "O'Brien"]);
    }

    public function test_only_admin_can_open_backup_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Sensei]))
            ->get(route('admin.backup.index'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin.backup.index'))
            ->assertOk()->assertSee('Backup Tabel')->assertSee('value="users"', false);
    }

    public function test_sql_backup_contains_structure_and_escaped_data(): void
    {
        $res = $this->actingAs($this->admin())->post(route('admin.backup.download'), [
            'tables' => ['users'], 'format' => 'sql',
            'sql_structure' => '1', 'sql_data' => '1', 'sql_drop' => '1',
        ]);

        $res->assertOk();
        $this->assertStringContainsString('.sql', $res->headers->get('content-disposition'));
        $sql = $res->streamedContent();
        $this->assertStringContainsString('DROP TABLE IF EXISTS "users";', $sql);
        $this->assertStringContainsString('CREATE TABLE "users"', $sql);
        $this->assertStringContainsString('INSERT INTO "users"', $sql);
        $this->assertStringContainsString("'O''Brien'", $sql);
    }

    public function test_sql_backup_requires_structure_or_data(): void
    {
        $this->actingAs($this->admin())->from(route('admin.backup.index'))
            ->post(route('admin.backup.download'), [
                'tables' => ['users'], 'format' => 'sql', 'sql_structure' => '0', 'sql_data' => '0',
            ])->assertRedirect(route('admin.backup.index'))->assertSessionHas('error');
    }

    public function test_single_table_csv_backup(): void
    {
        $res = $this->actingAs($this->admin())->post(route('admin.backup.download'), [
            'tables' => ['users'], 'format' => 'csv', 'csv_delimiter' => ';', 'csv_header' => '1', 'csv_bom' => '0',
        ]);

        $res->assertOk();
        $lines = array_values(array_filter(explode("\n", $res->streamedContent())));
        $this->assertStringStartsWith('id;', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString("O'Brien", $lines[1]);
    }

    public function test_multiple_tables_csv_backup_is_zip(): void
    {
        $res = $this->actingAs($this->admin())->post(route('admin.backup.download'), [
            'tables' => ['users', 'migrations'], 'format' => 'csv', 'csv_header' => '1',
        ]);

        $res->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($res->baseResponse->getFile()->getPathname()));
        $this->assertNotFalse($zip->locateName('users.csv'));
        $this->assertNotFalse($zip->locateName('migrations.csv'));
        $zip->close();
    }

    public function test_unknown_table_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('admin.backup.download'), [
            'tables' => ['users; DROP TABLE users'], 'format' => 'sql', 'sql_structure' => '1',
        ])->assertSessionHasErrors('tables.0');
    }

    public function test_download_is_logged_and_shown_in_history(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.backup.download'), [
            'tables' => ['users', 'migrations'], 'format' => 'csv', 'csv_header' => '1',
        ])->assertOk();

        $log = BackupLog::sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('csv', $log->format);
        $this->assertEqualsCanonicalizing(['users', 'migrations'], $log->tables);

        $this->actingAs($admin)->get(route('admin.backup.index'))
            ->assertOk()->assertSee('Riwayat unduhan terakhir')->assertSee("O&#039;Brien", false);
    }

    public function test_invalid_request_is_not_logged(): void
    {
        $this->actingAs($this->admin())->post(route('admin.backup.download'), [
            'tables' => ['users'], 'format' => 'sql', 'sql_structure' => '0', 'sql_data' => '0',
        ]);

        $this->assertSame(0, BackupLog::count());
    }

    public function test_sensitive_tables_are_marked_and_system_tables_skip_select_all(): void
    {
        $this->actingAs($this->admin())->get(route('admin.backup.index'))
            ->assertOk()
            ->assertSee('berisi hash password')
            ->assertSee('id="tbl-sessions" class="cb-table" data-skip="1"', false)
            ->assertDontSee('id="tbl-users" class="cb-table" data-skip="1"', false);
    }

    public function test_large_table_is_read_in_chunks_and_complete(): void
    {
        // Lebih dari satu potongan baca (1000 baris) dan lebih dari satu INSERT (200 baris).
        $rows = [];
        for ($i = 1; $i <= 2345; $i++) {
            $rows[] = ['subject_type' => 'level', 'subject_id' => $i, 'subject_label' => "baris-{$i}", 'action' => 'updated', 'created_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('master_data_logs')->insert($chunk);
        }

        $sql = $this->actingAs($this->admin())->post(route('admin.backup.download'), [
            'tables' => ['master_data_logs'], 'format' => 'sql', 'sql_structure' => '0', 'sql_data' => '1',
        ])->assertOk()->streamedContent();

        $this->assertSame(2345, substr_count($sql, "'baris-"));
        $this->assertStringContainsString("'baris-1'", $sql);
        $this->assertStringContainsString("'baris-2345'", $sql);

        $csv = $this->actingAs($this->admin())->post(route('admin.backup.download'), [
            'tables' => ['master_data_logs'], 'format' => 'csv', 'csv_header' => '1', 'csv_bom' => '0',
        ])->assertOk()->streamedContent();

        $this->assertSame(2345 + 1, count(array_filter(explode("\n", $csv))));
    }
}
