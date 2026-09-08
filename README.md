# SchoolCMS Laravel

Sistem Informasi Manajemen Sekolah (SIMS) berbasis REST API. Backend untuk manajemen akademik, kesiswaan, kepegawaian, keuangan, ujian online (CBT), PPDB, fasilitas, dan administrasi surat-menyurat. Migrasi dari CodeIgniter 4 ke Laravel 12. Frontend SPA terpisah: **[schoolcms-react](https://github.com/agilfebripradana123/schoolcms-react)** (React + TS + Vite + Tailwind).

## Tech Stack

- **Backend:** Laravel 12, PHP ≥8.2 (repo ini)
- **Frontend:** [schoolcms-react](https://github.com/agilfebripradana123/schoolcms-react) — React + TypeScript + Vite + Tailwind CSS (repo terpisah)
- **Auth:** Laravel Sanctum (Bearer token)
- **Database:** MySQL 8.4 (production/legacy), SQLite (dev)
- **Export/Import:** maatwebsite/excel 4.0
- **Frontend Assets:** Vite 7, Tailwind CSS v4
- **Testing:** PHPUnit 11.5

## Fitur

### Akademik
- Tahun ajaran, semester, kurikulum
- Kelas, mata pelajaran, jadwal, periode
- Penugasan guru, rapor

### Kesiswaan
- CRUD siswa, kehadiran, mutasi, alumni
- Orang tua/wali, riwayat, kartu pelajar
- Prestasi, pelanggaran, bimbingan konseling, ekstrakurikuler

### Kepegawaian
- Data guru & staff, penugasan
- Kehadiran, cuti, dokumen

### Ujian Online (CBT)
- Bank soal, sesi ujian, jadwal, instruksi
- Peserta, jawaban, hasil, monitoring
- Exam attempts (secure web exam)

### Keuangan
- Jenis biaya, tagihan, pembayaran, transaksi
- Beasiswa, laporan keuangan

### PPDB (Penerimaan Peserta Didik Baru)
- Pendaftaran publik (tanpa auth)
- Verifikasi, seleksi, daftar ulang
- Export Dapodik

### Fasilitas & Inventaris
- Ruangan, aset, pemeliharaan
- Inventaris, stock movement (masuk/keluar/adjustment)

### Komunikasi
- Pengumuman, notifikasi, kalender akademik

### Administrasi
- Surat masuk/keluar, disposisi, dokumen administrasi

### Laporan
- Akademik, siswa, guru, keuangan, kehadiran, inventaris

### Portal Self-Service
- **Siswa:** nilai, jadwal, kehadiran, tugas, keuangan, ujian online
- **Guru:** kelas sendiri, absensi siswa, input nilai, tugas, monitoring ujian

### Sistem
- RBAC (roles/permissions), user management
- Audit logs, settings, profile

## Cara Menjalankan

### Prasyarat
- PHP ≥8.2 + Composer
- Node.js + npm
- MySQL 8.4 atau SQLite

### Setup Otomatis
```bash
composer setup
```
Menjalankan: `composer install` → copy `.env` → `key:generate` → `migrate` → `npm install` → `npm run build`.

### Development
```bash
composer dev
```
Menjalankan concurrently: server, queue worker, pail (log tailing), Vite dev server.

### Testing
```bash
composer test
```

### Setup Manual
```bash
cp .env.example .env
# Edit DB credentials di .env
php artisan key:generate
php artisan migrate
npm install && npm run build
php artisan serve
```

## Struktur Project

```
app/
├── Exports/              # Excel exports (Teachers, Dapodik)
├── Http/
│   ├── Controllers/Api/  # 99 controllers, 15 domain modules
│   │   ├── Academic/     # Tahun ajaran, kelas, kurikulum, jadwal, rapor
│   │   ├── Administration/ # Surat masuk/keluar, disposisi
│   │   ├── Communication/  # Pengumuman, notifikasi, kalender
│   │   ├── Development/    # Prestasi, pelanggaran, BK, ekskul
│   │   ├── Examination/    # CBT: bank soal, sesi, jadwal, monitoring
│   │   ├── Facilities/     # Ruangan, aset, inventaris
│   │   ├── Finance/        # Biaya, tagihan, pembayaran, beasiswa
│   │   ├── PPDB/           # Pendaftaran, verifikasi, seleksi
│   │   ├── Reports/        # Laporan agregasi
│   │   ├── Staff/          # Guru, staff, kehadiran, cuti
│   │   ├── Students/       # Siswa, portal self-service
│   │   ├── System/         # Auth, roles, users, settings, audit
│   │   └── Teachers/       # Portal guru
│   ├── Middleware/        # RoleMiddleware, PermissionMiddleware
│   └── Resources/         # API resources per domain
├── Models/                # Eloquent models (Facilities, Staff, Students)
database/migrations/       # 41 migration files
docs/                      # teacher-api.md, permissions.md
routes/api.php             # 60+ API endpoints, Sanctum auth
```

## Kontribusi

### Legacy Database
- **Jangan jalankan `php artisan migrate` di production.** Schema changes via direct SQL + DBA approval.
- Database legacy dari CodeIgniter 4 (`schoolcms_db`).

### Roles & Permissions
- **Roles:** Admin, Administrator (write), Guru, Siswa (read-only)
- **Permissions:** System-defined catalog, bukan CRUD dinamis. Lihat `docs/permissions.md`.

### API Convention
- Semua endpoint JSON: `{ success, message, data, meta }`
- Auth: Sanctum Bearer token
- Rate limiting aktif di PPDB endpoints

### Development Workflow
1. Branch dari `main`
2. Follow existing controller/resource pattern per domain
3. Tambah migration hanya untuk dev/staging
4. Test: `composer test`
5. PR dengan deskripsi perubahan + screenshot jika UI-related

</content>