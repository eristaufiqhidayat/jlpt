# Mockup JGG Learning System

Mockup interaktif aplikasi `jlpt` setelah perubahan dari notulen rapat **JGG Learning System**
(PT. Jakaruta Global Group). Ini hanya rancangan tampilan dengan data contoh; kode aplikasi
belum diubah.

Buka `index.html` langsung di browser (tidak perlu server). Logo sudah tertanam di dalam file;
`logo-jakaruta-global-group.png` adalah logo asli untuk dipakai nanti di aplikasi.

## Cara mencoba

- Halaman awal adalah login aplikasi belajar (LMS). Pakai tombol **Demo masuk cepat**:
  Peserta, Sensei, Admin Pendidikan, Admin, Superadmin, Direktur.
- Toolbar atas: pindah antara **Aplikasi LMS** dan **Portal Ujian** (login terpisah),
  **Kembali ke login**, dan **Tandai perubahan** untuk menampilkan/menyembunyikan label
  nomor butir notulen (mis. `3.c`).
- Dari halaman login juga ada **Daftar** (pendaftaran calon peserta, 12 berkas) dan
  **Portal Ujian**.

| Akun demo | Peran |
| --- | --- |
| `rizky@jgg.id` / `JGG-2026-014` | Peserta (juga untuk Portal Ujian) |
| `yamada@jgg.id` | Sensei |
| `sari@jgg.id` | Admin Pendidikan |
| `dewi@jgg.id` | Admin (operasional) |
| `it@jgg.id` | Superadmin |
| `direktur@jgg.id` | Direktur (hanya lihat) |

Password apa saja (tidak diperiksa di mockup).

## Isi per peran

| Peran | Menu |
| --- | --- |
| Peserta | Dashboard, Materi (tab per kurikulum + lock), Soal Latihan, Kelas Saya (jadwal per minggu, catatan sensei), Sertifikat (unggah sertifikat bahasa), Progress to Japan (8 tahap), Pembayaran, Oshirase |
| Portal Ujian | Pilih jenis ujian (JLPT 180, J-Test 350, NAT-Test 180, JFT-Basic 250, SSW), masukkan kode, Nilai Saya (benar/salah disembunyikan) |
| Sensei | Catatan Mingguan, Materi Tambahan per kelas, Catatan Batsu, Materi Pakem (lihat + usulkan) |
| Admin Pendidikan | Data Master (klasifikasi kelas, klasifikasi ujian, skor & batas lulus, kategori batsu), Materi Pakem, Bank Soal, Ujian & Koreksi, Usulan Sensei, Laporan |
| Admin (operasional) | Pendaftaran, Kelas & Jadwal, Oshirase, Monitoring Portal, Perusahaan & Job Order (+ Cadangan), Laporan |
| Superadmin | Pengguna, Hak Akses per peran, Log Perubahan |
| Direktur | Laporan, Monitoring Portal, Log Perubahan (hanya lihat) |

## Catatan

- Beberapa keputusan masih terbuka dan di mockup hanya ditebak, antara lain: apakah portal
  ujian memakai akun yang sama dengan LMS, cara koreksi nilai akhir, skor maksimal SSW,
  dan isi tabel Hak Akses.
- Istilah: **materi pakem** = kurikulum inti (Minna I/II, N3, JFT, SSW) yang hanya boleh
  diubah Admin Pendidikan; **materi tambahan** = bahan dari sensei untuk kelasnya sendiri.
