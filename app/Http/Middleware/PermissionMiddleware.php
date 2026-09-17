<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission-based authorization middleware (reusable server-side primitive).
 *
 * Allows the request if the authenticated user has at least one of the given
 * permissions. The permission is resolved as an EFFECTIVE permission:
 *
 *   role permissions (permission_role) UNION user additional permissions (permission_user)
 *
 * Admin & Administrator roles bypass the check (superusers) so gating a route
 * with this middleware never locks out the administrative roles.
 *
 * The `permission.strict` alias (StrictPermissionMiddleware) disables that
 * bypass so an individual route can be gated purely by a permission — used by
 * the Settings/Audit Logs routes, where Administrator must be denied for
 * lacking `manage-settings`/`view-audit-logs`.
 *
 * Usage (see bootstrap/app.php alias `permission`):
 *   ->middleware('permission:manage-facilities')
 *   ->middleware('permission:manage-facilities,view-reports')
 *   ->middleware('permission.strict:manage-settings')   // no superuser bypass
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
            ], 403);
        }

        if ($this->shouldBypass($user)) {
            return $next($request);
        }

        $required = array_map('strtolower', array_map('trim', $permissions));
        $effective = array_map('strtolower', $user->effectivePermissions());

        if (empty(array_intersect($required, $effective))) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Administrative roles bypass the permission check by default so the
     * reusable `permission:` middleware never locks them out.
     */
    protected function shouldBypass($user): bool
    {
        return in_array($user->role?->name, ['Admin', 'Administrator'], true);
    }
}
