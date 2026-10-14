# JLPT CBT — versi Laravel

Pengganti aplikasi tes bahasa Jepang lama (PHP native, folder `rpl/laravel/public`).
Berisi struktur database, logika ujian, migrasi data lama, dan halaman untuk admin, sensei, dan student.
Tampilan memakai Blade + CSS biasa (`public/css/app.css`) mengikuti mockup JGG (sidebar navy, aksen sakura,
font Zen Kaku Gothic New), jadi server **tidak perlu npm / build**. Nama lembaga diatur lewat `APP_BRAND` di `.env`,
logo di `public/images/logo.png`.

- Laravel 13, PHP ≥ 8.3 (server sekarang PHP 8.3.28 dan MySQL 8.4, sudah cukup)
- Database baru: `utf8mb4` + InnoDB, jadi huruf Jepang bisa disimpan langsung di database

## Instalasi

```bash
composer install
cp .env.example .env
php artisan key:generate
# isi DB_* (database baru, mis. jlpt_cbt) dan LEGACY_DB_* (database lama jlpt_jgg) di .env
# opsional: ADMIN_EMAIL=... dan ADMIN_PASSWORD=... untuk akun admin pertama
php artisan migrate --seed
```

Kalau `ADMIN_PASSWORD` kosong, password admin dibuat acak dan ditampilkan sekali di terminal.

**Demo masuk cepat** (untuk situs uji coba): isi `DEMO_LOGIN=true` di `.env`, lalu `php artisan config:clear`. Halaman login
akan menampilkan tombol masuk tanpa password untuk Peserta, Sensei, Admin Pendidikan, Admin, dan Direktur (akun demo
dibuat otomatis bila belum ada; emailnya di `config/app.php` → `demo_accounts`). Siapa pun yang membuka halaman login
bisa masuk, termasuk sebagai Admin, jadi kembalikan ke `DEMO_LOGIN=false` sebelum situs dipakai peserta sungguhan.
Lupa password? `php artisan user:password admin@example.com` (tambahkan `--admin` untuk membuat akun admin baru).
Di `APP_ENV=local`, seeder juga membuat data demo: `sensei@example.com`, `student@example.com`, dan
`direktur@example.com`, `pendidikan@example.com` (Admin Pendidikan) (password `password`), 8 soal N4, ujian gratis berkode `DEMON4`, kelas N4-A dengan jadwal,
3 materi, tahapan Program Jepang, dan satu pesan.

Scheduler wajib aktif di server supaya bagian ujian yang waktunya habis dikumpulkan otomatis
(misalnya student menutup browser):

```
* * * * * cd /path/ke/project && php artisan schedule:run >> /dev/null 2>&1
```

## Struktur database

| Kelompok | Tabel | Keterangan |
|---|---|---|
| User | `users`, `student_profiles` | kolom `role`: admin, sensei, student, director; profil peserta (NIS, level, program, tanggal masuk) |
| Kelas | `classrooms`, `classroom_student`, `class_sessions`, `attendances` | kelas, peserta, jadwal, absensi (hadir/izin/sakit/alpa) |
| Materi | `materials`, `material_progress` | video (YouTube/upload), PDF, PPT, latihan soal (tautan ke ujian); tanda selesai per peserta |
| Program Jepang | `program_stages`, `program_documents` | 7 tahap (Pendaftaran → Keberangkatan) dan dokumen (paspor, sertifikat JLPT, interview, MCU, visa, tiket) |
| Sertifikat | `certificates` | terbit otomatis saat lulus (nilai ≥ nilai minimal ujian), nomor `JGG-JLPT-2026-0001`, token verifikasi |
| Pesan | `messages` | pesan admin/sensei ke peserta atau satu kelas; peserta bisa membalas |
| Master | `test_types` → `levels` → `sections` | JLPT → N4 → Moji Goi / Bunpou Dokkai / Choukai, dengan durasi standar; skor maks & batas lulus per jenis tes; level/bagian bisa dinonaktifkan |
| Master akademik | `curricula`, `violation_categories`, `master_data_logs` | klasifikasi kelas (Minna I/II, JFT, N3, SSW per bidang; urutan = dasar lock), kategori batsu, log setiap perubahan data master |
| Bank soal | `passages`, `questions`, `question_options` | bacaan/audio bersama, soal (isian, ★, bacaan, choukai), pilihan 1–4 |
| Ujian | `exams`, `exam_sections` | kode akses, gratis/berbayar, durasi dan jumlah soal per bagian (bisa diubah sensei) |
| Pengerjaan | `exam_attempts`, `attempt_sections`, `attempt_questions` | soal hasil acak + snapshot kunci, waktu per bagian |
| Pembayaran | `payments` | per ujian; status `paid` membuka akses |
| Arsip lama | `legacy_exams`, `legacy_exam_files`, `legacy_answer_keys` | salinan m_exam, detail_soal, jwb_soal |

