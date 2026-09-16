<?php

namespace Tests\Feature\Finance;

use App\Models\Academic\AcademicYear;
use App\Models\Finance\Billing;
use App\Models\Finance\FeeType;
use App\Models\Finance\FinancialReport;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentTransaction;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use App\Services\Finance\FinancialReportService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6 — Financial reports & summary foundation.
 *
 * Reports are generated snapshots: totals + source_fingerprint are derived
 * server-side from the successful signed transaction ledger, never from
 * client input. Summary filters apply one consistent billing scope to billed
 * and paid alike.
 *
 * Created rows use PH6-/VTX-/VP-/VS-/VT- markers and are removed in setUp /
 * tearDown so the seeded Phase 1 data stays untouched.
 */
class FinancialReportServiceTest extends TestCase
{
    private int $adminUserId;

    private int $testAcademicYearId;

    private int $testSemesterId;

    private const REPORT_START = '2026-11-01';

    private const REPORT_END = '2026-11-30';

    private const OTHER_START = '2026-12-01';

    private const OTHER_END = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupFinanceTestData();

        $this->adminUserId = User::where('role_id', Role::where('name', 'Admin')->value('id'))->firstOrFail()->id;
        $this->testAcademicYearId = AcademicYear::orderByDesc('id')->value('id');
        $this->testSemesterId = DB::connection()->table('semesters')->orderBy('id')->value('id');
    }

    protected function tearDown(): void
    {
        $this->cleanupFinanceTestData();
        parent::tearDown();
    }

    // ─── Auth / data helpers ───────────────────────────────────

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $user = User::where('role_id', $adminRole->id)->firstOrFail();
        Sanctum::actingAs($user);
        $this->adminUserId = $user->id;
    }

    private function authenticateAsGuru(): void
    {
        $guruRole = Role::where('name', 'Guru')->firstOrFail();
        $user = User::where('role_id', $guruRole->id)->firstOrFail();
        Sanctum::actingAs($user);
    }

    private function createTestStudent(): Student
    {
        return Student::create([
            'nisn' => 'VP-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'VS-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'PH6-Test Student',
            'gender' => 'L',
            'birth_place' => 'Test City',
            'birth_date' => '2008-01-01',
            'address' => 'Test Address',
        ]);
    }

    private function createTestFeeType(string $name): FeeType
    {
        return FeeType::create([
            'name' => $name,
            'amount' => 350000,
            'description' => 'PH6-Test fee type.',
            'is_active' => true,
        ]);
    }

    private function createBilling(Student $student, FeeType $feeType, array $overrides = []): Billing
    {
        return Billing::create(array_merge([
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'academic_year_id' => $this->testAcademicYearId,
            'semester_id' => null,
            'amount' => 400000,
            'status' => 'unpaid',
            'notes' => 'PH6-Billing',
            'period_start' => self::REPORT_START,
            'period_end' => self::REPORT_END,
        ], $overrides));
    }

    private function createPayment(Billing $billing, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'billing_id' => $billing->id,
            'student_id' => $billing->student_id,
            'payment_date' => '2026-11-05',
            'amount' => 100000,
            'method' => 'cash',
            'received_by' => $this->adminUserId,
            'notes' => 'PH6-Payment',
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
            'transaction_date' => '2026-11-05 09:00:00',
        ], $overrides));
    }

    private function reportService(): FinancialReportService
    {
        return app(FinancialReportService::class);
    }

    private function generateReport(string $type = 'bulanan', string $start = self::REPORT_START, string $end = self::REPORT_END): FinancialReport
    {
        return $this->reportService()->generate([
            'title' => 'PH6-FP-'.uniqid(),
            'report_type' => $type,
            'period_start' => $start,
            'period_end' => $end,
            'generated_by' => $this->adminUserId,
            'notes' => 'PH6-Fingerprint',
        ]);
    }

    private function validReportPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'PH6-'.uniqid(),
            'report_type' => 'bulanan',
            'period_start' => self::REPORT_START,
            'period_end' => self::REPORT_END,
            'notes' => 'PH6-Report',
        ], $overrides);
    }

    private function billedAndPaid(): array
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());

        $billingX = $this->createBilling($student, $feeType, ['amount' => 400000]);
        $paymentX = $this->createPayment($billingX);
        $this->createTransaction($paymentX, ['amount' => 250000]);
        $this->createTransaction($paymentX, ['type' => 'refund', 'amount' => -50000]);
        $this->createTransaction($paymentX, ['status' => 'pending', 'amount' => 100000]);

        $billingY = $this->createBilling($student, $feeType, ['amount' => 300000]);
        $paymentY = $this->createPayment($billingY);
        $this->createTransaction($paymentY, ['amount' => 300000]);

        return [$billingX, $billingY];
    }

    private function cleanupFinanceTestData(): void
    {
        $db = DB::connection();

        $db->table('payment_transactions')->where('transaction_code', 'like', 'VTX-%')->delete();
        $db->table('payments')->where('notes', 'like', 'PH6-%')->delete();
        $db->table('billings')->where('notes', 'like', 'PH6-%')->delete();
        $db->table('scholarships')->where('name', 'like', 'PH6-%')->delete();
        $db->table('financial_reports')->where('title', 'like', 'PH6-%')->delete();
        $db->table('fee_types')->where('name', 'like', 'VT-%')->delete();

        Student::where('nisn', 'like', 'VP-%')
            ->orWhere('nis', 'like', 'VS-%')
            ->forceDelete();
    }

    // ─── A. Generation ─────────────────────────────────────────

    public function test_generated_report_stores_correct_totals(): void
    {
        $this->authenticateAsAdmin();
        $this->billedAndPaid();

        $response = $this->postJson('/api/financial-reports', $this->validReportPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.total_billed', '700000.00');
        $response->assertJsonPath('data.total_paid', '500000.00');
        $response->assertJsonPath('data.total_outstanding', '200000.00');
        $this->assertSame(64, strlen($response->json('data.source_fingerprint')));

        $reportId = $response->json('data.id');
        $stored = DB::connection()->table('financial_reports')->where('id', $reportId)->first();
        $this->assertSame('700000.00', $stored->total_billed);
        $this->assertSame('500000.00', $stored->total_paid);
        $this->assertSame('200000.00', $stored->total_outstanding);
    }

    public function test_generated_by_is_server_controlled(): void
    {
        $this->authenticateAsAdmin();
        $this->billedAndPaid();
        $otherUser = $this->createAdminLikeUser();

        $response = $this->postJson('/api/financial-reports', $this->validReportPayload([
            'generated_by' => $otherUser,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.generated_by', $this->adminUserId);
    }

    public function test_client_supplied_totals_cannot_override_generated(): void
    {
        $this->authenticateAsAdmin();
        $this->billedAndPaid();

        $response = $this->postJson('/api/financial-reports', $this->validReportPayload([
            'total_billed' => 1,
            'total_paid' => 2,
            'total_outstanding' => 3,
            'source_fingerprint' => 'client-fake',
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.total_billed', '700000.00');
        $response->assertJsonPath('data.total_paid', '500000.00');
        $response->assertJsonPath('data.total_outstanding', '200000.00');
        $this->assertNotSame('client-fake', $response->json('data.source_fingerprint'));
    }

    // ─── B. Ledger treatment ───────────────────────────────────

    public function test_ledger_successful_and_pending_ledger_treatment(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());
        $billing = $this->createBilling($student, $feeType, ['amount' => 400000]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 250000]);          // success counts
        $this->createTransaction($payment, ['status' => 'pending', 'amount' => 100000]); // excluded
        $this->createTransaction($payment, ['status' => 'failed', 'amount' => 50000]);   // excluded

        $report = $this->generateReport();

        $this->assertSame('400000.00', $report->total_billed);
        $this->assertSame('250000.00', $report->total_paid);
        $this->assertSame('150000.00', $report->total_outstanding);
    }

    public function test_refund_and_adjustment_affect_net_by_sign(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());
        $billing = $this->createBilling($student, $feeType, ['amount' => 400000]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 250000]);
        $this->createTransaction($payment, ['type' => 'refund', 'amount' => -50000]);
        $this->createTransaction($payment, ['type' => 'adjustment', 'amount' => 25000]);

        $report = $this->generateReport();

        $this->assertSame('225000.00', $report->total_paid);
        $this->assertSame('175000.00', $report->total_outstanding);
    }

    // ─── C. Billing scope ──────────────────────────────────────

    public function test_cancelled_billing_is_excluded(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());
        $billing = $this->createBilling($student, $feeType, [
            'amount' => 400000,
            'status' => 'cancelled',
        ]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 400000]);

        $report = $this->generateReport();

        $this->assertSame('0.00', $report->total_billed);
        $this->assertSame('0.00', $report->total_paid);
        $this->assertSame('0.00', $report->total_outstanding);
    }

    public function test_null_period_billing_is_handled_deterministically(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());
        $billing = $this->createBilling($student, $feeType, [
            'amount' => 400000,
            'period_start' => null,
            'period_end' => null,
        ]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 400000]);

        $report = $this->generateReport();

        $this->assertSame('0.00', $report->total_billed);
        $this->assertSame('0.00', $report->total_paid);
    }

    // ─── D. Fingerprint ────────────────────────────────────────

    public function test_fingerprint_is_deterministic(): void
    {
        $this->billedAndPaid();

        $first = $this->generateReport();
        $second = $this->generateReport();

        $this->assertSame($first->source_fingerprint, $second->source_fingerprint);
        $this->assertSame(64, strlen($first->source_fingerprint));
    }

    public function test_fingerprint_changes_when_billing_amount_changes(): void
    {
        [$billingX] = $this->billedAndPaid();
        $before = $this->generateReport()->source_fingerprint;

        $billingX->update(['amount' => 450000]);
        $after = $this->generateReport()->source_fingerprint;

        $this->assertNotSame($before, $after);
    }

    public function test_fingerprint_ignores_pending_and_failed_transactions(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());
        $billing = $this->createBilling($student, $feeType, ['amount' => 400000]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 250000]);

        $before = $this->generateReport()->source_fingerprint;

        $this->createTransaction($payment, ['status' => 'pending', 'amount' => 100000]);
        $this->assertSame($before, $this->generateReport()->source_fingerprint);

        $this->createTransaction($payment, ['status' => 'failed', 'amount' => 50000]);
        $this->assertSame($before, $this->generateReport()->source_fingerprint);
    }

    public function test_fingerprint_changes_when_successful_refund_added(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());
        $billing = $this->createBilling($student, $feeType, ['amount' => 400000]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 250000]);

        $before = $this->generateReport()->source_fingerprint;

        $this->createTransaction($payment, ['type' => 'refund', 'amount' => -50000]);

        $this->assertNotSame($before, $this->generateReport()->source_fingerprint);
    }

    public function test_fingerprint_changes_when_billing_cancelled(): void
    {
        [$billingX] = $this->billedAndPaid();
        $before = $this->generateReport()->source_fingerprint;

        $billingX->update(['status' => 'cancelled']);
        $after = $this->generateReport()->source_fingerprint;

        $this->assertNotSame($before, $after);
    }

    public function test_fingerprint_differs_for_different_periods(): void
    {
        $this->billedAndPaid();

        $november = $this->generateReport('bulanan', self::REPORT_START, self::REPORT_END);
        $december = $this->generateReport('bulanan', self::OTHER_START, self::OTHER_END);

        $this->assertNotSame($november->source_fingerprint, $december->source_fingerprint);
    }

    public function test_fingerprint_differs_for_different_report_type(): void
    {
        $this->billedAndPaid();

        $bulanan = $this->generateReport('bulanan', self::REPORT_START, self::REPORT_END);
        $harian = $this->generateReport('harian', self::REPORT_START, self::REPORT_END);

        $this->assertNotSame($bulanan->source_fingerprint, $harian->source_fingerprint);
    }

    // ─── E. Update behaviour ───────────────────────────────────

    public function test_update_metadata_preserves_totals_and_fingerprint(): void
    {
        $this->authenticateAsAdmin();
        $this->billedAndPaid();
        $created = $this->postJson('/api/financial-reports', $this->validReportPayload());
        $created->assertStatus(201);
        $reportId = $created->json('data.id');

        $response = $this->putJson("/api/financial-reports/{$reportId}", [
            'title' => 'PH6-Renamed',
            'notes' => 'PH6-Updated notes',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'PH6-Renamed');
        $response->assertJsonPath('data.total_billed', '700000.00');
        $response->assertJsonPath('data.total_paid', '500000.00');
        $this->assertSame($created->json('data.source_fingerprint'), $response->json('data.source_fingerprint'));
    }

    public function test_update_cannot_overwrite_totals_manually(): void
    {
        $this->authenticateAsAdmin();
        $this->billedAndPaid();
        $created = $this->postJson('/api/financial-reports', $this->validReportPayload());
        $created->assertStatus(201);
        $reportId = $created->json('data.id');

        $response = $this->putJson("/api/financial-reports/{$reportId}", [
            'total_billed' => 999999,
            'total_paid' => 1,
            'total_outstanding' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_billed', '700000.00');
        $response->assertJsonPath('data.total_paid', '500000.00');
        $response->assertJsonPath('data.total_outstanding', '200000.00');
        $response->assertJsonPath('data.title', $created->json('data.title'));
    }

    public function test_update_changing_period_regenerates_totals_and_fingerprint(): void
    {
        $this->authenticateAsAdmin();

        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());

        $november = $this->createBilling($student, $feeType, [
            'amount' => 400000,
            'period_start' => self::REPORT_START,
            'period_end' => self::REPORT_END,
        ]);
        $novemberPayment = $this->createPayment($november);
        $this->createTransaction($novemberPayment, ['amount' => 250000]);

        $december = $this->createBilling($student, $feeType, [
            'amount' => 300000,
            'period_start' => self::OTHER_START,
            'period_end' => self::OTHER_END,
        ]);
        $decemberPayment = $this->createPayment($december);
        $this->createTransaction($decemberPayment, ['amount' => 300000]);

        $created = $this->postJson('/api/financial-reports', $this->validReportPayload());
        $created->assertStatus(201);
        $reportId = $created->json('data.id');
        $created->assertJsonPath('data.total_billed', '400000.00');
        $created->assertJsonPath('data.total_paid', '250000.00');

        $response = $this->putJson("/api/financial-reports/{$reportId}", [
            'period_start' => self::OTHER_START,
            'period_end' => self::OTHER_END,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_billed', '300000.00');
        $response->assertJsonPath('data.total_paid', '300000.00');
        $response->assertJsonPath('data.total_outstanding', '0.00');
        $this->assertNotSame($created->json('data.source_fingerprint'), $response->json('data.source_fingerprint'));
    }

    public function test_update_changing_report_type_behaves_consistently(): void
    {
        $this->authenticateAsAdmin();
        $this->billedAndPaid();
        $created = $this->postJson('/api/financial-reports', $this->validReportPayload());
        $created->assertStatus(201);
        $reportId = $created->json('data.id');

        $response = $this->putJson("/api/financial-reports/{$reportId}", ['report_type' => 'harian']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.report_type', 'harian');
        $response->assertJsonPath('data.total_billed', '700000.00');
        $response->assertJsonPath('data.total_paid', '500000.00');
        $this->assertNotSame($created->json('data.source_fingerprint'), $response->json('data.source_fingerprint'));
    }

    // ─── F. Summary ────────────────────────────────────────────

    public function test_summary_academic_year_filter_is_consistent(): void
    {
        $this->authenticateAsAdmin();
        $this->billedAndPaid();

        $response = $this->getJson("/api/reports/finance/summary?academic_year_id={$this->testAcademicYearId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.totals.total_billed', 700000);
        $response->assertJsonPath('data.totals.total_paid', 500000);
        $response->assertJsonPath('data.totals.total_outstanding', 200000);
    }

    public function test_summary_semester_filter_is_consistent(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());

        $billingA = $this->createBilling($student, $feeType, [
            'amount' => 300000,
            'semester_id' => $this->testSemesterId,
        ]);
        $paymentA = $this->createPayment($billingA);
        $this->createTransaction($paymentA, ['amount' => 300000]);

        $billingB = $this->createBilling($student, $feeType, ['amount' => 200000]);
        $paymentB = $this->createPayment($billingB);
        $this->createTransaction($paymentB, ['amount' => 200000]);

        $response = $this->getJson("/api/reports/finance/summary?semester_id={$this->testSemesterId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.totals.total_billed', 300000);
        $response->assertJsonPath('data.totals.total_paid', 300000);
        $response->assertJsonPath('data.totals.total_outstanding', 0);
    }

    public function test_summary_fee_type_filter_is_consistent(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeTypeA = $this->createTestFeeType('VT-Ph6-A-'.uniqid());
        $feeTypeB = $this->createTestFeeType('VT-Ph6-B-'.uniqid());

        $billingA = $this->createBilling($student, $feeTypeA, ['amount' => 400000]);
        $paymentA = $this->createPayment($billingA);
        $this->createTransaction($paymentA, ['amount' => 250000]);
        $this->createTransaction($paymentA, ['type' => 'refund', 'amount' => -50000]);

        $billingB = $this->createBilling($student, $feeTypeB, ['amount' => 300000]);
        $paymentB = $this->createPayment($billingB);
        $this->createTransaction($paymentB, ['amount' => 300000]);

        $response = $this->getJson("/api/reports/finance/summary?fee_type_id={$feeTypeA->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.totals.total_billed', 400000);
        $response->assertJsonPath('data.totals.total_paid', 200000);
        $response->assertJsonPath('data.totals.total_outstanding', 200000);
        $response->assertJsonPath('data.per_fee_type.0.fee_type_name', $feeTypeA->name);
        $response->assertJsonPath('data.per_fee_type.0.total_billed', 400000);
        $response->assertJsonPath('data.per_fee_type.0.total_paid', 200000);
    }

    public function test_summary_ledger_refunds_statuses_and_outstanding(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph6-'.uniqid());

        $active = $this->createBilling($student, $feeType, ['amount' => 400000]);
        $payment = $this->createPayment($active);
        $this->createTransaction($payment, ['amount' => 250000]);
        $this->createTransaction($payment, ['type' => 'refund', 'amount' => -50000]);
        $this->createTransaction($payment, ['status' => 'pending', 'amount' => 100000]);
        $this->createTransaction($payment, ['status' => 'failed', 'amount' => 20000]);

        $cancelled = $this->createBilling($student, $feeType, [
            'amount' => 500000,
            'status' => 'cancelled',
        ]);
        $cancelledPayment = $this->createPayment($cancelled);
        $this->createTransaction($cancelledPayment, ['amount' => 500000]);

        $response = $this->getJson("/api/reports/finance/summary?academic_year_id={$this->testAcademicYearId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.totals.total_billed', 400000);
        $response->assertJsonPath('data.totals.total_paid', 200000);
        $response->assertJsonPath('data.totals.total_outstanding', 200000);
        $response->assertJsonPath('data.monthly_trend.0.month', '2026-11');
        $response->assertJsonPath('data.monthly_trend.0.total_paid', 200000);
    }

    // ─── G. Authorization ──────────────────────────────────────

    private function createAdminLikeUser(): int
    {
        $role = Role::where('name', 'Administrator')->firstOrFail();
        $user = User::create([
            'username' => 'ph6other_'.mt_rand(100000, 999999),
            'name' => 'PH6-Other',
            'email' => 'ph6other.'.mt_rand(100000, 999999).'@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $role->id,
        ]);

        return $user->id;
    }

    public function test_unauthenticated_report_index_returns_401(): void
    {
        $this->getJson('/api/financial-reports')->assertStatus(401);
    }

    public function test_guru_cannot_create_report(): void
    {
        $this->authenticateAsGuru();
        $this->postJson('/api/financial-reports', $this->validReportPayload())->assertStatus(403);
    }

    public function test_admin_can_read_summary(): void
    {
        $this->authenticateAsAdmin();
        $this->getJson('/api/reports/finance/summary')->assertStatus(200);
    }
}
