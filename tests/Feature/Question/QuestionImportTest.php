<?php

namespace Tests\Feature\Question;

use App\Models\Academic\Subject;
use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Question Bank XLSX import (EXAM-IMPORT-01 Stage B).
 *
 * Hermetic suite: builds its own schema on the default sqlite :memory:
 * connection in setUp — never touches the live MySQL database. The schema
 * mirrors the production Question Bank tables plus the audit_logs table the
 * shared AppServiceProvider audit hook requires.
 */
class QuestionImportTest extends TestCase
{
    private User $admin;

    private User $guru;

    private User $siswa;

    private Subject $subject;

    private const HEADERS = [
        'question_text',
        'question_type',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_answer',
        'points',
        'explanation',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSchema();
        $this->seedFixture();
    }

    // -----------------------------------------------------------------
    // Hermetic environment
    // -----------------------------------------------------------------

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
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('action', 50);
            $t->string('model', 100)->nullable();
            $t->unsignedInteger('model_id')->nullable();
            $t->text('description');
            $t->string('ip_address', 45);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('title');
            $t->string('status')->default('draft');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('exam_questions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('question_id');
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin Import', 'email' => 'admin.import@test.local', 'username' => 'admin_import', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $this->guru = User::create(['name' => 'Guru Import', 'email' => 'guru.import@test.local', 'username' => 'guru_import', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $this->siswa = User::create(['name' => 'Siswa Import', 'email' => 'siswa.import@test.local', 'username' => 'siswa_import', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->subject = Subject::create(['code' => 'FIS', 'name' => 'Fisika']);
    }

    // -----------------------------------------------------------------
    // Excel file helpers
    // -----------------------------------------------------------------

    private function columnLetter(int $column): string
    {
        $letter = '';
        while ($column > 0) {
            $column--;
            $letter = chr(65 + ($column % 26)) . $letter;
            $column = intdiv($column, 26);
        }

        return $letter;
    }

    private function mcRow(array $overrides = []): array
    {
        return array_merge([
            'question_text' => 'Which planet is known as the Red Planet?',
            'question_type' => 'multiple_choice',
            'option_a' => 'Venus',
            'option_b' => 'Mars',
            'option_c' => 'Jupiter',
            'option_d' => 'Saturn',
            'correct_answer' => 'B',
            'points' => 10,
            'explanation' => 'Mars appears red because of iron oxide on its surface.',
        ], $overrides);
    }

    private function essayRow(array $overrides = []): array
    {
        return array_merge([
            'question_text' => 'Describe the water cycle.',
            'question_type' => 'essay',
            'option_a' => '',
            'option_b' => '',
            'option_c' => '',
            'option_d' => '',
            'correct_answer' => '',
            'points' => 20,
            'explanation' => '',
        ], $overrides);
    }

    private function orderedRow(array $row): array
    {
        return array_values(array_intersect_key($row, array_flip(self::HEADERS)));
    }

    private function createExcelFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $allRows = array_merge([self::HEADERS], array_map(fn (array $row) => $this->orderedRow($row), $rows));

