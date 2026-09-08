<?php

namespace Tests\Unit;

use App\Http\Middleware\PermissionMiddleware;
use App\Models\System\Permission;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * RBAC permission foundation (Phase 3.5) — unit level.
 *
 * Validates the effective-permissions union (role permissions UNION user
 * additional permissions) and the PermissionMiddleware decision logic.
 *
 * Runs without a database: relations are hydrated in-memory via
 * setRelation() so loadMissing() does not query the DB. This avoids the
 * project's MySQL-only RBAC migrations, which cannot run on sqlite :memory:.
 */
class PermissionMiddlewareTest extends TestCase
{
    private function userWithEffective(
        array $rolePerms,
        array $userPerms,
        ?string $roleName = 'Guru',
    ): User {
        $role = new Role(['name' => $roleName]);
        $role->setRelation('permissions', collect(array_map(
            fn (string $name) => new Permission(['name' => $name]),
            $rolePerms,
        )));

        $user = new User([
            'name' => 'Guru',
            'email' => 'guru@school.test',
            'role_id' => 1,
        ]);
        $user->setRelation('role', $role);
        $user->setRelation('permissions', collect(array_map(
            fn (string $name) => new Permission(['name' => $name]),
            $userPerms,
        )));

        return $user;
    }

    private function runMiddleware(User $user, string ...$permissions)
    {
        $request = Request::create('/gate', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = (new PermissionMiddleware())->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            ...$permissions,
        );

        return $response;
    }

    public function test_effective_permissions_unions_role_and_user(): void
    {
        $user = $this->userWithEffective(
            ['academic-view'],
            ['manage-facilities'],
        );

        $effective = $user->effectivePermissions();

        $this->assertContains('academic-view', $effective);     // dari role
        $this->assertContains('manage-facilities', $effective); // additional
    }

    public function test_plain_guru_without_permission_is_forbidden(): void
    {
        // Guru biasa: role Guru dengan academic-view saja, tanpa manage-facilities.
        $user = $this->userWithEffective(['academic-view'], []);

        $response = $this->runMiddleware($user, 'manage-facilities');

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_guru_with_additional_permission_is_allowed(): void
    {
        $user = $this->userWithEffective(
            ['academic-view'],
            ['manage-facilities'],
        );

        $response = $this->runMiddleware($user, 'manage-facilities');

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_admin_role_bypasses_permission_check(): void
    {
        // Admin tidak perlu permission eksplisit (superuser).
        $user = $this->userWithEffective([], [], 'Admin');

        $response = $this->runMiddleware($user, 'manage-facilities');

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        $request = Request::create('/gate', 'GET');
        $request->setUserResolver(fn () => null);

        $response = (new PermissionMiddleware())->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'manage-facilities',
        );

        $this->assertEquals(403, $response->getStatusCode());
    }
}