## Alur ujian (`app/Services/ExamAttemptService.php`)

1. Student memasukkan kode ujian. Ujian berbayar hanya bisa dimulai jika sudah ada pembayaran `paid`.
2. Saat mulai, soal tiap bagian **diacak dari bank soal** (`QuestionPicker`). Soal yang berbagi satu
   bacaan/audio selalu diambil bersama. Kunci jawaban saat itu disalin ke `attempt_questions`.
3. Bagian dikerjakan berurutan. Setiap bagian punya `deadline_at` yang dihitung di server; jawaban yang
   masuk setelah waktu habis ditolak.
4. Saat bagian dikumpulkan (oleh student atau otomatis karena waktu habis), bagian itu langsung dinilai
   dan bagian berikutnya dimulai. Setelah bagian terakhir, **nilai total langsung keluar**.
5. `php artisan exams:expire-overdue` (dijalankan scheduler tiap menit) menyelesaikan ujian student yang
   menutup browser.

Nilai saat ini = persentase jawaban benar (per bagian dan total). Skala nilai resmi JLPT belum diterapkan.

**Soal esai** (tipe `essay`, mis. tes Kanji): student menulis jawaban, sensei menilai benar/salah lewat
`ExamAttemptService::gradeEssay()`. Esai yang tidak dijawab otomatis salah. Selama masih ada esai yang belum
dinilai, status ujian `pending_review`; setelah semua dinilai, status menjadi `finished` dan nilai final keluar.

## Migrasi data lama

```bash
php artisan legacy:import --dry-run             # cek dulu, tidak ada data yang disimpan
php artisan legacy:import                       # impor semua jenis tes
php artisan legacy:import --only=JLPT           # hanya JLPT
php artisan legacy:import --files=/path/ke/pdf  # sekaligus salin file soal (default: LEGACY_PDF_PATH)
php artisan legacy:import --audio=/path/audio   # salin audio choukai (default: LEGACY_AUDIO_PATH, subfolder ikut dicari)
```

Aturan yang dipakai (sesuai diskusi):

- Kunci (`jwb_soal`) hanya dipakai bila `id_soal` **dan** `kode_exam`-nya cocok dengan satu baris `detail_soal`;
  kunci tanpa pasangan (file sudah dihapus/diupload ulang/beda paket) dibuang.
- Audio choukai per nomor (`jwb_soal.flag1`) dan pembahasan (`jwb_soal.textbahasan`) ikut diarsipkan. Saat import
  soal dari Excel dengan `arsip_id` + `no_arsip`, audio & pembahasan otomatis terpasang ke soal baru.
- Paket yang berbagi `kode_exam` memakai file soal yang sama, jadi file diimpor sekali dan terhubung ke
  semua paket itu.