        foreach ($allRows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $coord = $this->columnLetter($colIndex + 1) . ($rowIndex + 1);
                $sheet->setCellValue($coord, $value);
            }
        }

        $tempPath = sys_get_temp_dir() . '/q_import_test_' . mt_rand(100000, 999999) . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile($tempPath, 'questions.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // -----------------------------------------------------------------
    // Auth helpers
    // -----------------------------------------------------------------

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    private function previewUrl(): string
    {
        return '/api/questions/import/preview';
    }

    private function importUrl(): string
    {
        return '/api/questions/import';
    }

    // -----------------------------------------------------------------
    // 1. Unauthorized users (permission gate)
    // -----------------------------------------------------------------

    public function test_unauthorized_user_cannot_preview_import(): void
    {
        Sanctum::actingAs($this->guru);
        $file = $this->createExcelFile([$this->mcRow()]);

        $this->postJson($this->previewUrl(), ['subject_id' => $this->subject->id, 'file' => $file])
            ->assertStatus(403);

        Sanctum::actingAs($this->siswa);
        $this->postJson($this->previewUrl(), ['subject_id' => $this->subject->id, 'file' => $file])
            ->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_execute_import(): void
    {
        Sanctum::actingAs($this->guru);
        $file = $this->createExcelFile([$this->mcRow()]);

        $this->postJson($this->importUrl(), ['subject_id' => $this->subject->id, 'file' => $file])
            ->assertStatus(403);

        Sanctum::actingAs($this->siswa);
        $this->postJson($this->importUrl(), ['subject_id' => $this->subject->id, 'file' => $file])
            ->assertStatus(403);
    }

    public function test_import_routes_require_manage_exams_permission(): void
    {
        $router = $this->app['router'];

        foreach ([
            'api/questions/import/template',
            'api/questions/import/preview',
            'api/questions/import',
        ] as $uri) {
            $route = collect($router->getRoutes()->getRoutes())
                ->first(fn ($r) => rtrim($r->uri(), '/') === $uri);

            $this->assertNotNull($route, 'Route not registered: '.$uri);
            $this->assertContains('permission:manage-exams', $route->gatherMiddleware(), $uri.' must be gated by manage-exams');
        }
    }

    // -----------------------------------------------------------------
    // 2. Happy path imports
    // -----------------------------------------------------------------

    public function test_valid_multiple_choice_import_succeeds(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow()]),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.imported_count', 1);
        $response->assertJsonStructure(['success', 'message', 'data' => ['imported_count', 'question_ids']]);

        $this->assertEquals(1, QuestionBank::count());
    }

    public function test_valid_essay_import_succeeds(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->essayRow()]),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.imported_count', 1);

        $question = QuestionBank::first();
        $this->assertNotNull($question);
        $this->assertSame('essay', $question->type);
        $this->assertSame($this->subject->id, $question->subject_id);
        $this->assertEquals(0, $question->options()->count());
    }

    public function test_imported_questions_are_draft(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(), $this->essayRow()]),
        ])->assertStatus(200);

        QuestionBank::all()->each(fn (QuestionBank $q) => $this->assertSame('draft', $q->status));
        $this->assertSame(0, QuestionBank::where('status', 'approved')->count());
    }

    public function test_correct_options_are_created_correctly(): void
    {
        $this->actAsAdmin();

        $row = $this->mcRow([
            'option_a' => 'Venus',
            'option_b' => 'Mars',
            'option_c' => 'Jupiter',
            'option_d' => 'Saturn',
            'correct_answer' => 'C',
        ]);

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$row]),
        ])->assertStatus(200);

        $question = QuestionBank::first();
        $options = $question->options()->orderBy('id')->get();

        $this->assertCount(4, $options);
        $this->assertSame('Jupiter', $options[2]->option_text);
        $this->assertTrue($options[2]->is_correct);
        $this->assertFalse($options[0]->is_correct);
        $this->assertFalse($options[1]->is_correct);
        $this->assertFalse($options[3]->is_correct);
        $this->assertSame(1, $options->filter(fn ($o) => $o->is_correct)->count());
    }

    public function test_imported_question_gets_deterministic_code(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow()]),
        ]);

        $id = $response->assertStatus(200)->json('data.question_ids')[0];
        $expected = 'Q-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->assertSame($expected, QuestionBank::find($id)->code);
    }

    // -----------------------------------------------------------------
    // 3. Row validation
    // -----------------------------------------------------------------

    public function test_invalid_question_type_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(['question_type' => 'true_false'])]),
        ])->assertStatus(422);

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(['question_type' => 'matching'])]),
        ])->assertStatus(422);

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_missing_question_text_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(['question_text' => ''])]),
        ])->assertStatus(422);

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_missing_points_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(['points' => ''])]),
        ])->assertStatus(422);

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_multiple_choice_without_sufficient_options_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(['option_a' => 'Only one', 'option_b' => ''])]),
        ])->assertStatus(422);

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_invalid_correct_answer_rejected(): void
    {
        $this->actAsAdmin();

        foreach (['E', 'AB', '1'] as $answer) {
            $this->postJson($this->importUrl(), [
                'subject_id' => $this->subject->id,
                'file' => $this->createExcelFile([$this->mcRow(['correct_answer' => $answer])]),
            ])->assertStatus(422);
        }

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_correct_answer_referencing_missing_option_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(['option_d' => '', 'correct_answer' => 'D'])]),
        ])->assertStatus(422);

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_essay_does_not_require_correct_answer(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->essayRow()]),
        ]);

        $response->assertStatus(200);
        $this->assertSame('essay', QuestionBank::first()->type);
    }

    public function test_essay_with_options_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->essayRow(['option_a' => 'Hmm'])]),
        ])->assertStatus(422);

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_unknown_header_rejected(): void
    {
        $this->actAsAdmin();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['question_text', 'question_type', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'points', 'mystery_column'];
        foreach ($headers as $colIndex => $value) {
            $sheet->setCellValue($this->columnLetter($colIndex + 1).'1', $value);
        }

        $tempPath = sys_get_temp_dir() . '/q_import_test_' . mt_rand(100000, 999999) . '.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);
        $file = new UploadedFile($tempPath, 'questions.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->postJson($this->importUrl(), ['subject_id' => $this->subject->id, 'file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid Excel header.');

        $this->assertEquals(0, QuestionBank::count());
    }

    // -----------------------------------------------------------------
    // 4. Atomicity / rollback
    // -----------------------------------------------------------------

    public function test_invalid_row_causes_whole_import_rollback(): void
    {
        $this->actAsAdmin();

        $beforeQ = QuestionBank::count();
        $beforeO = QuestionOption::count();

        $file = $this->createExcelFile([
            $this->mcRow(),
            $this->essayRow(['question_text' => '']),
        ]);

        $response = $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('data.imported_count', 0);

        $this->assertSame($beforeQ, QuestionBank::count(), 'no partial import: valid row must not be persisted');
        $this->assertSame($beforeO, QuestionOption::count(), 'no partial import: options must not be persisted');
        $this->assertNotEmpty($response->json('data.errors'));
    }

    // -----------------------------------------------------------------
    // 5. Preview
    // -----------------------------------------------------------------

    public function test_preview_does_not_create_database_records(): void
    {
        $this->actAsAdmin();

        $beforeQ = QuestionBank::count();
        $beforeO = QuestionOption::count();

        $response = $this->postJson($this->previewUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(), $this->essayRow()]),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_rows', 2);
        $response->assertJsonPath('data.valid_rows', 2);
        $response->assertJsonPath('data.invalid_rows', 0);

        $preview = $response->json('data.preview');
        $this->assertCount(2, $preview);
        $this->assertArrayHasKey('excel_row', $preview[0]);
        $this->assertArrayHasKey('question_text', $preview[0]);
        $this->assertArrayHasKey('question_type', $preview[0]);
        $this->assertArrayHasKey('points', $preview[0]);
        $this->assertArrayHasKey('option_count', $preview[0]);
        $this->assertSame(4, $preview[0]['option_count']);

        $this->assertSame($beforeQ, QuestionBank::count(), 'preview must not insert questions');
        $this->assertSame($beforeO, QuestionOption::count(), 'preview must not insert options');
    }

    public function test_preview_reports_row_level_errors(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson($this->previewUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(), $this->essayRow(['points' => ''])]),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.total_rows', 2);
        $response->assertJsonPath('data.valid_rows', 1);
        $response->assertJsonPath('data.invalid_rows', 1);

        $errors = $response->json('data.errors');
        $this->assertNotEmpty($errors);
        $this->assertSame(3, $errors[0]['row']);
        $this->assertSame('points', $errors[0]['field']);
    }

    // -----------------------------------------------------------------
    // 6. Request-level validation
    // -----------------------------------------------------------------

    public function test_invalid_subject_id_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), [
            'subject_id' => 999999,
            'file' => $this->createExcelFile([$this->mcRow()]),
        ])->assertStatus(422);

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_non_xlsx_file_rejected(): void
    {
        $this->actAsAdmin();

        $tempPath = tempnam(sys_get_temp_dir(), 'q_import_nonxlsx_');
        file_put_contents($tempPath, 'this is definitely not an excel spreadsheet');

        $file = new UploadedFile($tempPath, 'questions.csv', 'text/csv', null, true);

        $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $file,
        ])->assertStatus(422);

        $this->assertEquals(0, QuestionBank::count());
    }

    public function test_missing_file_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson($this->importUrl(), ['subject_id' => $this->subject->id])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // 7. Duplicates are NOT deduplicated
    // -----------------------------------------------------------------

    public function test_duplicate_question_text_creates_separate_records(): void
    {
        $this->actAsAdmin();

        $text = 'Which planet is known as the Red Planet?';

        $response = $this->postJson($this->importUrl(), [
            'subject_id' => $this->subject->id,
            'file' => $this->createExcelFile([$this->mcRow(), $this->mcRow()]),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.imported_count', 2);

        $questions = QuestionBank::where('question_text', $text)->get();
        $this->assertCount(2, $questions);
        $this->assertNotSame($questions[0]->id, $questions[1]->id);
    }

    // -----------------------------------------------------------------
    // 8. Template endpoint
    // -----------------------------------------------------------------

    public function test_import_template_download(): void
    {
        $this->actAsAdmin();

        $response = $this->getJson('/api/questions/import/template');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_unauthenticated_cannot_download_template(): void
    {
        $this->getJson('/api/questions/import/template')->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // 9. Existing Question Bank CRUD stays intact
    // -----------------------------------------------------------------

    public function test_existing_question_bank_crud_remains_working(): void
    {
        $this->actAsAdmin();

        $create = $this->postJson('/api/questions', [
            'subject_id' => $this->subject->id,
            'question_text' => 'CRUD untouched?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 10,
            'options' => [
                ['option_text' => 'Ya', 'is_correct' => true],
                ['option_text' => 'Tidak', 'is_correct' => false],
            ],
        ]);

        $create->assertStatus(201);

        $id = $create->json('data.id');
        $this->getJson('/api/questions')->assertStatus(200);
        $this->getJson('/api/questions/'.$id)->assertStatus(200);
        $this->putJson('/api/questions/'.$id, ['question_text' => 'CRUD updated?'])->assertStatus(200);
        $this->deleteJson('/api/questions/'.$id)->assertStatus(200);
    }
}