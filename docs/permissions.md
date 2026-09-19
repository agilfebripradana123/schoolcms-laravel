# Permission Catalog

Permission adalah capability resmi sistem yang ditentukan oleh developer. Permission bukan master data yang dapat CRUD bebas oleh Admin/Operator.

Admin hanya mengatur: **Role → Permission assignment**.

---

## System

| Permission | Description |
|------------|-------------|
| manage-users | Mengelola pengguna sistem. |
| manage-roles | Mengelola peran dan assignment hak akses. |
| manage-settings | Mengelola pengaturan sistem. |
| view-audit-logs | Melihat log aktivitas sistem. |

## Academic

| Permission | Description |
|------------|-------------|
| manage-academic-years | Mengelola tahun ajaran, semester, dan kurikulum. |
| manage-subjects | Mengelola mata pelajaran. |
| manage-classes | Mengelola kelas, penugasan siswa, dan penugasan mata pelajaran ke kelas. |
| manage-schedules | Mengelola jadwal pelajaran. |
| manage-grades | Mengelola data penilaian, tugas, periode, dan rapor. |

## Staff

| Permission | Description |
|------------|-------------|
| manage-teachers | Mengelola data guru, penugasan, kehadiran, cuti, dan dokumen. |
| manage-staff | Mengelola tenaga kependidikan. |

## Students

| Permission | Description |
|------------|-------------|
| manage-students | Mengelola data siswa, kehadiran, orang tua, wali, riwayat, mutasi, alumni, dan kartu pelajar. |

## Finance

| Permission | Description |
|------------|-------------|
| manage-finance | Mengelola jenis biaya, tagihan, pembayaran, transaksi, dan laporan keuangan. |
| manage-scholarships | Mengelola beasiswa. |

## Examination

| Permission | Description |
|------------|-------------|
| manage-exams | Mengelola ujian, bank soal, sesi, jadwal, instruksi, peserta, dan hasil. |
| view-exam-results | Akses baca hasil ujian pada mata pelajaran scope mengajar guru (Portal Guru). |
| manage-exam-results | Akses mutasi hasil ujian (pengisian nilai / grade-sync) guru pada scope mengajar. Catatan: jalur grading/sync guru saat ini menerima `view-exam-results,manage-exam-results` (OR) agar kompatibel ke belakang. |

## Facilities

| Permission | Description |
|------------|-------------|
| manage-facilities | Mengelola ruangan, aset, pemeliharaan, dan inventaris. |

## Communication

| Permission | Description |
|------------|-------------|
| manage-announcements | Mengelola pengumuman. |
| manage-notifications | Mengelola notifikasi. |
| manage-calendars | Mengelola kalender akademik. |

## Development

| Permission | Description |
|------------|-------------|
| manage-development | Mengelola prestasi, pelanggaran, bimbingan konseling, dan ekstrakurikuler. |

## Administration

| Permission | Description |
|------------|-------------|
| manage-letters | Mengelola surat masuk, surat keluar, dan disposisi. |
| manage-documents | Mengelola dokumen administrasi. |

## PPDB

| Permission | Description |
|------------|-------------|
| manage-ppdb | Mengelola pendaftaran dan daftar ulang siswa baru. |

## Reports

| Permission | Description |
|------------|-------------|
| view-reports | Melihat laporan akademik, siswa, guru, keuangan, kehadiran, dan inventaris. |
