<?php

namespace Tests\Feature\Communication;

use App\Models\Communication\UserNotification;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserNotificationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsGuru(): void
    {
        $guruRole = Role::where('name', 'Guru')->first();
        $user = User::where('role_id', $guruRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function createTestUser(string $prefix): User
    {
        $suffix = mt_rand(100000, 999999);

        return User::create([
            'role_id' => (int) Role::where('name', 'Guru')->value('id'),
            'username' => $prefix . $suffix,
            'name' => 'Notif Test ' . $suffix,
            'email' => $prefix . $suffix . '@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function createTestNotification(User $user): UserNotification
    {
        $suffix = mt_rand(100000, 999999);

        return UserNotification::create([
            'user_id' => $user->id,
            'title' => 'NOTIF-TEST-' . $suffix,
            'message' => 'Pesan notifikasi pengujian ' . $suffix,
            'type' => 'info',
            'is_read' => false,
        ]);
    }

    private function cleanupTestData(): void
    {
        DB::connection()->statement('DELETE FROM notifications WHERE title LIKE "NOTIF-TEST-%"');
        DB::connection()->statement('DELETE FROM users WHERE username LIKE "notiftest-%"');
    }

    // ─── Authorization ───────────────────────────────────────

    public function test_guest_list_returns_401(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_guest_cannot_create_notification(): void
    {
        $this->postJson('/api/notifications', [
            'title' => 'NOTIF-TEST-Guest',
            'message' => 'x',
        ])->assertStatus(401);
    }

    public function test_guru_cannot_create_notification(): void
    {
        $this->authenticateAsGuru();

        $this->postJson('/api/notifications', [
            'title' => 'NOTIF-TEST-Guru',
            'message' => 'x',
        ])->assertStatus(403);
    }

    // ─── List ────────────────────────────────────────────────

    public function test_admin_can_list_notifications(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/notifications?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
    }

    // ─── Show ────────────────────────────────────────────────

    public function test_admin_can_view_notification(): void
    {
        $this->authenticateAsAdmin();
        $user = $this->createTestUser('notiftest-view-');
        $notif = $this->createTestNotification($user);

        $response = $this->getJson('/api/notifications/' . $notif->id);
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $notif->id);
        $response->assertJsonPath('data.is_read', false);
    }

    public function test_show_nonexistent_notification_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->getJson('/api/notifications/99999999')->assertStatus(404);
    }

    // ─── Create ──────────────────────────────────────────────

    public function test_admin_can_create_notification(): void
    {
        $this->authenticateAsAdmin();
        $user = $this->createTestUser('notiftest-create-');
        $suffix = mt_rand(100000, 999999);

        $response = $this->postJson('/api/notifications', [
            'user_id' => $user->id,
            'title' => 'NOTIF-TEST-' . $suffix,
            'message' => 'Pesan baru ' . $suffix,
            'type' => 'exam',
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.type', 'exam');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'NOTIF-TEST-' . $suffix,
        ]);
    }

    public function test_create_requires_title_and_message(): void
    {
        $this->authenticateAsAdmin();

        $this->postJson('/api/notifications', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'message', 'user_id']);
    }

    // ─── Update ──────────────────────────────────────────────

    public function test_admin_can_mark_notification_as_read(): void
    {
        $this->authenticateAsAdmin();
        $user = $this->createTestUser('notiftest-update-');
        $notif = $this->createTestNotification($user);

        $response = $this->patchJson('/api/notifications/' . $notif->id, [
            'is_read' => true,
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $notif->id);
        $response->assertJsonPath('data.is_read', true);
        $this->assertNotNull($response->json('data.read_at'));

        $this->assertDatabaseHas('notifications', [
            'id' => $notif->id,
            'is_read' => 1,
        ]);
    }

    public function test_update_nonexistent_notification_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->putJson('/api/notifications/99999999', [
            'user_id' => 1,
            'title' => 'x',
            'message' => 'x',
        ])->assertStatus(404);
    }

    // ─── Delete ──────────────────────────────────────────────

    public function test_admin_can_delete_notification(): void
    {
        $this->authenticateAsAdmin();
        $user = $this->createTestUser('notiftest-delete-');
        $notif = $this->createTestNotification($user);

        $response = $this->deleteJson('/api/notifications/' . $notif->id);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseMissing('notifications', ['id' => $notif->id]);
    }

    public function test_delete_nonexistent_notification_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->deleteJson('/api/notifications/99999999')->assertStatus(404);
    }

    // ─── My Notifications ────────────────────────────────────

    public function test_user_can_view_own_notifications(): void
    {
        $user = $this->createTestUser('notiftest-my-');
        Sanctum::actingAs($user);
        $this->createTestNotification($user);

        $response = $this->getJson('/api/notifications/my');
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'My notifications retrieved successfully');

        $userId = $user->id;
        $matches = collect($response->json('data'))
            ->filter(fn ($n) => $n['title'] === 'NOTIF-TEST-*' || str_contains($n['title'], 'NOTIF-TEST-'))
            ->filter(fn ($n) => $n['user_id'] === $userId);
        $this->assertGreaterThanOrEqual(1, $matches->count());
    }
}