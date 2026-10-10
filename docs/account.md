# Akun & Autentikasi

Kredensial hasil seeder dan alur login API. Sumber: `DatabaseSeeder.php`, `RoleSeeder.php`, `routes/api.php`.

## Akun Seeder

Dibuat oleh `DatabaseSeeder` (bukan factory, idempotent via `firstOrCreate`):

| Field | Nilai | Sumber |
|---|---|---|
| email | `test@example.com` | `DatabaseSeeder.php:27` |
| username | `testuser` | `DatabaseSeeder.php:29` |
| password | `password` | `DatabaseSeeder.php:32` (`bcrypt('password')`) |
| role_id | `1` | `DatabaseSeeder.php:28` |
| is_active | `true` | `DatabaseSeeder.php:34` |

Jalankan: `php artisan db:seed` (atau `php artisan migrate --seed`). Seeder akun ini selalu dijalankan di akhir `DatabaseSeeder::run()`.

> Catatan: `RoleSeeder` membuat role **Administrator** (semua permission kecuali `view-audit-logs` & `manage-settings`), **Guru** (`User::GURU_DEFAULT_PERMISSIONS`), **Siswa** (`User::SISWA_DEFAULT_PERMISSIONS`). Akun seeder menunjuk `role_id = 1` secara literal.

## Login

```
POST /api/login
Content-Type: application/json

{ "login": "test@example.com", "password": "password" }
```

Field `login` menerima **email atau username**. Sukses (200):

```json
{
  "message": "Login berhasil.",
  "token": "1|abc123...",
  "user": { "id": 1, "name": "Test User", "role": "Admin", "is_active": true }
}
```

Error: `401 Username atau Email tidak ditemukan.` / `401 Password salah.` / `403 Akun Anda tidak aktif.`

## Sesi Token (Sanctum)

| Endpoint | Fungsi |
|---|---|
| `POST /api/login` | Dapat token |
| `GET /api/me` | Info user terautentikasi |
| `POST /api/logout` | Hapus token aktif |

Semua request terautentikasi pakai header `Authorization: Bearer {token}`. Tanpa token → `401 Unauthenticated.`

## Otorisasi

Role-based lewat `RoleMiddleware` + permission (lihat `permissions.md`). Write operation (`POST/PUT/PATCH/DELETE/import`) butuh role `Admin`/`Administrator`. Role tidak diizinkan → `403 Unauthorized` (bukan `Forbidden`).
