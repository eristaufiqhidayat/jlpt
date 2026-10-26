@extends('layouts.app')
@section('title', 'Backup Tabel')
@section('content')
    <div class="page-head"><h1>Backup Tabel</h1></div>

    <div class="alert warn">
        File backup berisi data pribadi peserta dan hash password. Simpan di tempat aman, jangan dibagikan,
        dan hapus salinan yang tidak diperlukan. Setiap unduhan dicatat.
    </div>

    <form method="POST" action="{{ route('admin.backup.download') }}" id="backup-form">
        @csrf

        <div class="card">
            <h2>Format</h2>
            <p class="muted">Database: <strong>{{ $database }}</strong>. Centang tabel yang ingin di-backup di bawah.</p>
            <div class="inline-form" style="align-items:flex-start;gap:32px">
                <div class="field">
                    <label><input type="radio" name="format" value="sql" @checked(old('format', 'sql') === 'sql')> SQL</label>
                    <label><input type="radio" name="format" value="csv" @checked(old('format') === 'csv')> CSV</label>
                </div>

                <div class="field" data-opt="sql">
                    <input type="hidden" name="sql_structure" value="0">
                    <label><input type="checkbox" name="sql_structure" value="1" @checked(old('sql_structure', '1') === '1')> Sertakan struktur (CREATE TABLE)</label>
                    <input type="hidden" name="sql_data" value="0">
                    <label><input type="checkbox" name="sql_data" value="1" @checked(old('sql_data', '1') === '1')> Sertakan data (INSERT)</label>
                    <input type="hidden" name="sql_drop" value="0">
                    <label><input type="checkbox" name="sql_drop" value="1" @checked(old('sql_drop', '1') === '1')> Tambahkan DROP TABLE IF EXISTS</label>
                </div>

                <div class="field" data-opt="csv">
                    <label for="csv_delimiter">Pemisah</label>
                    <select id="csv_delimiter" name="csv_delimiter">
                        <option value="," @selected(old('csv_delimiter') === ',')>Koma (,)</option>
                        <option value=";" @selected(old('csv_delimiter') === ';')>Titik koma (;)</option>
                        <option value="tab" @selected(old('csv_delimiter') === 'tab')>Tab</option>
                    </select>
                    <input type="hidden" name="csv_header" value="0">
                    <label><input type="checkbox" name="csv_header" value="1" @checked(old('csv_header', '1') === '1')> Baris header (nama kolom)</label>
                    <input type="hidden" name="csv_bom" value="0">
                    <label><input type="checkbox" name="csv_bom" value="1" @checked(old('csv_bom', '1') === '1')> UTF-8 BOM (agar rapi di Excel)</label>
                    <small class="muted">Lebih dari 1 tabel diunduh sebagai ZIP.</small>
                </div>
            </div>
        </div>

        <div class="card" style="padding:0">
            <div class="table-wrap"><table>
                <tr>
                    <th style="width:40px"><input type="checkbox" id="check-all" title="Pilih semua (kecuali tabel sistem bertanda merah)"></th>
                    <th>Nama tabel</th>
                    <th class="num">Ukuran</th>
                </tr>
                @forelse ($tables as $t)
                    <tr>
                        <td><input type="checkbox" name="tables[]" value="{{ $t['name'] }}" id="tbl-{{ $t['name'] }}" class="cb-table" @if ($t['skip']) data-skip="1" @endif @checked(in_array($t['name'], old('tables', []), true))></td>
                        <td>
                            <label for="tbl-{{ $t['name'] }}" style="margin:0;font-weight:normal">{{ $t['name'] }}</label>
                            @if ($t['note'])
                                <span @class(['badge', 'err' => $t['skip'], 'warn' => ! $t['skip']]) @if ($t['skip']) title="Tidak ikut pilih semua; centang manual bila perlu." @endif>⚠ {{ $t['note'] }}</span>
                            @endif
                        </td>
                        <td class="num">{{ $t['size'] !== null ? number_format($t['size'] / 1024, 1, ',', '.').' KB' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">Tidak ada tabel.</td></tr>
                @endforelse
            </table></div>
        </div>

        <div class="inline-form" style="margin-top:12px">
            <span class="muted"><span id="selected-count">0</span> tabel dipilih</span>
            <button class="btn" id="btn-backup" disabled>Download backup</button>
        </div>
    </form>

    @if ($logs->isNotEmpty())
        <div class="card" style="padding:0;margin-top:24px">
            <h2 style="padding:16px 16px 0">Riwayat unduhan terakhir</h2>
            <div class="table-wrap"><table>
                <tr><th>Waktu</th><th>Oleh</th><th>Format</th><th>Tabel</th><th>IP</th></tr>
                @foreach ($logs as $log)
                    <tr>
                        <td>{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $log->user?->name ?? '—' }}</td>
                        <td>{{ strtoupper($log->format) }}</td>
                        <td><small>{{ \Illuminate\Support\Str::limit(implode(', ', $log->tables ?? []), 120) }}</small> <span class="muted">({{ count($log->tables ?? []) }})</span></td>
                        <td><small class="muted">{{ $log->ip }}</small></td>
                    </tr>
                @endforeach
            </table></div>
        </div>
    @endif

    <script>
        (() => {
            const all = document.getElementById('check-all');
            const boxes = [...document.querySelectorAll('.cb-table')];
            // "Pilih semua" tidak mencakup tabel sistem (sesi, cache, antrean); tetap bisa dicentang manual.
            const normal = boxes.filter(b => !b.dataset.skip);
            const btn = document.getElementById('btn-backup');
            const count = document.getElementById('selected-count');

            const refresh = () => {
                const n = boxes.filter(b => b.checked).length;
                count.textContent = n;
                btn.disabled = n === 0;
                const all_normal = normal.length > 0 && normal.every(b => b.checked);
                all.checked = all_normal;
                all.indeterminate = n > 0 && !all_normal;
            };
            all.addEventListener('change', () => {
                normal.forEach(b => b.checked = all.checked);
                if (!all.checked) boxes.forEach(b => b.checked = false);
                refresh();
            });
            boxes.forEach(b => b.addEventListener('change', refresh));

            const toggle = () => {
                const f = document.querySelector('input[name=format]:checked').value;
                document.querySelectorAll('[data-opt]').forEach(el => el.style.display = el.dataset.opt === f ? '' : 'none');
            };
            document.querySelectorAll('input[name=format]').forEach(r => r.addEventListener('change', toggle));
            toggle(); refresh();
        })();
    </script>
@endsection
