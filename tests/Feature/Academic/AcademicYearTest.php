<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Semester;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    private ?int $savedActiveYearId = null;

    protected function setUp(): void
    {
        parent::setUp();



        $this->savedActiveYearId = AcademicYear::where('is_active', true)->value('id');
    }

    protected function tearDown(): void
    {
        // Restore pre-test active state so the shared dev DB is not left altered.
        AcademicYear::query()->update(['is_active' => false]);
        if ($this->savedActiveYearId !== null) {
            AcademicYear::where('id', $this->savedActiveYearId)->update(['is_active' => true]);
        }

        $this->cleanupTestAcademicYears();

        parent::tearDown();
    }

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function makeUniqueName(): string
    {
        return 'AY-TEST-'.mt_rand(100000, 999999);
    }

    private function cleanupTestAcademicYears(): void
    {
        AcademicYear::onlyTrashed()
            ->where('name', 'like', 'AY-TEST-%')
            ->get()
            ->each(fn ($year) => $year->forceDelete());

        DB::table('academic_years')
            ->where('name', 'like', 'AY-TEST-%')
            ->delete();
    }

    public function test_academic_year_can_be_created(): void
    {
        $this->authenticateAsAdmin();

        $name = $this->makeUniqueName();

        $response = $this->postJson('/api/academic-years', [
            'name' => $name,
            'start_date' => '2027-07-01',
            'end_date' => '2028-06-30',
            'is_active' => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', $name);

        $this->assertDatabaseHas('academic_years', [
            'name' => $name,
            'start_date' => '2027-07-01',
            'end_date' => '2028-06-30',
            'is_active' => 0,
        ]);
    }

    public function test_academic_year_can_be_activated(): void
    {
        $this->authenticateAsAdmin();

        $year = AcademicYear::create([
            'name' => $this->makeUniqueName(),
            'is_active' => false,
        ]);

        $response = $this->putJson('/api/academic-years/'.$year->id, [
            'is_active' => true,
        ]);

        $response->assertOk();

        $this->assertTrue((bool) $year->refresh()->is_active);
    }

    public function test_activating_one_academic_year_deactivates_the_previous_active(): void
    {
        $this->authenticateAsAdmin();

        $a = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => true]);
        $b = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);

        $this->putJson('/api/academic-years/'.$b->id, ['is_active' => true])->assertOk();

        $a->refresh();
        $b->refresh();

        $this->assertFalse((bool) $a->is_active);
        $this->assertTrue((bool) $b->is_active);
    }

    public function test_only_one_academic_year_is_active_after_activate(): void
    {
        $this->authenticateAsAdmin();

        $a = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => true]);
        $b = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);
        $c = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);

        $this->putJson('/api/academic-years/'.$b->id, ['is_active' => true])->assertOk();

        $activeCount = AcademicYear::where('is_active', true)->count();

        $this->assertEquals(1, $activeCount);
        $this->assertTrue((bool) $b->refresh()->is_active);
        $this->assertFalse((bool) $c->refresh()->is_active);
    }

    public function test_academic_year_has_many_semesters_relationship(): void
    {
        $year = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);
        $yearId = $year->id;

        Semester::create(['academic_year_id' => $yearId, 'name' => '1', 'is_active' => false]);
        Semester::create(['academic_year_id' => $yearId, 'name' => '2', 'is_active' => false]);

        $semesters = $year->semesters;

        $this->assertCount(2, $semesters);
        $this->assertContains('1', $semesters->pluck('name')->all());
        $this->assertContains('2', $semesters->pluck('name')->all());
    }
}
