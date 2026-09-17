<?php

namespace Tests\Unit;

use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\StrictPermissionMiddleware;
use App\Models\System\Permission;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PHASE RBAC SETTINGS (Stage B) — regression for Settings/Audit Logs gating.
 *
 * Settings and Audit Logs now use the `permission.strict` middleware
 * (StrictPermissionMiddleware) with `manage-settings` / `view-audit-logs`
 * instead of the role-based `role:Administrator,Super Admin`.
 *
 * "Strict" means no superuser bypass: Administrator is denied because it is
 * deliberately not granted those permissions, while Admin passes because it
 * holds them. The default `permission` middleware keeps its Admin/Administrator
 * bypass, so no other permission-gated module changes behavior.
 *
 * Runs without a database, exactly like PermissionMiddlewareTest: the RBAC
 * migrations are MySQL-only and cannot run on sqlite :memory:. Relations are
 * hydrated in-memory so loadMissing() does not hit the DB.
 */
class SettingsAuthorizationTest extends TestCase
{
    private function userWithEffective(
        array $rolePerms,
        array $userPerms,
        string $roleName,
    ): User {
        $role = new Role(['name' => $roleName]);
        $role->setRelation('permissions', collect(array_map(
            fn (string $name) => new Permission(['name' => $name]),
            $rolePerms,
        )));

        $user = new User([
            'name' => $roleName,
            'email' => strtolower($roleName) . '@school.test',
            'role_id' => 1,
        ]);
        $user->setRelation('role', $role);
        $user->setRelation('permissions', collect(array_map(
            fn (string $name) => new Permission(['name' => $name]),
            $userPerms,
        )));

        return $user;
    }

    private function runMiddleware(PermissionMiddleware $middleware, User $user, string ...$permissions)
    {
        $request = Request::create('/settings', 'GET');
        $request->setUserResolver(fn () => $user);

        return $middleware->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            ...$permissions,
        );
    }

    private function runStrict(User $user, string ...$permissions)
    {
        return $this->runMiddleware(new StrictPermissionMiddleware(), $user, ...$permissions);
    }

    // ── Settings: strict manage-settings ────────────────────────────

    public function test_admin_with_manage_settings_is_allowed(): void
    {
        $user = $this->userWithEffective(['manage-settings'], [], 'Admin');

        $this->assertEquals(200, $this->runStrict($user, 'manage-settings')->getStatusCode());
    }

    public function test_administrator_without_manage_settings_is_forbidden(): void
    {
        // Administrator is seeded WITHOUT manage-settings.
        $user = $this->userWithEffective([], [], 'Administrator');

        $this->assertEquals(403, $this->runStrict($user, 'manage-settings')->getStatusCode());
    }

    public function test_guru_without_manage_settings_is_forbidden(): void
    {
        $user = $this->userWithEffective(['view-classes'], [], 'Guru');

        $this->assertEquals(403, $this->runStrict($user, 'manage-settings')->getStatusCode());
    }

    public function test_siswa_without_manage_settings_is_forbidden(): void
    {
        $user = $this->userWithEffective(['view-grades'], [], 'Siswa');

        $this->assertEquals(403, $this->runStrict($user, 'manage-settings')->getStatusCode());
    }

    public function test_guru_with_direct_manage_settings_is_allowed(): void
    {
        // Direct permission_user grant must still win (role + direct union).
        $user = $this->userWithEffective(['view-classes'], ['manage-settings'], 'Guru');

        $this->assertEquals(200, $this->runStrict($user, 'manage-settings')->getStatusCode());
    }

    // ── Audit Logs: strict view-audit-logs ──────────────────────────

    public function test_admin_with_view_audit_logs_is_allowed(): void
    {
        $user = $this->userWithEffective(['view-audit-logs'], [], 'Admin');

        $this->assertEquals(200, $this->runStrict($user, 'view-audit-logs')->getStatusCode());
    }

    public function test_administrator_without_view_audit_logs_is_forbidden(): void
    {
        $user = $this->userWithEffective([], [], 'Administrator');

        $this->assertEquals(403, $this->runStrict($user, 'view-audit-logs')->getStatusCode());
    }

    // ── Regression: default middleware behavior is unchanged ────────

    public function test_default_permission_middleware_still_bypasses_administrator(): void
    {
        // Other permission-gated modules (e.g. Portal Guru routes) keep the
        // administrative bypass, so removing settings role-gating does not
        // change their access.
        $user = $this->userWithEffective([], [], 'Administrator');

        $this->assertEquals(
            200,
            $this->runMiddleware(new PermissionMiddleware(), $user, 'manage-facilities')->getStatusCode(),
        );
    }

    public function test_default_permission_middleware_still_bypasses_admin(): void
    {
        $user = $this->userWithEffective([], [], 'Admin');

        $this->assertEquals(
            200,
            $this->runMiddleware(new PermissionMiddleware(), $user, 'manage-facilities')->getStatusCode(),
        );
    }

    // ── /me effective permissions (Admin carries manage-settings) ───

    public function test_admin_effective_permissions_include_system_permissions(): void
    {
        $user = $this->userWithEffective(
            ['manage-settings', 'view-audit-logs'],
            [],
            'Admin',
        );

        $effective = $user->effectivePermissions();

        $this->assertContains('manage-settings', $effective);
        $this->assertContains('view-audit-logs', $effective);
    }
}
