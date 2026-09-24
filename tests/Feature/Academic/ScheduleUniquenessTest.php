<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Period;
use App\Models\Academic\Schedule;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\QueryException;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B-A03 (DB-1): schedule slot uniqueness must be scoped per semester.
 *
 * Covers: same-semester duplicate rejected; different-semester same-slot
 * allowed; different academic year allowed; different class allowed; update
 * collision rejected; nullable semester policy; database-level protection.
 */
class ScheduleUniquenessTest extends TestCase
{
    private int $classAId;
    private int $classBId;
    private int $subjectId;
    private int $periodId;
    private int $yearAId;
    private int $yearBId;
    private int $semA1;
    private int $semA2;
    private int $semB1;

    /** @var int[] */
    private array $createdScheduleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->classAId = SchoolClass::query()->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->classBId = SchoolClass::query()->whereNull('deleted_at')->orderBy('id')->skip(1)->value('id');
        $this->subjectId = Subject::query()->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->periodId = Period::query()->orderBy('id')->value('id');

        $suffix = substr(str_replace('.', '', (string) microtime(true)), -6);

        $yearA = AcademicYear::query()->create(['name' => "B-A03 Y{$suffix}", 'is_active' => true]);
        $this->yearAId = (int) $yearA->id;
        $this->semA1 = (int) Semester::query()->create(['academic_year_id' => $yearA->id, 'name' => '1', 'is_active' => true])->id;
        $this->semA2 = (int) Semester::query()->create(['academic_year_id' => $yearA->id, 'name' => '2', 'is_active' => false])->id;

        $yearB = AcademicYear::query()->create(['name' => "B-A03 Z{$suffix}", 'is_active' => false]);
        $this->yearBId = (int) $yearB->id;
        $this->semB1 = (int) Semester::query()->create(['academic_year_id' => $yearB->id, 'name' => '1', 'is_active' => false])->id;
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdScheduleIds)) {
            Schedule::query()->whereIn('id', $this->createdScheduleIds)->delete();
        }

        AcademicYear::query()->whereIn('id', [$this->yearAId, $this->yearBId])->delete();

        parent::tearDown();
    }

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::query()->where('name', 'Admin')->first();
        $user = User::query()->where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function postSchedule(array $overrides = []): TestResponse
    {
        $payload = array_merge([
            'class_id' => $this->classAId,
            'subject_id' => $this->subjectId,
            'day' => 'senin',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->yearAId,
        ], $overrides);

        $response = $this->postJson('/api/schedules', $payload);

        if ($response->status() === 201) {
            $this->createdScheduleIds[] = (int) $response->json('data.id');
        }

        return $response;
    }

    public function test_a_same_semester_duplicate_slot_is_rejected(): void
    {
        $this->authenticateAsAdmin();
        $this->postSchedule(['semester_id' => $this->semA1])->assertStatus(201);

        $response = $this->postSchedule(['semester_id' => $this->semA1]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
        $this->assertSame(1, Schedule::query()
            ->where('class_id', $this->classAId)
            ->where('day', 'senin')
            ->where('period_id', $this->periodId)
            ->where('academic_year_id', $this->yearAId)
            ->where('semester_id', $this->semA1)
            ->count());
    }

    public function test_b_different_semester_same_slot_is_allowed(): void
    {
        $this->authenticateAsAdmin();
        $this->postSchedule(['semester_id' => $this->semA1])->assertStatus(201);
        $this->postSchedule(['semester_id' => $this->semA2])->assertStatus(201);

        $this->assertSame(2, Schedule::query()
            ->where('class_id', $this->classAId)
            ->where('day', 'senin')
            ->where('period_id', $this->periodId)
            ->where('academic_year_id', $this->yearAId)
            ->count());
    }

    public function test_c_different_academic_year_is_allowed(): void
    {
        $this->authenticateAsAdmin();
        $this->postSchedule(['semester_id' => $this->semA1])->assertStatus(201);
        $this->postSchedule(['academic_year_id' => $this->yearBId, 'semester_id' => $this->semB1])->assertStatus(201);
    }

    public function test_d_different_class_is_allowed(): void
    {
        $this->authenticateAsAdmin();
        $this->postSchedule(['semester_id' => $this->semA1])->assertStatus(201);
        $this->postSchedule(['class_id' => $this->classBId, 'semester_id' => $this->semA1])->assertStatus(201);
    }

    public function test_e_update_collision_is_rejected(): void
    {
        $this->authenticateAsAdmin();
        $this->postSchedule(['semester_id' => $this->semA1, 'day' => 'senin'])->assertStatus(201);
        $second = $this->postSchedule(['semester_id' => $this->semA1, 'day' => 'selasa'])->assertStatus(201);
        $id = (int) $second->json('data.id');

        $response = $this->putJson("/api/schedules/{$id}", [
            'class_id' => $this->classAId,
            'subject_id' => $this->subjectId,
            'day' => 'senin',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->yearAId,
            'semester_id' => $this->semA1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
        $this->assertSame('selasa', Schedule::query()->find($id)->day);
    }

    public function test_f_nullable_semester_rows_are_not_slot_collision_scoped(): void
    {
        $this->authenticateAsAdmin();
        $this->postSchedule()->assertStatus(201);
        $this->postSchedule()->assertStatus(201);

        $this->assertSame(2, Schedule::query()
            ->whereNull('semester_id')
            ->where('class_id', $this->classAId)
            ->count());
    }

    public function test_g_database_constraint_rejects_same_semester_duplicate(): void
    {
        $first = Schedule::query()->create([
            'class_id' => $this->classAId,
            'subject_id' => $this->subjectId,
            'day' => 'senin',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->yearAId,
            'semester_id' => $this->semA1,
        ]);
        $this->createdScheduleIds[] = (int) $first->id;

        $this->expectException(QueryException::class);
        Schedule::query()->create([
            'class_id' => $this->classAId,
            'subject_id' => $this->subjectId,
            'day' => 'senin',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->yearAId,
            'semester_id' => $this->semA1,
        ]);
    }
}