- Kunci `A–D` diubah menjadi `1–4`. Kunci `Z`, `E`, `F`, kosong, nomor dobel, atau nomor di luar jumlah
  soal dianggap bug input aplikasi lama: disimpan apa adanya, ditandai tidak valid, dan file-nya berstatus
  `invalid_keys` supaya diperiksa sensei.
- File Kanji-Furigana / Furigana-Kanji tanpa kunci dianggap esai (dinilai manual), seperti Interview.
- File uji coba (1 soal, tanpa kunci, bukan Interview) dilewati, misalnya `LABELLOGO.png`.
- File `.html` di folder `pdf` lama disalin ke `storage/app/private/legacy/{kode_exam}/{id_soal}.pdf`
  (di luar folder publik). Isi file dicek; yang bukan PDF dicatat.
- File yang paketnya sudah terhapus dari `m_exam` tetapi `kode_exam`-nya berlevel N1–N5 dengan bagian
  Moji Goi / Bunpou Dokkai / Choukai diasumsikan JLPT, dan tetap ditandai di laporan untuk dicek.
- Level dan bagian untuk jenis tes selain JLPT (JTEST, NATTEST, dll.) dibuat otomatis dari data lama.
- Perintah aman dijalankan berulang; data dicocokkan lewat ID lama.

Di akhir, daftar file yang perlu ditinjau disimpan di `storage/app/private/legacy-import-report-*.csv`.

Soal di dalam PDF tidak dipecah otomatis ke bank soal. Sensei menginputnya ulang dari arsip, dengan kunci
yang sudah tersedia di `legacy_answer_keys`; kolom `questions.legacy_file_id` mencatat asal soalnya.

## Halaman

| Role | Menu | Isi |
|---|---|---|
| Semua | `/login` | Login dengan email + password (maks. 5 percobaan/menit) |
| Admin | User | Tambah/edit admin, Admin Pendidikan, sensei, student; aktif/nonaktifkan; ganti password |
| Admin Pendidikan | Data Master | Klasifikasi kelas, klasifikasi ujian (jenis tes → level → bagian), skor & batas lulus, kategori batsu. Hanya peran ini yang bisa menambah/mengubah/menonaktifkan (tidak ada hapus); staf lain hanya melihat. Setiap perubahan tercatat |
| Admin | Pembayaran | Lihat semua transaksi (Midtrans & manual), catat pembayaran manual, ubah status |
| Sensei & admin | Bank Soal | Soal isian, ★, bacaan, choukai, esai; furigana `{漢字|かんじ}`, garis bawah `__kata__`; gambar & audio |
| Sensei & admin | Bacaan & audio | Bacaan/audio yang dipakai bersama beberapa soal |
| Sensei & admin | Bank Soal → Import Excel | Template .xlsx, pratinjau + cek per baris, hanya baris valid yang disimpan; kunci bisa diambil otomatis dari arsip lama (`arsip_id` + `no_arsip`) |
| Sensei & admin | Ujian | Buat ujian per level: durasi & jumlah soal per bagian, gratis/berbayar, batas putar audio, buka/tutup; lihat peserta & nilai; export nilai ke .xlsx |
| Sensei & admin | Penilaian Esai | Nilai jawaban esai benar/salah + catatan |
| Sensei & admin | Arsip Lama | Baca PDF lama, perbaiki kunci (E/F/Z/kosong); perbaikan tidak tertimpa impor ulang |
| Student | Beranda | Masukkan kode ujian, lanjutkan ujian/pembayaran, riwayat nilai |
| Student | Pembayaran | Kode ujian berbayar → halaman bayar (Midtrans Snap: QRIS, VA, e-wallet) → langsung bisa mulai |
| Student | Ujian | Satu soal per layar + navigasi nomor, timer per bagian, simpan otomatis, tanda ragu-ragu, audio dengan batas putar |
| Student | Nilai & Riwayat | Semua ujian; halaman hasil dengan status Lulus/Belum lulus (bila ujian punya nilai minimal) dan rekap per bagian |
| Student | Dashboard | Level, kehadiran, materi selesai, sertifikat, tahapan Program Jepang, materi terbaru, jadwal hari ini |
| Student | Hasil ujian | Tombol Lihat Pembahasan (kunci + pembahasan), Download Sertifikat (bila lulus), Ulangi Ujian (bila ujian masih dibuka) |
| Student | Materi / Kelas Saya / Program Jepang | Belajar per bab + tandai selesai; jadwal & riwayat kehadiran; status tahapan & dokumen (hanya lihat) |
| Student | Sertifikat | Sertifikat digital: cetak/simpan PDF (A4 landscape), tautan verifikasi publik `/verifikasi/{token}`, bagikan |
| Sensei & admin | Materi | Tambah/edit materi per level & bab: YouTube/upload video, PDF, PPT, latihan soal, draft/terbit |
| Sensei & admin | Kelas & Jadwal | Kelas, peserta, jadwal (bisa berulang mingguan), absensi per sesi |
| Sensei, admin, direktur | Program Jepang | Monitoring semua peserta; sensei/admin mengubah status tahap & mengunggah dokumen, direktur hanya lihat |
| Direktur & admin | Laporan | Peserta aktif, kelas aktif, lulus per tahun, siap berangkat; grafik peserta per level, tren kelulusan 3 tahun, rekap tahapan; filter tahun/level/kelas; cetak |
| Semua | Pesan / Profil | Kotak masuk & balas; ubah nama, no. HP, password (wajib password lama) |

