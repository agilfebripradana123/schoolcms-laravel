<?php

namespace Tests\Feature\Finance;

use App\Models\Academic\AcademicYear;
use App\Models\Finance\Billing;
use App\Models\Finance\FeeType;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentTransaction;
use App\Models\Finance\Scholarship;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use App\Services\Finance\BillingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 9 — Student Finance API (read-only Student Portal).
 *
 * All /api/student/finance endpoints are identity-scoped: the authenticated
 * Siswa's linked Student (students.user_id) forces every query. Client-supplied
 * `student_id` is rejected (prohibited). Cross-student resources always 404.
 *
 * Created rows use PH9-/PH9U-/VTX-/VT- markers and are removed in setUp /
 * tearDown so seeded Phase 1 data stays untouched.
 */
class StudentFinanceApiTest extends TestCase
{
    private int $adminUserId;

    private int $academicYearId;

    private int $semesterId;

    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestData();

        $this->adminUserId = User::where('role_id', Role::where('name', 'Admin')->value('id'))->firstOrFail()->id;
        $this->academicYearId = AcademicYear::orderByDesc('id')->value('id');
        $this->semesterId = DB::connection()->table('semesters')->orderBy('id')->value('id');
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    // ─── Auth / data helpers ───────────────────────────────────

    private function createUser(string $roleName, array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'PH9U-'.$roleName.'-'.mt_rand(100000, 999999),
            'name' => 'PH9-'.$roleName,
            'email' => 'ph9.'.$roleName.'.'.mt_rand(100000, 999999).'@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => (int) Role::where('name', $roleName)->value('id'),
        ], $overrides));
    }

    private function createStudent(array $overrides = []): Student
    {
        return Student::create(array_merge([
            'nisn' => 'PH9-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'PH9-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'PH9-Test Student',
            'gender' => 'L',
            'birth_place' => 'Test City',
            'birth_date' => '2008-01-01',
            'address' => 'Test Address',
        ], $overrides));
    }

    private function authenticateStudent(Student $student): User
    {
        $user = $this->createUser('Siswa', ['username' => 'PH9U-S-'.uniqid()]);
        $student->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createFeeType(): FeeType
    {
        return FeeType::create([
            'name' => 'VT-Ph9-'.uniqid(),
            'amount' => 350000,
            'description' => 'PH9-Test fee type.',
            'is_active' => true,
        ]);
    }

    private function createBilling(Student $student, array $overrides = []): Billing
    {
        return Billing::create(array_merge([
            'student_id' => $student->id,
            'fee_type_id' => $this->createFeeType()->id,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'amount' => 350000,
            'status' => 'unpaid',
            'notes' => 'PH9-Billing',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ], $overrides));
    }

    private function createPayment(Student $student, array $overrides = []): Payment
    {
        $billing = $overrides['billing'] ?? $this->createBilling($student);
        unset($overrides['billing']);

        return Payment::create(array_merge([
            'billing_id' => $billing->id,
            'student_id' => $student->id,
            'payment_date' => '2026-09-05',
            'amount' => 100000,
            'method' => 'cash',
            'received_by' => $this->adminUserId,
            'notes' => 'PH9-Payment',
        ], $overrides));
    }

    private function createTransaction(Payment $payment, array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'payment_id' => $payment->id,
            'transaction_code' => 'VTX-'.uniqid(),
            'type' => 'payment',
            'amount' => 100000,
            'method' => 'cash',
            'status' => 'success',
            'transaction_date' => '2026-09-05 09:00:00',
        ], $overrides));
    }

    private function createScholarship(Student $student, array $overrides = []): Scholarship
    {
        return Scholarship::create(array_merge([
            'student_id' => $student->id,
            'name' => 'PH9-Scholarship-'.uniqid(),
            'provider' => 'PH9-Provider',
            'amount' => 500000,
            'status' => 'aktif',
        ], $overrides));
    }

    private function cleanupTestData(): void
    {
        $db = DB::connection();

        $db->table('payment_transactions')->where('transaction_code', 'like', 'VTX-%')->delete();
        $db->table('payments')->where('notes', 'like', 'PH9-%')->delete();
        $db->table('billings')->where('notes', 'like', 'PH9-%')->delete();
        $db->table('scholarships')->where('name', 'like', 'PH9-%')->delete();
        $db->table('fee_types')->where('name', 'like', 'VT-%')->delete();
        $db->table('users')->where('username', 'like', 'PH9U-%')->delete();

        Student::where('nisn', 'like', 'PH9-%')
            ->orWhere('nis', 'like', 'PH9-%')
            ->forceDelete();
    }

    private function assertPortal404(TestResponse $response): void
    {
        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // ─── Authentication ────────────────────────────────────────

    public function test_guest_gets_401_on_all_portal_endpoints(): void
    {
        foreach ([
            '/api/student/finance/summary',
            '/api/student/finance/billings',
            '/api/student/finance/payments',
            '/api/student/finance/transactions',
            '/api/student/finance/scholarships',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertStatus(401);
        }
    }

    // ─── Authorization ─────────────────────────────────────────

    public function test_non_siswa_roles_get_403(): void
    {
        foreach (['Guru', 'Admin', 'Administrator'] as $role) {
            Sanctum::actingAs($this->createUser($role));

            $response = $this->getJson('/api/student/finance/billings');

            $response->assertStatus(403);
            $response->assertJsonPath('success', false);
        }
    }

    public function test_siswa_without_linked_student_gets_403(): void
    {
        Sanctum::actingAs($this->createUser('Siswa'));

        $response = $this->getJson('/api/student/finance/summary');

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Student profile is not linked to this account.');
    }

    public function test_linked_siswa_gets_200(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $this->createBilling($student);

        $this->getJson('/api/student/finance/billings')->assertStatus(200);
    }

    // ─── Billings ──────────────────────────────────────────────

    public function test_own_billing_list_with_derived_values(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $billing = $this->createBilling($student);
        $payment = $this->createPayment($student, ['billing' => $billing]);
        $this->createTransaction($payment, ['amount' => 150000]);

        $response = $this->getJson('/api/student/finance/billings');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 15);
        $response->assertJsonStructure(['success', 'message', 'data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $item = collect($response->json('data'))->firstWhere('id', $billing->id);
        $this->assertSame('350000.00', $item['amount']);
        $this->assertSame('150000.00', $item['paid']);
        $this->assertSame('200000.00', $item['outstanding']);
        $this->assertArrayHasKey('fee_type', $item);
        $this->assertArrayHasKey('academic_year', $item);
        $this->assertArrayHasKey('semester', $item);
    }

    public function test_billing_detail_includes_relations_and_derived(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $billing = $this->createBilling($student);
        $payment = $this->createPayment($student, ['billing' => $billing]);
        $this->createTransaction($payment, ['amount' => 350000]);
        app(BillingService::class)->reconcile($billing);

        $response = $this->getJson("/api/student/finance/billings/{$billing->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $billing->id);
        $response->assertJsonPath('data.paid', '350000.00');
        $response->assertJsonPath('data.outstanding', '0.00');
        $response->assertJsonPath('data.status', 'paid');
        $this->assertArrayHasKey('fee_type', $response->json('data'));
    }

    public function test_another_students_billing_returns_404(): void
    {
        $owner = $this->createStudent();
        $this->authenticateStudent($owner);
        $otherBilling = $this->createBilling($this->createStudent());

        $this->assertPortal404($this->getJson("/api/student/finance/billings/{$otherBilling->id}"));
    }

    public function test_student_id_cannot_override_billing_scope(): void
    {
        $owner = $this->createStudent();
        $other = $this->createStudent();
        $this->authenticateStudent($owner);
        $otherBilling = $this->createBilling($other);

        $this->getJson("/api/student/finance/billings?student_id={$other->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
        $this->getJson("/api/student/finance/billings/{$otherBilling->id}?student_id={$other->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
        $this->assertPortal404($this->getJson("/api/student/finance/billings/{$otherBilling->id}"));
    }

    public function test_billing_filters_and_pagination_meta(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $this->createBilling($student, ['amount' => 100000]);
        $this->createBilling($student, ['amount' => 200000, 'semester_id' => null]);

        $latest = $this->createBilling($student, ['amount' => 300000]);

        $response = $this->getJson("/api/student/finance/billings?semester_id={$this->semesterId}");
        $response->assertStatus(200);
        $amounts = collect($response->json('data'))->pluck('amount')->all();
        $this->assertContains('100000.00', $amounts);
        $this->assertContains('300000.00', $amounts);
        $this->assertNotContains('200000.00', $amounts);

        $this->getJson('/api/student/finance/billings?semester_id=999999')
            ->assertStatus(422)->assertJsonValidationErrors(['semester_id']);

        $this->getJson('/api/student/finance/billings?status=paid')->assertStatus(200);
        $this->getJson('/api/student/finance/billings?status=weird')
            ->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->getJson("/api/student/finance/billings?fee_type_id={$latest->fee_type_id}")->assertStatus(200);
        $this->getJson("/api/student/finance/billings?academic_year_id={$this->academicYearId}")->assertStatus(200);
        $this->getJson('/api/student/finance/billings?per_page=1')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_pending_and_failed_transactions_excluded_from_derived_values(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $billing = $this->createBilling($student);
        $payment = $this->createPayment($student, ['billing' => $billing]);
        $this->createTransaction($payment, ['amount' => 100000, 'status' => 'pending']);
        $this->createTransaction($payment, ['amount' => 50000, 'status' => 'failed']);

        $this->getJson("/api/student/finance/billings/{$billing->id}")
            ->assertJsonPath('data.paid', '0.00')
            ->assertJsonPath('data.outstanding', '350000.00');
    }

    public function test_refund_reduces_net_paid(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $billing = $this->createBilling($student);
        $payment = $this->createPayment($student, ['billing' => $billing]);
        $this->createTransaction($payment, ['amount' => 300000]);
        $this->createTransaction($payment, ['type' => 'refund', 'amount' => -50000]);

        $this->getJson("/api/student/finance/billings/{$billing->id}")
            ->assertJsonPath('data.paid', '250000.00')
            ->assertJsonPath('data.outstanding', '100000.00');
    }

    public function test_soft_deleted_payment_and_transaction_excluded(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $billing = $this->createBilling($student);
        $payment = $this->createPayment($student, ['billing' => $billing]);
        $transaction = $this->createTransaction($payment, ['amount' => 350000]);

        $this->getJson("/api/student/finance/billings/{$billing->id}")->assertJsonPath('data.paid', '350000.00');

        // Soft-deleted transaction no longer counts.
        $transaction->delete();
        $this->getJson("/api/student/finance/billings/{$billing->id}")
            ->assertJsonPath('data.paid', '0.00')
            ->assertJsonPath('data.status', 'unpaid');

        // Soft-deleted payment no longer counts either.
        $secondPayment = $this->createPayment($student, ['billing' => $billing]);
        $this->createTransaction($secondPayment, ['amount' => 200000]);
        $this->getJson("/api/student/finance/billings/{$billing->id}")->assertJsonPath('data.paid', '200000.00');

        $secondPayment->delete();
        $this->getJson("/api/student/finance/billings/{$billing->id}")->assertJsonPath('data.paid', '0.00');
    }

    public function test_cancelled_billing_visible_only_when_requested(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $cancelled = $this->createBilling($student, ['status' => 'cancelled', 'amount' => 99999]);

        $default = $this->getJson('/api/student/finance/billings');
        $default->assertStatus(200);
        $this->assertEmpty(collect($default->json('data'))->where('id', $cancelled->id));

        $filtered = $this->getJson('/api/student/finance/billings?status=cancelled');
        $filtered->assertStatus(200);
        $this->assertSame(99999, (int) collect($filtered->json('data'))->firstWhere('id', $cancelled->id)['amount']);
    }

    // ─── Payments ──────────────────────────────────────────────

    public function test_own_payment_list_and_detail(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $payment = $this->createPayment($student, ['amount' => 120000]);

        $list = $this->getJson('/api/student/finance/payments');
        $list->assertStatus(200);
        $list->assertJsonStructure(['success', 'message', 'data', 'meta']);
        $item = collect($list->json('data'))->firstWhere('id', $payment->id);
        $this->assertSame('120000.00', $item['amount']);
        $this->assertArrayHasKey('billing', $item);
        $this->assertArrayHasKey('fee_type', $item['billing']);
        $this->assertArrayNotHasKey('student', $item);

        $detail = $this->getJson("/api/student/finance/payments/{$payment->id}");
        $detail->assertStatus(200);
        $detail->assertJsonPath('data.id', $payment->id);
    }

    public function test_another_students_payment_returns_404(): void
    {
        $owner = $this->createStudent();
        $this->authenticateStudent($owner);
        $other = $this->createStudent();
        $otherPayment = $this->createPayment($other);

        $this->assertPortal404($this->getJson("/api/student/finance/payments/{$otherPayment->id}"));
        $this->getJson("/api/student/finance/payments?student_id={$other->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
    }

    public function test_payment_method_filter(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $this->createPayment($student, ['method' => 'transfer']);

        $response = $this->getJson('/api/student/finance/payments?method=transfer');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));

        $this->getJson('/api/student/finance/payments?method=cheque')
            ->assertStatus(422)->assertJsonValidationErrors(['method']);
    }

    // ─── Transactions ──────────────────────────────────────────

    public function test_own_transaction_list_and_detail(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $payment = $this->createPayment($student);
        $transaction = $this->createTransaction($payment, ['amount' => 150000]);

        $list = $this->getJson('/api/student/finance/transactions');
        $list->assertStatus(200);
        $item = collect($list->json('data'))->firstWhere('id', $transaction->id);
        $this->assertSame('150000.00', $item['amount']);
        $this->assertSame('payment', $item['type']);
        $this->assertArrayHasKey('payment', $item);
        $this->assertArrayHasKey('billing', $item['payment']);
        $this->assertArrayHasKey('fee_type', $item['payment']['billing']);

        $detail = $this->getJson("/api/student/finance/transactions/{$transaction->id}");
        $detail->assertStatus(200);
        $detail->assertJsonPath('data.id', $transaction->id);
    }

    public function test_another_students_transaction_returns_404(): void
    {
        $owner = $this->createStudent();
        $this->authenticateStudent($owner);
        $other = $this->createStudent();
        $otherPayment = $this->createPayment($other);
        $otherTransaction = $this->createTransaction($otherPayment);

        $this->assertPortal404($this->getJson("/api/student/finance/transactions/{$otherTransaction->id}"));
        $this->getJson("/api/student/finance/transactions?student_id={$other->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
    }

    public function test_transaction_type_and_status_filters(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $payment = $this->createPayment($student);
        $this->createTransaction($payment, ['type' => 'refund', 'amount' => -50000, 'status' => 'pending']);

        $this->getJson('/api/student/finance/transactions?type=refund')->assertStatus(200);
        $this->getJson('/api/student/finance/transactions?status=pending')->assertStatus(200);
        $this->getJson('/api/student/finance/transactions?status=pending')->assertJsonPath('data.0.type', 'refund');
        $this->getJson('/api/student/finance/transactions?type=other')
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->getJson('/api/student/finance/transactions?status=weird')
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_transactions_scoped_through_payment(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $payment = $this->createPayment($student);
        $transaction = $this->createTransaction($payment, ['amount' => 100000]);

        $this->getJson("/api/student/finance/transactions/{$transaction->id}")->assertStatus(200);
    }

    // ─── Scholarships ──────────────────────────────────────────

    public function test_own_scholarship_list_and_detail(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $active = $this->createScholarship($student);
        $this->createScholarship($student, ['status' => 'selesai']);

        $list = $this->getJson('/api/student/finance/scholarships');
        $list->assertStatus(200);
        $list->assertJsonPath('meta.per_page', 15);
        $this->assertArrayNotHasKey('student', collect($list->json('data'))->first());
        $this->assertSame($active->id, $list->json('data.0.id'));

        $detail = $this->getJson("/api/student/finance/scholarships/{$active->id}");
        $detail->assertStatus(200);
        $detail->assertJsonPath('data.id', $active->id);
    }

    public function test_scholarship_status_filter_and_404(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);
        $finished = $this->createScholarship($student, ['status' => 'selesai']);

        $this->getJson('/api/student/finance/scholarships?status=selesai')->assertJsonPath('data.0.id', $finished->id);
        $this->getJson('/api/student/finance/scholarships?status=lunas')
            ->assertStatus(422)->assertJsonValidationErrors(['status']);

        $other = $this->createStudent();
        $otherScholarship = $this->createScholarship($other);
        $this->assertPortal404($this->getJson("/api/student/finance/scholarships/{$otherScholarship->id}"));
        $this->getJson("/api/student/finance/scholarships?student_id={$other->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
    }

    // ─── Summary ───────────────────────────────────────────────

    private function tomorrowString(int $days = 1): string
    {
        return Carbon::today()->addDays($days)->toDateString();
    }

    public function test_summary_shape_and_totals(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);

        $billing = $this->createBilling($student, ['amount' => 400000, 'due_date' => $this->tomorrowString(10)]);
        $payment = $this->createPayment($student, ['billing' => $billing]);
        $this->createTransaction($payment, ['amount' => 250000]);
        $this->createTransaction($payment, ['type' => 'refund', 'amount' => -50000]);
        $this->createTransaction($payment, ['status' => 'pending', 'amount' => 100000]);
        $this->createBilling($student, ['amount' => 200000, 'status' => 'cancelled']);
        $this->createScholarship($student);

        $response = $this->getJson('/api/student/finance/summary');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'totals' => ['total_billed', 'total_paid', 'total_outstanding'],
                'upcoming_billing',
                'overdue_billings',
                'recent_payments',
                'active_scholarships',
            ],
        ]);
        $response->assertJsonPath('data.totals.total_billed', 400000);
        $response->assertJsonPath('data.totals.total_paid', 200000);
        $response->assertJsonPath('data.totals.total_outstanding', 200000);
        $response->assertJsonPath('data.upcoming_billing.id', $billing->id);
        $response->assertJsonPath('data.active_scholarships.0.name', function ($name) {
            return is_string($name) && str_starts_with($name, 'PH9-Scholarship-');
        });
    }

    public function test_summary_upcoming_selects_nearest_billing_and_null_when_none(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);

        $near = $this->createBilling($student, ['due_date' => $this->tomorrowString(3)]);
        $far = $this->createBilling($student, ['due_date' => $this->tomorrowString(10)]);

        $this->getJson('/api/student/finance/summary')
            ->assertJsonPath('data.upcoming_billing.id', $near->id);

        // Fully paid billing must not become the upcoming billing.
        $payment = $this->createPayment($student, ['billing' => $near]);
        $this->createTransaction($payment, ['amount' => 350000]);

        $this->getJson('/api/student/finance/summary')
            ->assertJsonPath('data.upcoming_billing.id', $far->id);

        $empty = $this->createStudent();
        $this->authenticateStudent($empty);
        $this->getJson('/api/student/finance/summary')
            ->assertJsonPath('data.upcoming_billing', null)
            ->assertJsonPath('data.totals.total_billed', 0)
            ->assertJsonPath('data.totals.total_paid', 0);
    }

    public function test_summary_overdue_and_recent_payments_and_scholarships(): void
    {
        $student = $this->createStudent();
        $this->authenticateStudent($student);

        $overdue = $this->createBilling($student, ['due_date' => Carbon::today()->subDays(5)->toDateString()]);
        $paymentOld = $this->createPayment($student, [
            'billing' => $overdue,
            'payment_date' => Carbon::today()->subDays(10)->toDateString(),
            'amount' => 50000,
        ]);
        $this->createTransaction($paymentOld, ['amount' => 50000]);

        $billing = $this->createBilling($student);
        $paymentNew = $this->createPayment($student, ['billing' => $billing, 'payment_date' => Carbon::today()->toDateString(), 'amount' => 100000]);
        $this->createTransaction($paymentNew, ['amount' => 100000]);

        $this->createScholarship($student, ['name' => 'PH9-Scholarship-Active', 'provider' => 'PH9-Provider-A', 'amount' => 300000]);

        $response = $this->getJson('/api/student/finance/summary');

        $response->assertStatus(200);
        // Overdue: outstanding = 350000 - 50000 = 300000.
        $overdueItem = collect($response->json('data.overdue_billings'))->firstWhere('id', $overdue->id);
        $this->assertSame(350000, $overdueItem['amount']);
        $this->assertSame(300000, $overdueItem['outstanding']);

        // Recent payments ordered payment_date DESC — newest first.
        $recent = $response->json('data.recent_payments');
        $this->assertCount(2, $recent);
        $this->assertSame($paymentNew->id, $recent[0]['id']);
        $this->assertSame($paymentOld->id, $recent[1]['id']);

        // Active scholarship with provider + amount.
        $this->assertSame('PH9-Provider-A', $response->json('data.active_scholarships.0.provider'));
    }

    public function test_summary_never_exposes_another_student(): void
    {
        $owner = $this->createStudent();
        $this->authenticateStudent($owner);
        $this->createBilling($owner, ['amount' => 111000]);

        $other = $this->createStudent();
        $this->createBilling($other, ['amount' => 999000]);
        $this->createScholarship($other, ['name' => 'PH9-Scholarship-Other', 'amount' => 1]);

        $response = $this->getJson('/api/student/finance/summary');

        $response->assertJsonPath('data.totals.total_billed', 111000);
        $this->assertEmpty($response->json('data.active_scholarships'));

        $this->getJson('/api/student/finance/summary?student_id='.$other->id)
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
    }
}
