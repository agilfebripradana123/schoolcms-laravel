# 01 — Plan: SchoolCMS Laravel

## Tujuan

Sistem Informasi Manajemen Sekolah (SIMS) berbasis REST API. Backend untuk akademik, kesiswaan, kepegawaian, keuangan, ujian online (CBT), PPDB, fasilitas, administrasi. Migrasi dari CodeIgniter 4 ke Laravel 12. Frontend SPA terpisah: `schoolcms-react`.

## Stack

| Lapisan | Teknologi |
|---|---|
| Framework | Laravel 12, PHP ≥8.2 |
| Auth | Laravel Sanctum (Bearer token) |
| Database | MySQL 8.4 (legacy/production), SQLite (dev & test) |
| Excel | maatwebsite/excel ^3.1 |
| Testing | PHPUnit 11.5 |
| Frontend asset | Vite 7, Tailwind CSS v4 |

## Struktur

99 controller di `app/Http/Controllers/Api/`, 15 domain module:

```
app/Http/Controllers/Api/
├── Academic/        # tahun ajaran, kelas, kurikulum, jadwal, rapor
├── Administration/  # surat masuk/keluar, disposisi
├── Communication/   # pengumuman, notifikasi, kalender
├── Development/     # prestasi, pelanggaran, BK, ekskul
├── Examination/     # CBT: bank soal, sesi, jadwal, monitoring
├── Facilities/      # ruangan, aset, inventaris
├── Finance/         # biaya, tagihan, pembayaran, beasiswa
├── PPDB/            # pendaftaran, verifikasi, seleksi
├── Reports/         # laporan agregasi
├── Staff/           # guru, staff, kehadiran, cuti
├── Students/        # siswa, portal self-service
├── System/          # auth, roles, users, settings, audit
└── Teachers/        # portal guru
```

Pola per domain: Controller + Request (validasi) + Resource (response). `database/migrations/` 41 file. `routes/api.php` 60+ endpoint.

## Konvensi API

- Envelope: `{ success, message, data, meta }`
- Auth: Sanctum Bearer token
- Rate limiting di endpoint PPDB
- Endpoint publik: `POST /api/ppdb/register` (di luar `auth:sanctum`)

## Status Testing

| Suite | Status | Catatan |
|---|---|---|
| PHPUnit Unit | 39/39 hijau | SQLite in-memory (`phpunit.xml`) |
| PHPUnit Feature | 2121 masalah | 2114 error `no such table` + 7 failure |
| Playwright FE | 5/5 | di repo react |

### Follow-up

1. **Migrasi SQLite** — blocker utama. Jalankan `php artisan migrate:fresh --env=testing` untuk lihat error skema asli; penyebab 2114 `no such table`.
2. **`maatwebsite/excel`** — pastikan terpasang, fixes `QuestionImportTest` (`Class "...Excel" not found`).
3. **403 di test grade/exam** — cek permission/role setup, kemungkinan regression policy.
4. **E2E authenticated** — butuh API hidup + seed user per role.

## Aturan Database (Legacy)

`schoolcms_db` = database CodeIgniter 4, production. **Jangan** jalankan `php artisan migrate` di production. Schema change via SQL langsung + approval DBA. Detail: `teacher-api.md` §18.
