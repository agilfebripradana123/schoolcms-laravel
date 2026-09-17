<?php

namespace App\Http\Middleware;

/**
 * Strict variant of PermissionMiddleware: NO superuser bypass.
 *
 * Used to gate routes that must be controlled purely by an explicit
 * permission, even for Admin/Administrator. Specifically the Settings and
 * Audit Logs routes, so that:
 *
 *   - Admin          → allowed (holds manage-settings / view-audit-logs)
 *   - Administrator  → denied  (deliberately not granted those permissions)
 *   - Guru/Siswa      → denied
 *
 * Registered under the `permission.strict` alias (see bootstrap/app.php).
 */
class StrictPermissionMiddleware extends PermissionMiddleware
{
    protected function shouldBypass($user): bool
    {
        return false;
    }
}
