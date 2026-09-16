<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Semester;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SemesterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();


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

    private function makeUniqueName(): string
    {
        return 'AY-TEST-'.mt_rand(100000, 999999);
    }

    private function cleanupTestData(): void
    {
        $yearIds = DB::table('academic_years')
            ->where('name', 'like', 'AY-TEST-%')
            ->pluck('id');

        if ($yearIds->isNotEmpty()) {
            DB::table('semesters')->whereIn('academic_year_id', $yearIds)->delete();
        }

        DB::table('academic_years')->where('name', 'like', 'AY-TEST-%')->delete();
    }

    public function test_semester_can_be_created(): void
    {
        $this->authenticateAsAdmin();

        $year = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);

        $response = $this->postJson('/api/semesters', [
            'academic_year_id' => $year->id,
            'name' => '1',
            'is_active' => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', '1')
            ->assertJsonPath('data.academic_year_id', $year->id);
    }

    public function test_semester_belongs_to_academic_year(): void
    {
        $year = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);
        $semester = Semester::create(['academic_year_id' => $year->id, 'name' => '1', 'is_active' => false]);

        $this->assertInstanceOf(AcademicYear::class, $semester->academicYear);
        $this->assertEquals($year->id, $semester->academicYear->id);
    }

    public function test_both_semesters_can_exist_in_same_academic_year(): void
    {
        $year = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);

        $s1 = Semester::create(['academic_year_id' => $year->id, 'name' => '1', 'is_active' => false]);
        $s2 = Semester::create(['academic_year_id' => $year->id, 'name' => '2', 'is_active' => false]);

        $this->assertEquals('1', $s1->name);
        $this->assertEquals('2', $s2->name);
        $this->assertCount(2, $year->semesters);
    }

    public function test_duplicate_semester_in_same_academic_year_is_rejected(): void
    {
        $this->authenticateAsAdmin();

        $year = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);
        Semester::create(['academic_year_id' => $year->id, 'name' => '1', 'is_active' => false]);

        $response = $this->postJson('/api/semesters', [
            'academic_year_id' => $year->id,
            'name' => '1',
            'is_active' => false,
        ]);

        $response->assertStatus(422);

        $this->assertEquals(
            1,
            Semester::where('academic_year_id', $year->id)->where('name', '1')->count()
        );
    }

    public function test_duplicate_semester_is_rejected_at_database_level(): void
    {
        $year = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);
        Semester::create(['academic_year_id' => $year->id, 'name' => '1', 'is_active' => false]);

        $this->expectException(QueryException::class);

        Semester::create(['academic_year_id' => $year->id, 'name' => '1', 'is_active' => false]);
    }

    public function test_activating_semester_2_deactivates_semester_1_in_same_academic_year(): void
    {
        $this->authenticateAsAdmin();

        $year = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);
        $s1 = Semester::create(['academic_year_id' => $year->id, 'name' => '1', 'is_active' => true]);
        $s2 = Semester::create(['academic_year_id' => $year->id, 'name' => '2', 'is_active' => false]);

        $this->putJson('/api/semesters/'.$s2->id, ['is_active' => true])->assertOk();

        $this->assertFalse((bool) $s1->refresh()->is_active);
        $this->assertTrue((bool) $s2->refresh()->is_active);
    }

    public function test_active_semester_scope_is_per_academic_year(): void
    {
        $this->authenticateAsAdmin();

        $yearA = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);
        $yearB = AcademicYear::create(['name' => $this->makeUniqueName(), 'is_active' => false]);

        $sA = Semester::create(['academic_year_id' => $yearA->id, 'name' => '1', 'is_active' => true]);
        $sB = Semester::create(['academic_year_id' => $yearB->id, 'name' => '1', 'is_active' => false]);

        // Activating semester in year B must not affect the active semester in year A.
        $this->putJson('/api/semesters/'.$sB->id, ['is_active' => true])->assertOk();

        $this->assertTrue((bool) $sA->refresh()->is_active);
        $this->assertTrue((bool) $sB->refresh()->is_active);
    }
}