Data peserta (NIS, level, program, tanggal masuk, kelas) diisi admin di menu **User** saat role = Peserta.
Sertifikat hanya terbit untuk ujian yang punya **nilai minimal lulus**.

Gambar, audio, dan PDF disimpan di `storage/app/private` dan hanya bisa dibuka lewat aplikasi setelah login.
Student tidak bisa mengunduh audio choukai langsung; setiap putar dicatat dan dibatasi sesuai pengaturan ujian.

## Pembayaran Midtrans

1. Isi `.env` dengan key dari dashboard Midtrans (**Settings → Access Keys**). Mulai dari sandbox:
   ```
   MIDTRANS_SERVER_KEY=SB-Mid-server-xxxx
   MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxx
   MIDTRANS_IS_PRODUCTION=false
   MIDTRANS_EXPIRY_MINUTES=60
   ```
2. Di dashboard Midtrans, **Settings → Payment → Notification URL**, isi:
   `https://DOMAIN-ANDA/payments/midtrans/notification`
3. `php artisan config:clear`

Alur: student memasukkan kode ujian berbayar → halaman bayar → popup Snap → setelah lunas, tombol "Mulai ujian".
Status diperbarui dari dua jalur: notifikasi Midtrans (diverifikasi dengan `signature_key` dan nominal) dan
cek status langsung saat student kembali dari popup. Transaksi yang masih berlaku dipakai ulang (tidak dobel).
Pembayaran yang sudah lunas tidak bisa turun statusnya kecuali refund. Tanpa key Midtrans, halaman bayar
menampilkan instruksi pembayaran manual lewat admin.

Untuk production: ganti ke key production dan `MIDTRANS_IS_PRODUCTION=true`.

## Update dari versi sebelumnya

```bash
php artisan migrate        # tabel kelas, materi, Program Jepang, sertifikat, pesan, data master akademik
php artisan db:seed --class=MasterDataSeeder   # klasifikasi kelas & ujian sesuai notulen; aman diulang, tidak menimpa perubahan
php artisan optimize:clear
```

Peran **Admin Pendidikan** (`education_admin`) dibuat admin lewat menu User. Peran ini mengelola Data Master dan
punya akses yang sama dengan sensei/admin untuk materi, kelas, bank soal, ujian (semua ujian), penilaian esai, dan laporan.

## Tahap berikutnya

1. Skala nilai JLPT (nilai per bagian 0–60 / total 0–180) bila dibutuhkan.
