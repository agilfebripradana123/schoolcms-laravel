<?php

namespace Tests\Feature\Communication;

use App\Models\Communication\Calendar;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalendarApiTest extends TestCase
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

    private function createTestEvent(): Calendar
    {
        $suffix = mt_rand(100000, 999999);

        return Calendar::create([
            'title' => 'CAL-TEST-' . $suffix,
            'description' => 'Agenda pengujian ' . $suffix,
            'event_date' => '2026-10-05',
            'type' => 'rapat',
        ]);
    }

    private function cleanupTestData(): void
    {
        DB::connection()->statement('DELETE FROM calendars WHERE title LIKE "CAL-TEST-%"');
    }

    // ─── Authorization ───────────────────────────────────────

    public function test_guest_list_returns_401(): void
    {
        $this->getJson('/api/calendars')->assertStatus(401);
    }

    public function test_guest_cannot_create_event(): void
    {
        $this->postJson('/api/calendars', [
            'title' => 'CAL-TEST-Guest',
            'event_date' => '2026-10-05',
            'type' => 'umum',
        ])->assertStatus(401);
    }

    public function test_guru_cannot_create_event(): void
    {
        $this->authenticateAsGuru();

        $this->postJson('/api/calendars', [
            'title' => 'CAL-TEST-Guru',
            'event_date' => '2026-10-05',
            'type' => 'umum',
        ])->assertStatus(403);
    }

    // ─── List + Filter ───────────────────────────────────────

    public function test_admin_can_list_events(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestEvent();

        $response = $this->getJson('/api/calendars?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
    }

    public function test_admin_can_filter_events_by_type(): void
    {
        $this->authenticateAsAdmin();
        $event = $this->createTestEvent();

        $response = $this->getJson('/api/calendars?type=libur');
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($event->id), 'Agenda rapat tidak boleh muncul pada filter libur.');
    }

    public function test_admin_can_search_events(): void
    {
        $this->authenticateAsAdmin();
        $event = $this->createTestEvent();

        $needle = substr($event->title, 9);
        $response = $this->getJson('/api/calendars?q=' . $needle);
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($event->id), 'Hasil pencarian harus memuat agenda yang dibuat.');
    }

    // ─── Show ────────────────────────────────────────────────

    public function test_admin_can_view_event(): void
    {
        $this->authenticateAsAdmin();
        $event = $this->createTestEvent();

        $response = $this->getJson('/api/calendars/' . $event->id);
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $event->id);
        $response->assertJsonPath('data.type', 'rapat');
        $response->assertJsonPath('data.event_date', '2026-10-05');
    }

    public function test_show_nonexistent_event_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->getJson('/api/calendars/99999999')->assertStatus(404);
    }

    // ─── Create ──────────────────────────────────────────────

    public function test_admin_can_create_event(): void
    {
        $this->authenticateAsAdmin();
        $suffix = mt_rand(100000, 999999);

        $response = $this->postJson('/api/calendars', [
            'title' => 'CAL-TEST-' . $suffix,
            'description' => 'Agenda baru',
            'event_date' => '2026-11-01',
            'type' => 'kegiatan',
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'kegiatan');
        $response->assertJsonPath('data.event_date', '2026-11-01');

        $this->assertDatabaseHas('calendars', [
            'title' => 'CAL-TEST-' . $suffix,
            'type' => 'kegiatan',
        ]);
    }

    public function test_create_requires_title_and_event_date(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/calendars', ['type' => 'umum']);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'event_date']);
    }

    public function test_create_rejects_invalid_type(): void
    {
        $this->authenticateAsAdmin();

        $this->postJson('/api/calendars', [
            'title' => 'CAL-TEST-Invalid',
            'event_date' => '2026-11-01',
            'type' => 'rapat_invalid',
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    // ─── Update ──────────────────────────────────────────────

    public function test_admin_can_update_event(): void
    {
        $this->authenticateAsAdmin();
        $event = $this->createTestEvent();

        $response = $this->putJson('/api/calendars/' . $event->id, [
            'title' => 'CAL-TEST-' . $event->id . '-updated',
            'event_date' => '2026-12-01',
            'type' => 'ujian',
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $event->id);
        $response->assertJsonPath('data.type', 'ujian');
        $response->assertJsonPath('data.event_date', '2026-12-01');
    }

    public function test_admin_can_patch_event(): void
    {
        $this->authenticateAsAdmin();
        $event = $this->createTestEvent();

        $response = $this->patchJson('/api/calendars/' . $event->id, [
            'type' => 'libur',
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'libur');
    }

    public function test_update_nonexistent_event_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->putJson('/api/calendars/99999999', [
            'title' => 'x',
            'event_date' => '2026-01-01',
            'type' => 'umum',
        ])->assertStatus(404);
    }

    // ─── Delete ──────────────────────────────────────────────

    public function test_admin_can_delete_event(): void
    {
        $this->authenticateAsAdmin();
        $event = $this->createTestEvent();

        $response = $this->deleteJson('/api/calendars/' . $event->id);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseMissing('calendars', ['id' => $event->id]);
    }

    public function test_delete_nonexistent_event_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->deleteJson('/api/calendars/99999999')->assertStatus(404);
    }
}