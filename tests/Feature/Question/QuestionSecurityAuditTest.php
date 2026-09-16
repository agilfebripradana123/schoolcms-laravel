<?php

namespace Tests\Feature\Question;

use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use App\Models\System\Role;
use App\Models\Academic\Subject;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionSecurityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestQuestions();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestQuestions();
        parent::tearDown();
    }

    // ─── Auth Helpers ──────────────────────────────────────────

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsAdministrator(): void
    {
        $adminRole = Role::where('name', 'Administrator')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsGuru(): void
    {
        $guruRole = Role::where('name', 'Guru')->first();
        $user = User::where('role_id', $guruRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsSiswa(): void
    {
        $siswaRole = Role::where('name', 'Siswa')->first();
        $user = $this->createTestUser($siswaRole->id, 'siswa');
        Sanctum::actingAs($user);
    }

    // ─── Data Helpers ──────────────────────────────────────────

    private function createTestUser(int $roleId, string $prefix = 'test'): User
    {
        return User::create([
            'username' => $prefix . '_' . mt_rand(100000, 999999),
            'name' => 'Test User Question Audit ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function getTestSubject(): Subject
    {
        return Subject::whereNull('deleted_at')->first();
    }

    private function createTestQuestion(array $overrides = []): QuestionBank
    {
        $subject = $this->getTestSubject();

        $defaults = [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-' . mt_rand(100, 999) . ' Question?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 1,
        ];

        $question = QuestionBank::create(array_merge($defaults, $overrides));

        if ($question->type === 'multiple_choice') {
            $this->createTestOptions($question->id, 4, 0);
        } elseif ($question->type === 'true_false') {
            $this->createTestOptions($question->id, 2, 0);
        }

        return $question;
    }

    private function createTestOptions(int $questionId, int $count = 4, int $correctIndex = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            QuestionOption::create([
                'question_id' => $questionId,
                'option_text' => 'Option ' . ($i + 1),
                'is_correct' => $i === $correctIndex,
            ]);
        }
    }

    // ─── Cleanup Helpers ───────────────────────────────────────

    private function cleanupTestQuestions(): void
    {
        $questionIds = QuestionBank::where('question_text', 'like', 'SEC-%')
            ->pluck('id');

        QuestionOption::whereIn('question_id', $questionIds)->forceDelete();

        QuestionBank::where('question_text', 'like', 'SEC-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_index_returns_401(): void
    {
        $response = $this->getJson('/api/questions');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_show_returns_401(): void
    {
        $response = $this->getJson('/api/questions/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_store_returns_401(): void
    {
        $response = $this->postJson('/api/questions', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_update_returns_401(): void
    {
        $response = $this->putJson('/api/questions/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_patch_returns_401(): void
    {
        $response = $this->patchJson('/api/questions/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $response = $this->deleteJson('/api/questions/1');
        $response->assertStatus(401);
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_guru_can_read_index(): void
    {
        $this->authenticateAsGuru();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);
    }

    public function test_guru_can_read_show(): void
    {
        $this->authenticateAsGuru();
        $question = $this->createTestQuestion();
        $response = $this->getJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-GURU question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();
        $question = $this->createTestQuestion();
        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'SEC-GURU update?',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_patch(): void
    {
        $this->authenticateAsGuru();
        $question = $this->createTestQuestion();
        $response = $this->patchJson("/api/questions/{$question->id}", [
            'question_text' => 'SEC-GURU patch?',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $question = $this->createTestQuestion();
        $response = $this->deleteJson("/api/questions/{$question->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_can_read_index(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_show(): void
    {
        $this->authenticateAsSiswa();
        $question = $this->createTestQuestion();
        $response = $this->getJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-SISWA question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update(): void
    {
        $this->authenticateAsSiswa();
        $question = $this->createTestQuestion();
        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'SEC-SISWA update?',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_patch(): void
    {
        $this->authenticateAsSiswa();
        $question = $this->createTestQuestion();
        $response = $this->patchJson("/api/questions/{$question->id}", [
            'question_text' => 'SEC-SISWA patch?',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $question = $this->createTestQuestion();
        $response = $this->deleteJson("/api/questions/{$question->id}");
        $response->assertStatus(403);
    }

    public function test_admin_can_all_operations(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);

        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-ADM question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(201);
        $questionId = $response->json('data.id');

        $response = $this->getJson("/api/questions/{$questionId}");
        $response->assertStatus(200);

        $response = $this->putJson("/api/questions/{$questionId}", [
            'question_text' => 'SEC-ADM updated?',
        ]);
        $response->assertStatus(200);

        $response = $this->patchJson("/api/questions/{$questionId}", [
            'difficulty' => 'hard',
        ]);
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/questions/{$questionId}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_all_operations(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);

        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-ADM2 question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(201);
        $questionId = $response->json('data.id');

        $response = $this->getJson("/api/questions/{$questionId}");
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/questions/{$questionId}");
        $response->assertStatus(200);
    }

    // ─── Soft-Delete Tests ─────────────────────────────────────

    public function test_soft_delete_sets_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'SEC-DELTEST?']);
        $this->deleteJson("/api/questions/{$question->id}")->assertStatus(200);

        $dbQuestion = QuestionBank::withTrashed()->find($question->id);
        $this->assertNotNull($dbQuestion->deleted_at);
    }

    public function test_soft_deleted_not_in_active_query(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'SEC-NOACT?']);
        $this->deleteJson("/api/questions/{$question->id}")->assertStatus(200);

        $response = $this->getJson('/api/questions');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($question->id, $item['id']);
        }
    }

    public function test_soft_deleted_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'SEC-404?']);
        $this->deleteJson("/api/questions/{$question->id}")->assertStatus(200);

        $this->getJson("/api/questions/{$question->id}")->assertStatus(404);
    }

    public function test_soft_deleted_preserves_data(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'SEC-PRESERVE?']);
        $this->deleteJson("/api/questions/{$question->id}")->assertStatus(200);

        $dbQuestion = QuestionBank::withTrashed()->find($question->id);
        $this->assertEquals('SEC-PRESERVE?', $dbQuestion->question_text);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'id' => 99999,
            'subject_id' => $subject->id,
            'question_text' => 'SEC-MAI?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at_field(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-MAC?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_updated_at_field(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-MAU?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
            'updated_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.updated_at'));
    }

    public function test_store_ignores_deleted_at_field(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-MAD?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(QuestionBank::find($response->json('data.id'))->deleted_at);
    }

    // ─── Input Validation Security Tests ───────────────────────

    public function test_store_rejects_invalid_type(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-INVTYPE?',
            'type' => 'invalid',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_difficulty(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-INVDIFF?',
            'type' => 'essay',
            'difficulty' => 'invalid',
            'points' => 1,
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/questions', []);
        $response->assertStatus(422);
    }

    public function test_update_allows_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'SEC-EMPTYBODY?']);
        $response = $this->putJson("/api/questions/{$question->id}", []);
        $response->assertStatus(200);
        $this->assertDatabaseHas('question_banks', ['id' => $question->id]);
    }

    public function test_store_rejects_invalid_subject_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/questions', [
            'subject_id' => 99999,
            'question_text' => 'SEC-INVSUBJ?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_points_zero(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-ZEROPTS?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 0,
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_negative_points(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-NEGPTS?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => -5,
        ]);
        $response->assertStatus(422);
    }

    // ─── Options Validation Tests ──────────────────────────────

    public function test_mc_requires_minimum_2_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-MC-1OPT?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 1,
            'options' => [
                ['option_text' => 'Only one', 'is_correct' => true],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_tf_requires_exactly_2_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-TF-3OPT?',
            'type' => 'true_false',
            'difficulty' => 'medium',
            'points' => 1,
            'options' => [
                ['option_text' => 'True', 'is_correct' => true],
                ['option_text' => 'False', 'is_correct' => false],
                ['option_text' => 'Maybe', 'is_correct' => false],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_essay_options_array_must_be_empty(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'SEC-ESSAY-OPTS?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
            'options' => [
                ['option_text' => 'Should not be here', 'is_correct' => false],
            ],
        ]);
        $response->assertStatus(422);
    }

    // ─── Pagination Security Tests ─────────────────────────────

    public function test_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/questions?per_page=0');
        $response->assertStatus(422);
    }

    public function test_negative_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/questions?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_excessive_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/questions?per_page=101');
        $response->assertStatus(422);
    }

    public function test_string_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/questions?per_page=abc');
        $response->assertStatus(422);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/questions/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/questions/99999', [
            'question_text' => 'SEC-IDOR?',
        ]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/questions/99999');
        $response->assertStatus(404);
    }

    // ─── Filter Validation Tests ───────────────────────────────

    public function test_invalid_type_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/questions?type=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_difficulty_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/questions?difficulty=invalid');
        $response->assertStatus(422);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestQuestion(['question_text' => 'SEC-NOEXPOSED1?']);
        $response = $this->getJson('/api/questions');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'SEC-NOEXPOSED2?']);
        $response = $this->getJson("/api/questions/{$question->id}");
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    public function test_no_password_fields_exposed(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'SEC-NOPWD?']);
        $response = $this->getJson("/api/questions/{$question->id}");
        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
    }

    // ─── Sorting Tests ─────────────────────────────────────────

    public function test_fixed_sorting_by_id_desc(): void
    {
        $this->authenticateAsAdmin();
        $q1 = $this->createTestQuestion(['question_text' => 'SEC-SORT1?']);
        $q2 = $this->createTestQuestion(['question_text' => 'SEC-SORT2?']);
        $response = $this->getJson('/api/questions');
        $data = $response->json('data');
        if (count($data) >= 2) {
            $this->assertGreaterThanOrEqual($data[1]['id'], $data[0]['id']);
        }
    }

    // ─── forceDelete Protection Tests ──────────────────────────

    public function test_destroy_uses_soft_delete_not_force(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'SEC-FORCEDEL?']);
        $questionId = $question->id;
        $this->deleteJson("/api/questions/{$questionId}")->assertStatus(200);
        $this->assertDatabaseHas('question_banks', ['id' => $questionId]);
        $dbQuestion = QuestionBank::withTrashed()->find($questionId);
        $this->assertNotNull($dbQuestion->deleted_at);
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_database_unchanged_after_read_operations(): void
    {
        $this->authenticateAsAdmin();
        $beforeCount = QuestionBank::count();
        $this->getJson('/api/questions');
        $this->createTestQuestion(['question_text' => 'SEC-DBINT?']);
        $this->getJson('/api/questions');
        $afterCount = QuestionBank::count();
        $this->assertEquals($beforeCount + 1, $afterCount);
    }

    public function test_database_schema_unchanged(): void
    {
        $this->authenticateAsAdmin();
        $columns = DB::connection()->select('SHOW COLUMNS FROM question_banks');
        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('subject_id', $columnNames);
        $this->assertContains('instruction_id', $columnNames);
        $this->assertContains('question_text', $columnNames);
        $this->assertContains('question_image', $columnNames);
        $this->assertContains('type', $columnNames);
        $this->assertContains('difficulty', $columnNames);
        $this->assertContains('explanation', $columnNames);
        $this->assertContains('points', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);
        $this->assertContains('deleted_at', $columnNames);
        $this->assertCount(12, $columns);
    }
}
