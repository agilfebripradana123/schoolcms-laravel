<?php

namespace Tests\Feature;

use App\Models\Academic\Subject;
use App\Models\Examination\QuestionBank;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2C — Question Bank foundation.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches the live MySQL database. The 'mysql'
 * guard in the controllers was replaced by default-connection transactions so
 * this suite exercises the real request path end-to-end.
 */
class QuestionBankFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
    }

    private function buildSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('description')->nullable();
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->primary(['permission_id', 'role_id']);
        });
        Schema::create('permission_user', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('user_id');
            $t->primary(['permission_id', 'user_id']);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->string('username')->nullable();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('type')->nullable();
            $t->string('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('question_banks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('instruction_id')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('code')->nullable();
            $t->text('question_text');
            $t->string('question_image')->nullable();
            $t->string('audio_url')->nullable();
            $t->string('video_url')->nullable();
            $t->string('type');
            $t->string('difficulty')->default('medium');
            $t->string('cognitive_level')->nullable();
            $t->text('competency')->nullable();
            $t->text('indicator')->nullable();
            $t->text('explanation')->nullable();
            $t->unsignedInteger('points')->default(1);
            $t->string('status')->default('draft');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('question_options', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->text('option_text');
            $t->string('option_image')->nullable();
            $t->boolean('is_correct')->default(false);
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin Q', 'email' => 'admin@q2c.test', 'username' => 'adminq', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $this->guru = User::create(['name' => 'Guru Q', 'email' => 'guru@q2c.test', 'username' => 'guruq', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $this->siswa = User::create(['name' => 'Siswa Q', 'email' => 'siswa@q2c.test', 'username' => 'siswaq', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->subject = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
    }

    private function mcPayload(array $overrides = []): array
    {
        return array_merge([
            'subject_id' => $this->subject->id,
            'question_text' => 'Sec Q?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 10,
            'options' => [
                ['option_text' => 'A', 'is_correct' => true],
                ['option_text' => 'B', 'is_correct' => false],
            ],
        ], $overrides);
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    // -----------------------------------------------------------------
    // A. CRUD
    // -----------------------------------------------------------------

    public function test_admin_can_create_multiple_choice_question(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/questions', $this->mcPayload());
        $res->assertStatus(201);

        $data = $res->json('data');
        $this->assertSame($data['code'], 'Q-'.str_pad((string) $data['id'], 6, '0', STR_PAD_LEFT));
        $this->assertSame('draft', $data['status']);
        $this->assertSame($this->admin->id, $data['owner_id']);
        $this->assertCount(2, $data['options']);
        $this->assertSame('A', $data['options'][0]['option_text']);
        $this->assertTrue($data['options'][0]['is_correct']);

        $this->assertDatabaseHas('question_banks', ['id' => $data['id'], 'code' => $data['code']], null);
    }

    public function test_admin_can_create_essay_question(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/questions', [
            'subject_id' => $this->subject->id,
            'question_text' => 'Uraikan?',
            'type' => 'essay',
            'difficulty' => 'hard',
            'points' => 20,
            'options' => [],
        ]);
        $res->assertStatus(201);
        $this->assertEmpty($res->json('data.options'));
        $this->assertSame('hard', $res->json('data.difficulty'));
    }

    public function test_admin_can_read_question(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/questions', $this->mcPayload())->json('data.id');

        $this->getJson('/api/questions')->assertStatus(200);
        $this->getJson('/api/questions/'.$id)->assertStatus(200)->assertJsonPath('data.id', $id);
    }

    public function test_admin_can_update_question(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/questions', $this->mcPayload())->json('data.id');
        $codeBefore = QuestionBank::find($id)->code;

        $res = $this->putJson('/api/questions/'.$id, ['question_text' => 'Updated text?']);
        $res->assertStatus(200);
        $this->assertSame('Updated text?', $res->json('data.question_text'));
        $this->assertSame($codeBefore, $res->json('data.code'), 'update must never regenerate code');
    }

    public function test_admin_can_soft_delete_question(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/questions', $this->mcPayload())->json('data.id');

        $this->deleteJson('/api/questions/'.$id)->assertStatus(200);
        $this->getJson('/api/questions/'.$id)->assertStatus(404);

        $this->assertNotNull(QuestionBank::withTrashed()->find($id)->deleted_at, 'delete must be a soft delete');
    }

    // -----------------------------------------------------------------
    // B. Type invariants / validation
    // -----------------------------------------------------------------

    public function test_mc_requires_at_least_two_options(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload(['options' => [['option_text' => 'A', 'is_correct' => true]]]))->assertStatus(422);
    }

    public function test_mc_requires_exactly_one_correct_option(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload([
            'options' => [
                ['option_text' => 'A', 'is_correct' => false],
                ['option_text' => 'B', 'is_correct' => false],
            ],
        ]))->assertStatus(422);

        $this->postJson('/api/questions', $this->mcPayload([
            'options' => [
                ['option_text' => 'A', 'is_correct' => true],
                ['option_text' => 'B', 'is_correct' => true],
            ],
        ]))->assertStatus(422);
    }

    public function test_tf_requires_exactly_two_options(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload([
            'type' => 'true_false',
            'options' => [
                ['option_text' => 'Benar', 'is_correct' => true],
                ['option_text' => 'Salah', 'is_correct' => false],
                ['option_text' => 'Ragu', 'is_correct' => false],
            ],
        ]))->assertStatus(422);
    }

    public function test_tf_requires_exactly_one_correct_option(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload([
            'type' => 'true_false',
            'options' => [
                ['option_text' => 'Benar', 'is_correct' => false],
                ['option_text' => 'Salah', 'is_correct' => false],
            ],
        ]))->assertStatus(422);
    }

    public function test_essay_rejects_options(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', [
            'subject_id' => $this->subject->id,
            'question_text' => 'Uraian',
            'type' => 'essay',
            'difficulty' => 'medium',
            'points' => 10,
            'options' => [['option_text' => 'x', 'is_correct' => true]],
        ])->assertStatus(422);
    }

    public function test_points_must_be_positive(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload(['points' => 0]))->assertStatus(422);
        $this->postJson('/api/questions', $this->mcPayload(['points' => -3]))->assertStatus(422);
    }

    public function test_invalid_type_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload(['type' => 'matching']))->assertStatus(422);
    }

    public function test_invalid_difficulty_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload(['difficulty' => 'expert']))->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // C. Option integrity
    // -----------------------------------------------------------------

    public function test_duplicate_option_text_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload([
            'options' => [
                ['option_text' => 'A', 'is_correct' => true],
                ['option_text' => 'a', 'is_correct' => false],
            ],
        ]))->assertStatus(422);
    }

    public function test_empty_option_text_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/questions', $this->mcPayload([
            'options' => [
                ['option_text' => '   ', 'is_correct' => true],
                ['option_text' => 'B', 'is_correct' => false],
            ],
        ]))->assertStatus(422);
    }

    public function test_update_synchronises_options_without_orphans(): void
    {
        $this->actAsAdmin();
        $other = $this->postJson('/api/questions', $this->mcPayload())->json('data.id');
        $id = $this->postJson('/api/questions', $this->mcPayload())->json('data.id');
        $this->assertCount(2, QuestionBank::find($id)->options);

        $res = $this->putJson('/api/questions/'.$id, [
            'options' => [
                ['option_text' => 'X', 'is_correct' => true],
                ['option_text' => 'Y', 'is_correct' => false],
                ['option_text' => 'Z', 'is_correct' => false],
            ],
        ]);
        $res->assertStatus(200);
        $this->assertCount(3, $res->json('data.options'));

        $q = QuestionBank::find($id);
        $this->assertCount(3, $q->options, 'old options must be replaced, not accumulated');
        $this->assertCount(2, QuestionBank::find($other)->options, 'unrelated question options must be untouched');
    }

    // -----------------------------------------------------------------
    // E. Data integrity / atomicity
    // -----------------------------------------------------------------

    public function test_codes_are_unique_across_creates(): void
    {
        $this->actAsAdmin();
        $first = $this->postJson('/api/questions', $this->mcPayload())->json('data');
        $second = $this->postJson('/api/questions', $this->mcPayload())->json('data');

        $this->assertNotSame($first['code'], $second['code']);
        $dbCodes = QuestionBank::pluck('code')->all();
        $this->assertSame($dbCodes, array_unique($dbCodes));
    }

    public function test_client_supplied_code_is_ignored(): void
    {
        $this->actAsAdmin();
        $data = $this->postJson('/api/questions', $this->mcPayload(['code' => 'Q-999999']))->json('data');
        $this->assertNotSame('Q-999999', $data['code']);
        $this->assertSame('Q-'.str_pad((string) $data['id'], 6, '0', STR_PAD_LEFT), $data['code']);
    }

    public function test_failed_update_leaves_existing_rows_unchanged(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/questions', $this->mcPayload())->json('data.id');
        $before = QuestionBank::with('options')->find($id);

        // Duplicate option text -> 422, nothing must be modified.
        $this->putJson('/api/questions/'.$id, [
            'question_text' => 'Should not persist',
            'options' => [
                ['option_text' => 'Sama', 'is_correct' => true],
                ['option_text' => 'sama', 'is_correct' => false],
            ],
        ])->assertStatus(422);

        $after = QuestionBank::with('options')->find($id);
        $this->assertSame($before->question_text, $after->question_text);
        $this->assertSame($before->options->count(), $after->options->count());
        $this->assertSame($before->options()->pluck('option_text')->sort()->values()->all(), $after->options()->pluck('option_text')->sort()->values()->all());
    }

    public function test_failed_create_leaves_no_rows(): void
    {
        $this->actAsAdmin();
        $beforeQ = QuestionBank::count();
        $beforeO = \App\Models\Examination\QuestionOption::count();

        $this->postJson('/api/questions', $this->mcPayload([
            'options' => [
                ['option_text' => 'A', 'is_correct' => false],
                ['option_text' => 'B', 'is_correct' => false],
            ],
        ]))->assertStatus(422);

        $this->assertSame($beforeQ, QuestionBank::count(), 'failed create must not persist a question');
        $this->assertSame($beforeO, \App\Models\Examination\QuestionOption::count(), 'failed create must not persist options');
    }

    // -----------------------------------------------------------------
    // D. Security boundary
    // -----------------------------------------------------------------

    public function test_unauthenticated_rejected(): void
    {
        $this->getJson('/api/questions')->assertStatus(401);
        $this->postJson('/api/questions', [])->assertStatus(401);
    }

    public function test_student_rejected_from_question_bank(): void
    {
        Sanctum::actingAs($this->siswa);
        $this->getJson('/api/questions')->assertStatus(403);
        $this->getJson('/api/questions/1')->assertStatus(403);
        $this->postJson('/api/questions', $this->mcPayload())->assertStatus(403);
    }

    public function test_teacher_without_manage_exams_rejected_from_question_bank(): void
    {
        Sanctum::actingAs($this->guru);
        $this->getJson('/api/questions')->assertStatus(403);
        $this->postJson('/api/questions', $this->mcPayload())->assertStatus(403);
    }

    public function test_admin_question_response_contains_answer_key_for_management(): void
    {
        $this->actAsAdmin();
        $data = $this->postJson('/api/questions', $this->mcPayload())->json('data');

        // Authorized management resource: answer key is allowed and required here.
        $this->assertArrayHasKey('explanation', $data);
        foreach ($data['options'] as $option) {
            $this->assertArrayHasKey('is_correct', $option);
        }
    }
}