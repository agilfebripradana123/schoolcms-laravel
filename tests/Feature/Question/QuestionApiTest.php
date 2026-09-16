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

class QuestionApiTest extends TestCase
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

    private function authenticate(): void
    {
        $user = User::first();
        Sanctum::actingAs($user);
    }

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
            'name' => 'Test User Question ' . $prefix,
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
            'question_text' => 'Test Question ' . mt_rand(100, 999),
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
        $questionIds = QuestionBank::where('question_text', 'like', 'Test Question %')
            ->orWhere('question_text', 'like', 'SEC-%')
            ->orWhere('question_text', 'like', 'API-%')
            ->pluck('id');

        QuestionOption::whereIn('question_id', $questionIds)->forceDelete();

        QuestionBank::where('question_text', 'like', 'Test Question %')->forceDelete();
        QuestionBank::where('question_text', 'like', 'SEC-%')->forceDelete();
        QuestionBank::where('question_text', 'like', 'API-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/questions');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_index(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_access_show(): void
    {
        $this->authenticate();
        $question = $this->createTestQuestion();
        $response = $this->getJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);
    }

    public function test_index_returns_json(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions');
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_index_response_structure(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions');
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
        $response->assertJson([
            'success' => true,
            'message' => 'Questions retrieved successfully',
        ]);
    }

    public function test_index_returns_questions(): void
    {
        $this->authenticate();
        $this->createTestQuestion();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_index_includes_subject_and_options(): void
    {
        $this->authenticate();
        $question = $this->createTestQuestion();
        $response = $this->getJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('subject', $data);
        $this->assertArrayHasKey('options', $data);
    }

    // ─── Search Tests ──────────────────────────────────────────

    public function test_search_by_question_text(): void
    {
        $this->authenticate();
        $this->createTestQuestion(['question_text' => 'API-SEARCH-Alpha']);
        $this->createTestQuestion(['question_text' => 'API-NOMATCH-Beta']);
        $response = $this->getJson('/api/questions?search=SEARCH-Alpha');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('SEARCH-Alpha', $item['question_text']);
        }
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions?search=NONEXISTENTXYZ');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    // ─── Pagination Tests ──────────────────────────────────────

    public function test_pagination_default_per_page(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(10, $meta['per_page']);
        $this->assertIsInt($meta['total']);
    }

    public function test_pagination_per_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions?per_page=5');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(5, $meta['per_page']);
    }

    public function test_pagination_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions?page=2&per_page=1');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(2, $meta['current_page']);
    }

    public function test_pagination_invalid_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_pagination_excessive_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions?per_page=101');
        $response->assertStatus(422);
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_subject_id(): void
    {
        $this->authenticate();
        $subject = $this->getTestSubject();
        $this->createTestQuestion(['subject_id' => $subject->id]);
        $response = $this->getJson("/api/questions?subject_id={$subject->id}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($subject->id, $item['subject_id']);
        }
    }

    public function test_filter_by_type(): void
    {
        $this->authenticate();
        $this->createTestQuestion(['question_text' => 'API-TYPE-ESSAY', 'type' => 'essay']);
        $response = $this->getJson('/api/questions?type=essay');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('essay', $item['type']);
        }
    }

    public function test_filter_by_difficulty(): void
    {
        $this->authenticate();
        $this->createTestQuestion(['question_text' => 'API-DIFF-EASY', 'difficulty' => 'easy']);
        $response = $this->getJson('/api/questions?difficulty=easy');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('easy', $item['difficulty']);
        }
    }

    public function test_filter_combined(): void
    {
        $this->authenticate();
        $subject = $this->getTestSubject();
        $this->createTestQuestion(['question_text' => 'API-COMB-HARD', 'subject_id' => $subject->id, 'difficulty' => 'hard']);
        $response = $this->getJson("/api/questions?subject_id={$subject->id}&difficulty=hard");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($subject->id, $item['subject_id']);
            $this->assertEquals('hard', $item['difficulty']);
        }
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticate();
        $question = $this->createTestQuestion();
        $response = $this->getJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticate();
        $subject = $this->getTestSubject();
        $question = $this->createTestQuestion([
            'subject_id' => $subject->id,
            'question_text' => 'API-SHOW-What is PHP?',
            'type' => 'multiple_choice',
            'difficulty' => 'easy',
            'points' => 5,
        ]);
        $response = $this->getJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $question->id,
                'question_text' => 'API-SHOW-What is PHP?',
                'type' => 'multiple_choice',
                'difficulty' => 'easy',
                'points' => 5,
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Question not found',
            'data' => null,
        ]);
    }

    public function test_show_excludes_deleted_at(): void
    {
        $this->authenticate();
        $question = $this->createTestQuestion();
        $response = $this->getJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    // ─── Store Auth Tests ──────────────────────────────────────

    public function test_admin_can_store_question(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-STORE-Admin MC question?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 2,
            'options' => [
                ['option_text' => 'A', 'is_correct' => true],
                ['option_text' => 'B', 'is_correct' => false],
                ['option_text' => 'C', 'is_correct' => false],
                ['option_text' => 'D', 'is_correct' => false],
            ],
        ]);
        $response->assertStatus(201);
    }

    public function test_administrator_can_store_question(): void
    {
        $this->authenticateAsAdministrator();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-STORE-Admin2 MC question?',
            'type' => 'multiple_choice',
            'difficulty' => 'easy',
            'points' => 1,
            'options' => [
                ['option_text' => 'Yes', 'is_correct' => true],
                ['option_text' => 'No', 'is_correct' => false],
                ['option_text' => 'Maybe', 'is_correct' => false],
            ],
        ]);
        $response->assertStatus(201);
    }

    public function test_guru_cannot_store_question(): void
    {
        $this->authenticateAsGuru();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'Guru question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_store_question(): void
    {
        $this->authenticateAsSiswa();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'Siswa question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(403);
    }

    // ─── Store Validation Tests ────────────────────────────────

    public function test_store_requires_subject_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/questions', [
            'question_text' => 'No subject?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    public function test_store_requires_question_text(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question_text']);
    }

    public function test_store_requires_type(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'No type?',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_store_requires_difficulty(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'No difficulty?',
            'type' => 'essay',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['difficulty']);
    }

    public function test_store_requires_points(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'No points?',
            'type' => 'essay',
            'difficulty' => 'medium',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['points']);
    }

    public function test_store_rejects_invalid_type(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'Invalid type?',
            'type' => 'invalid_type',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_store_rejects_invalid_difficulty(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'Invalid difficulty?',
            'type' => 'essay',
            'difficulty' => 'invalid',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['difficulty']);
    }

    public function test_store_rejects_invalid_subject_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/questions', [
            'subject_id' => 99999,
            'question_text' => 'Invalid subject?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    public function test_store_rejects_points_below_1(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'Zero points?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 0,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['points']);
    }

    public function test_store_rejects_negative_points(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'Negative points?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => -1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['points']);
    }

    // ─── Store Business Rules Tests ────────────────────────────

    public function test_store_mc_requires_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'MC without options?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['options']);
    }

    public function test_store_mc_requires_minimum_2_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'MC with 1 option?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 1,
            'options' => [
                ['option_text' => 'Only one', 'is_correct' => true],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_store_tf_requires_exactly_2_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'TF with 3 options?',
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

    public function test_store_tf_requires_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'TF without options?',
            'type' => 'true_false',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['options']);
    }

    public function test_store_essay_accepts_no_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'Essay question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 5,
        ]);
        $response->assertStatus(201);
        $this->assertEmpty($response->json('data.options'));
    }

    public function test_store_creates_database_record(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-DB-Record question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 3,
        ])->assertStatus(201);

        $this->assertDatabaseHas('question_banks', [
            'question_text' => 'API-DB-Record question?',
            'subject_id' => $subject->id,
        ]);
    }

    public function test_store_mc_with_options_creates_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-OPTS-MC with options?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 2,
            'options' => [
                ['option_text' => 'Option A', 'is_correct' => true],
                ['option_text' => 'Option B', 'is_correct' => false],
                ['option_text' => 'Option C', 'is_correct' => false],
                ['option_text' => 'Option D', 'is_correct' => false],
            ],
        ]);
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertCount(4, $data['options']);
        $correctCount = 0;
        foreach ($data['options'] as $opt) {
            if ($opt['is_correct']) {
                $correctCount++;
            }
        }
        $this->assertEquals(1, $correctCount);
    }

    public function test_store_tf_with_2_options(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-OPTS-TF with 2 options?',
            'type' => 'true_false',
            'difficulty' => 'easy',
            'points' => 1,
            'options' => [
                ['option_text' => 'True', 'is_correct' => true],
                ['option_text' => 'False', 'is_correct' => false],
            ],
        ]);
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertCount(2, $data['options']);
    }

    public function test_store_returns_created_status(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-201 question?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(201);
    }

    public function test_store_returns_data(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-RET question?',
            'type' => 'essay',
            'difficulty' => 'easy',
            'points' => 10,
        ]);
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('API-RET question?', $data['question_text']);
        $this->assertEquals('essay', $data['type']);
        $this->assertEquals('easy', $data['difficulty']);
        $this->assertEquals(10, $data['points']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    // ─── Update Auth Tests ─────────────────────────────────────

    public function test_admin_can_update_question(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion();
        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'Updated by admin?',
        ]);
        $response->assertStatus(200);
    }

    public function test_administrator_can_update_question(): void
    {
        $this->authenticateAsAdministrator();
        $question = $this->createTestQuestion();
        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'Updated by admin2?',
        ]);
        $response->assertStatus(200);
    }

    public function test_guru_cannot_update_question(): void
    {
        $this->authenticateAsGuru();
        $question = $this->createTestQuestion();
        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'Guru update?',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update_question(): void
    {
        $this->authenticateAsSiswa();
        $question = $this->createTestQuestion();
        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'Siswa update?',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_patch_question(): void
    {
        $this->authenticateAsGuru();
        $question = $this->createTestQuestion();
        $response = $this->patchJson("/api/questions/{$question->id}", [
            'question_text' => 'Guru patch?',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_patch_question(): void
    {
        $this->authenticateAsSiswa();
        $question = $this->createTestQuestion();
        $response = $this->patchJson("/api/questions/{$question->id}", [
            'question_text' => 'Siswa patch?',
        ]);
        $response->assertStatus(403);
    }

    // ─── Update Behavior Tests ─────────────────────────────────

    public function test_update_changes_question_text(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'API-OLD-Original text?']);
        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'API-NEW-Updated text?',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('API-NEW-Updated text?', $response->json('data.question_text'));
    }

    public function test_patch_updates_single_field(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['difficulty' => 'easy', 'points' => 1]);
        $response = $this->patchJson("/api/questions/{$question->id}", [
            'difficulty' => 'hard',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('hard', $response->json('data.difficulty'));
        $this->assertEquals(1, $response->json('data.points'));
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/questions/99999', [
            'question_text' => 'Update nonexistent?',
        ]);
        $response->assertStatus(404);
    }

    public function test_update_replaces_options_when_provided(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion([
            'question_text' => 'API-REPLACE-Options test?',
            'type' => 'multiple_choice',
        ]);
        $oldOptionCount = $question->options()->count();
        $this->assertEquals(4, $oldOptionCount);

        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'API-REPLACE-Options test?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 1,
            'options' => [
                ['option_text' => 'New A', 'is_correct' => true],
                ['option_text' => 'New B', 'is_correct' => false],
                ['option_text' => 'New C', 'is_correct' => false],
            ],
        ]);
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(3, $data['options']);
    }

    public function test_update_keeps_options_when_not_provided(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion([
            'question_text' => 'API-KEEP-Keep options test?',
            'type' => 'multiple_choice',
        ]);
        $originalOptions = $question->options()->pluck('option_text')->toArray();

        $response = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'API-KEEP-Keep options test?',
        ]);
        $response->assertStatus(200);
        $this->assertCount(4, $response->json('data.options'));
    }

    // ─── Delete Auth Tests ─────────────────────────────────────

    public function test_admin_can_delete_question(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion();
        $response = $this->deleteJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_delete_question(): void
    {
        $this->authenticateAsAdministrator();
        $question = $this->createTestQuestion();
        $response = $this->deleteJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_delete_question(): void
    {
        $this->authenticateAsGuru();
        $question = $this->createTestQuestion();
        $response = $this->deleteJson("/api/questions/{$question->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete_question(): void
    {
        $this->authenticateAsSiswa();
        $question = $this->createTestQuestion();
        $response = $this->deleteJson("/api/questions/{$question->id}");
        $response->assertStatus(403);
    }

    // ─── Delete Behavior Tests ─────────────────────────────────

    public function test_delete_soft_deletes(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'API-SDEL Soft delete test?']);
        $questionId = $question->id;
        $this->deleteJson("/api/questions/{$questionId}")->assertStatus(200);
        $this->assertSoftDeleted('question_banks', ['id' => $questionId]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/questions/99999');
        $response->assertStatus(404);
    }

    public function test_deleted_question_not_in_index(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'API-NOTINIDX?']);
        $this->deleteJson("/api/questions/{$question->id}")->assertStatus(200);
        $response = $this->getJson('/api/questions');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($question->id, $item['id']);
        }
    }

    public function test_deleted_question_returns_404_on_show(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion(['question_text' => 'API-DEL404?']);
        $this->deleteJson("/api/questions/{$question->id}")->assertStatus(200);
        $this->getJson("/api/questions/{$question->id}")->assertStatus(404);
    }

    public function test_delete_preserves_options(): void
    {
        $this->authenticateAsAdmin();
        $question = $this->createTestQuestion([
            'question_text' => 'API-PRESOPTS?',
            'type' => 'multiple_choice',
        ]);
        $optionIds = $question->options()->pluck('id')->toArray();
        $this->assertNotEmpty($optionIds);

        $this->deleteJson("/api/questions/{$question->id}")->assertStatus(200);

        foreach ($optionIds as $optId) {
            $this->assertDatabaseHas('question_options', ['id' => $optId]);
        }
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/questions/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/questions/99999', [
            'question_text' => 'IDOR update?',
        ]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/questions/99999');
        $response->assertStatus(404);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'id' => 99999,
            'subject_id' => $subject->id,
            'question_text' => 'API-MA-ID?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-MA-CAT?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $subject = $this->getTestSubject();
        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'question_text' => 'API-MA-DAT?',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 1,
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(QuestionBank::find($response->json('data.id'))->deleted_at);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $this->createTestQuestion();
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $question = $this->createTestQuestion();
        $response = $this->getJson("/api/questions/{$question->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }
}
