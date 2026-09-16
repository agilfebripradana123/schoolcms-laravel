<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\Period;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeriodApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestPeriods();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestPeriods();
        parent::tearDown();
    }

    private function cleanupTestPeriods(): void
    {
        DB::connection()->table('periods')
            ->where('name', 'LIKE', 'Test Period%')
            ->delete();
    }

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function createTestPeriod(array $overrides = []): Period
    {
        $defaults = [
            'name' => 'Test Period ' . mt_rand(1000, 9999),
            'start_time' => '08:00',
            'end_time' => '08:40',
        ];

        return Period::create(array_merge($defaults, $overrides));
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/periods');
        $response->assertStatus(200);
    }

    public function test_index_returns_json_structure(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/periods');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ]);
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $period = $this->createTestPeriod();
        $response = $this->getJson("/api/periods/{$period->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticateAsAdmin();
        $period = $this->createTestPeriod();
        $response = $this->getJson("/api/periods/{$period->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $period->id,
                'name' => $period->name,
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/periods/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Period not found',
        ]);
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_store_creates_period(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/periods', [
            'name' => 'Test Period New',
            'start_time' => '09:00',
            'end_time' => '09:40',
        ]);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Period created successfully',
        ]);
    }

    public function test_store_requires_name(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/periods', [
            'start_time' => '09:00',
            'end_time' => '09:40',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_requires_start_time(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/periods', [
            'name' => 'Test Period',
            'end_time' => '09:40',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_time']);
    }

    public function test_store_requires_end_time(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/periods', [
            'name' => 'Test Period',
            'start_time' => '09:00',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_time']);
    }

    public function test_store_validates_time_format(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/periods', [
            'name' => 'Test Period',
            'start_time' => 'invalid',
            'end_time' => '09:40',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_time']);
    }

    public function test_store_validates_end_time_after_start_time(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/periods', [
            'name' => 'Test Period',
            'start_time' => '10:00',
            'end_time' => '09:00',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_time']);
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_update_changes_period(): void
    {
        $this->authenticateAsAdmin();
        $period = $this->createTestPeriod();
        $response = $this->putJson("/api/periods/{$period->id}", [
            'name' => 'Updated Period Name',
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Updated Period Name',
            ],
        ]);
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/periods/99999', [
            'name' => 'Updated',
        ]);
        $response->assertStatus(404);
    }

    // ─── Delete Tests ──────────────────────────────────────────

    public function test_delete_removes_period(): void
    {
        $this->authenticateAsAdmin();
        $period = $this->createTestPeriod();
        $response = $this->deleteJson("/api/periods/{$period->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Period deleted successfully',
        ]);
        $this->assertDatabaseMissing('periods', ['id' => $period->id]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/periods/99999');
        $response->assertStatus(404);
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/periods');
        $response->assertStatus(401);
    }
}
