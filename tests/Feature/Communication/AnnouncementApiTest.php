<?php

namespace Tests\Feature\Communication;

use App\Models\Communication\Announcement;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementApiTest extends TestCase
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

    private function createTestAnnouncement(): Announcement
    {
        $suffix = mt_rand(100000, 999999);

        return Announcement::create([
            'title' => 'ANN-TEST-' . $suffix,
            'content' => 'Konten pengumuman pengujian ' . $suffix,
            'category' => 'umum',
            'publish_date' => '2026-08-01',
        ]);
    }

    private function cleanupTestData(): void
    {
        DB::connection()->statement('DELETE FROM announcements WHERE title LIKE "ANN-TEST-%"');
    }

    // ─── Authorization ───────────────────────────────────────

    public function test_guest_list_returns_401(): void
    {
        $this->getJson('/api/announcements')->assertStatus(401);
    }

    public function test_guest_cannot_create_announcement(): void
    {
        $this->postJson('/api/announcements', [
            'title' => 'ANN-TEST-Guest',
            'content' => 'x',
            'category' => 'umum',
        ])->assertStatus(401);
    }

    public function test_guru_cannot_create_announcement(): void
    {
        $this->authenticateAsGuru();

        $this->postJson('/api/announcements', [
            'title' => 'ANN-TEST-Guru',
            'content' => 'x',
            'category' => 'umum',
        ])->assertStatus(403);
    }

    // ─── List ────────────────────────────────────────────────

    public function test_admin_can_list_announcements(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestAnnouncement();

        $response = $this->getJson('/api/announcements');
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['success', 'message', 'data']);
    }

    // ─── Show ────────────────────────────────────────────────

    public function test_admin_can_view_announcement(): void
    {
        $this->authenticateAsAdmin();
        $ann = $this->createTestAnnouncement();

        $response = $this->getJson('/api/announcements/' . $ann->id);
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $ann->id);
        $response->assertJsonPath('data.category', 'umum');
        $response->assertJsonPath('data.publish_date', '2026-08-01');
    }

    public function test_show_nonexistent_announcement_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->getJson('/api/announcements/99999999')->assertStatus(404);
    }

    // ─── Create ──────────────────────────────────────────────

    public function test_admin_can_create_announcement(): void
    {
        $this->authenticateAsAdmin();
        $suffix = mt_rand(100000, 999999);

        $response = $this->postJson('/api/announcements', [
            'title' => 'ANN-TEST-' . $suffix,
            'content' => 'Konten pengumuman baru ' . $suffix,
            'category' => 'siswa',
            'publish_date' => '2026-08-10',
            'expired_date' => '2026-08-30',
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.category', 'siswa');
        $response->assertJsonPath('data.publish_date', '2026-08-10');

        $this->assertDatabaseHas('announcements', [
            'title' => 'ANN-TEST-' . $suffix,
            'category' => 'siswa',
        ]);
    }

    public function test_create_requires_title_and_content(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/announcements', ['category' => 'umum']);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'content']);
    }

    public function test_create_rejects_invalid_category(): void
    {
        $this->authenticateAsAdmin();

        $this->postJson('/api/announcements', [
            'title' => 'ANN-TEST-Invalid',
            'content' => 'x',
            'category' => 'akademik',
        ])->assertStatus(422)->assertJsonValidationErrors(['category']);
    }

    // ─── Update ──────────────────────────────────────────────

    public function test_admin_can_update_announcement(): void
    {
        $this->authenticateAsAdmin();
        $ann = $this->createTestAnnouncement();

        $response = $this->putJson('/api/announcements/' . $ann->id, [
            'title' => 'ANN-TEST-' . $ann->id . '-updated',
            'content' => 'Konten diperbarui',
            'category' => 'guru',
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $ann->id);
        $response->assertJsonPath('data.category', 'guru');
    }

    public function test_admin_can_patch_announcement(): void
    {
        $this->authenticateAsAdmin();
        $ann = $this->createTestAnnouncement();

        $response = $this->patchJson('/api/announcements/' . $ann->id, [
            'title' => 'ANN-TEST-' . $ann->id . '-patch',
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'ANN-TEST-' . $ann->id . '-patch');
    }

    public function test_update_nonexistent_announcement_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->putJson('/api/announcements/99999999', [
            'title' => 'x',
            'content' => 'x',
            'category' => 'umum',
        ])->assertStatus(404);
    }

    // ─── Delete ──────────────────────────────────────────────

    public function test_admin_can_delete_announcement(): void
    {
        $this->authenticateAsAdmin();
        $ann = $this->createTestAnnouncement();

        $response = $this->deleteJson('/api/announcements/' . $ann->id);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertSoftDeleted('announcements', ['id' => $ann->id]);
    }

    public function test_delete_nonexistent_announcement_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->deleteJson('/api/announcements/99999999')->assertStatus(404);
    }
}