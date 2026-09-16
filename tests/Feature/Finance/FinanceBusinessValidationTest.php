<?php

namespace Tests\Feature\Finance;

use App\Models\Academic\AcademicYear;
use App\Models\Finance\Billing;
use App\Models\Finance\FeeType;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentTransaction;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3 — Finance business validation boundary.
 *
 * Runs against the live MySQL test configuration (same convention as the rest
 * of the Feature suite). Every request-level business rule is exercised
 * through the real HTTP endpoints, asserting status + validation error field.
 *
 * Data created here is tagged with PH3-/VTX-/VP-/VS-/VT- markers and removed
 * in setUp/tearDown, so the seeded Phase 1 data stays untouched.
 */
class FinanceBusinessValidationTest extends TestCase
{
    private int $adminUserId;

    private int $academicYearId;

    private int $semesterId;

    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupFinanceTestData();
        $this->authenticateAsAdmin();

        $this->academicYearId = AcademicYear::whereNull('deleted_at')->orderBy('id')->value('id');
        $this->semesterId = DB::connection()->table('semesters')->orderBy('id')->value('id');
    }

    protected function tearDown(): void
    {
        $this->cleanupFinanceTestData();
        parent::tearDown();
    }

    // ─── Auth helpers ──────────────────────────────────────────

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $user = User::where('role_id', $adminRole->id)->firstOrFail();
        Sanctum::actingAs($user);
        $this->adminUserId = $user->id;
    }

    // ─── Data helpers ──────────────────────────────────────────

    private function createTestStudent(): Student
    {
        return Student::create([
            'nisn' => 'VP-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'VS-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'PH3-Test Student',
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
            'description' => 'PH3-Test fee type.',
            'is_active' => true,
        ]);
    }

    private function createBilling(Student $student, FeeType $feeType, array $overrides = []): Billing
    {
        return Billing::create(array_merge([
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'amount' => 350000,
            'status' => 'unpaid',
            'notes' => 'PH3-Billing',
        ], $overrides));
    }

    private function createPayment(Billing $billing, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'billing_id' => $billing->id,
            'student_id' => $billing->student_id,
            'payment_date' => '2026-08-05',
            'amount' => 100000,
            'method' => 'cash',
            'received_by' => $this->adminUserId,
            'notes' => 'PH3-Payment',
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
            'transaction_date' => '2026-08-05 09:00:00',
        ], $overrides));
    }

    // ─── Payload builders ──────────────────────────────────────

    private function validBillingPayload(Student $student, FeeType $feeType, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'amount' => 100000,
            'due_date' => '2026-11-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'notes' => 'PH3-Billing POST',
        ], $overrides);
    }

    private function validPaymentPayload(Billing $billing, array $overrides = []): array
    {
        return array_merge([
            'billing_id' => $billing->id,
            'student_id' => $billing->student_id,
            'payment_date' => '2026-08-05',
            'amount' => 100000,
            'method' => 'cash',
            'received_by' => $this->adminUserId,
            'notes' => 'PH3-Payment POST',
        ], $overrides);
    }

    private function validTransactionPayload(Payment $payment, array $overrides = []): array
    {
        return array_merge([
            'payment_id' => $payment->id,
            'transaction_code' => 'VTX-'.uniqid(),
            'type' => 'payment',
            'amount' => 100000,
            'method' => 'cash',
            'status' => 'success',
            'transaction_date' => '2026-08-05 09:00:00',
        ], $overrides);
    }

    // ─── Cleanup helpers ───────────────────────────────────────

    private function cleanupFinanceTestData(): void
    {
        $db = DB::connection();

        $db->table('payment_transactions')->where('transaction_code', 'like', 'VTX-%')->delete();
        $db->table('payments')->where('notes', 'like', 'PH3-%')->delete();
        $db->table('billings')->where('notes', 'like', 'PH3-%')->delete();
        $db->table('scholarships')->where('name', 'like', 'PH3-%')->delete();
        $db->table('financial_reports')->where('title', 'like', 'PH3-%')->delete();
        $db->table('fee_types')->where('name', 'like', 'VT-%')->delete();

        Student::where('nisn', 'like', 'VP-%')
            ->orWhere('nis', 'like', 'VS-%')
            ->forceDelete();
    }

    private function dbAmount(string $table, int $id, string $column): ?string
    {
        $row = DB::connection()->table($table)->where('id', $id)->first();

        return $row ? $row->{$column} : null;
    }

    // ─── Billing ───────────────────────────────────────────────

    public function test_billing_valid_billing_accepted(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Bill-'.uniqid());

        $response = $this->postJson('/api/billings', $this->validBillingPayload($student, $feeType));

        $response->assertStatus(201);
        $billingId = $response->json('data.id');
        $this->assertSame('2026-10-01', $this->dbAmount('billings', $billingId, 'period_start'));
        $this->assertSame('2026-10-31', $this->dbAmount('billings', $billingId, 'period_end'));
        $this->assertSame('unpaid', $this->dbAmount('billings', $billingId, 'status'));
    }

    public function test_billing_period_end_before_period_start_rejected(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Bill-'.uniqid());

        $response = $this->postJson('/api/billings', $this->validBillingPayload($student, $feeType, [
            'period_start' => '2026-10-31',
            'period_end' => '2026-10-01',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['period_end']);
    }

    public function test_billing_duplicate_active_period_rejected(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Bill-'.uniqid());

        $first = $this->postJson('/api/billings', $this->validBillingPayload($student, $feeType));
        $first->assertStatus(201);

        $response = $this->postJson('/api/billings', $this->validBillingPayload($student, $feeType));
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_id']);
    }

    public function test_billing_cancelled_billing_does_not_block_rebilling(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Bill-'.uniqid());

        $cancelled = $this->postJson('/api/billings', $this->validBillingPayload($student, $feeType, [
            'status' => 'cancelled',
        ]));
        $cancelled->assertStatus(201);

        $recreated = $this->postJson('/api/billings', $this->validBillingPayload($student, $feeType));
        $recreated->assertStatus(201);
    }

    public function test_billing_omitted_nullable_update_fields_are_preserved(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Bill-'.uniqid());

        $created = $this->postJson('/api/billings', $this->validBillingPayload($student, $feeType));
        $created->assertStatus(201);
        $billingId = $created->json('data.id');

        $response = $this->putJson("/api/billings/{$billingId}", [
            'notes' => 'PH3-Updated notes',
            'amount' => 12345,
        ]);

        $response->assertStatus(200);
        $this->assertSame('PH3-Updated notes', $this->dbAmount('billings', $billingId, 'notes'));
        $this->assertSame('12345.00', $this->dbAmount('billings', $billingId, 'amount'));
        $this->assertSame((string) $this->semesterId, (string) $this->dbAmount('billings', $billingId, 'semester_id'));
        $this->assertSame('2026-11-10', $this->dbAmount('billings', $billingId, 'due_date'));
        $this->assertSame('2026-10-01', $this->dbAmount('billings', $billingId, 'period_start'));
        $this->assertSame('2026-10-31', $this->dbAmount('billings', $billingId, 'period_end'));
    }

    // ─── Payment ───────────────────────────────────────────────

    public function test_payment_valid_payment_accepted(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Pay-'.uniqid()), [
            'amount' => 300000,
        ]);

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billing, [
            'amount' => 100000,
        ]));

        $response->assertStatus(201);
        $this->assertSame((string) $billing->student_id, $this->dbAmount('payments', $response->json('data.id'), 'student_id'));
    }

    public function test_payment_student_mismatch_rejected(): void
    {
        $studentA = $this->createTestStudent();
        $studentB = $this->createTestStudent();
        $billing = $this->createBilling($studentA, $this->createTestFeeType('VT-Pay-'.uniqid()), [
            'amount' => 300000,
        ]);

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billing, [
            'student_id' => $studentB->id,
            'amount' => 100000,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_id']);
    }

    public function test_payment_amount_lte_zero_rejected(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Pay-'.uniqid()), [
            'amount' => 300000,
        ]);

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billing, [
            'amount' => 0,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_exceeding_outstanding_rejected(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Pay-'.uniqid()), [
            'amount' => 350000,
        ]);
        $payment = $this->createPayment($billing, ['amount' => 200000]);
        $this->createTransaction($payment, ['amount' => 200000]);

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billing, [
            'amount' => 150000.01,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_invalid_method_rejected(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Pay-'.uniqid()), [
            'amount' => 300000,
        ]);

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billing, [
            'method' => 'cheque',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['method']);
    }

    public function test_payment_duplicate_reference_rejected(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Pay-'.uniqid());
        $billingA = $this->createBilling($student, $feeType, [
            'amount' => 300000,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);
        $billingB = $this->createBilling($student, $feeType, [
            'amount' => 300000,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
        ]);

        $reference = 'PH3-REF-'.uniqid();

        $first = $this->postJson('/api/payments', $this->validPaymentPayload($billingA, [
            'reference_number' => $reference,
        ]));
        $first->assertStatus(201);

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billingB, [
            'reference_number' => $reference,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reference_number']);
    }

    public function test_payment_deleted_billing_rejected(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Pay-'.uniqid()), [
            'amount' => 300000,
        ]);
        $billingId = $billing->id;
        $billing->delete();

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billing, [
            'billing_id' => $billingId,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['billing_id']);
    }

    // ─── Payment transactions ──────────────────────────────────

    private function billingWithSuccessfulTransaction(float $amount): array
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Trx-'.uniqid()), [
            'amount' => 350000,
        ]);
        $payment = $this->createPayment($billing, ['amount' => $amount]);
        $transaction = $this->createTransaction($payment, ['amount' => $amount]);

        return [$billing, $payment, $transaction];
    }

    public function test_payment_transaction_valid_transaction_accepted(): void
    {
        [, $payment] = $this->billingWithSuccessfulTransaction(100000);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 50000,
        ]));

        $response->assertStatus(201);
    }

    public function test_payment_transaction_refund_must_be_negative(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Trx-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => 100,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_transaction_payment_must_be_positive(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Trx-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'payment',
            'amount' => -50,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_transaction_adjustment_cannot_be_zero(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Trx-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'adjustment',
            'amount' => 0,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_transaction_invalid_type_rejected(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Trx-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'other',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_payment_transaction_invalid_status_rejected(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Trx-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'status' => 'weird',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_payment_transaction_duplicate_transaction_code_rejected(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Trx-'.uniqid()));
        $payment = $this->createPayment($billing);

        $code = 'VTX-DUP-'.uniqid();
        $this->createTransaction($payment, ['transaction_code' => $code]);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'transaction_code' => $code,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['transaction_code']);
    }

    public function test_payment_transaction_refund_exceeding_refundable_rejected(): void
    {
        [, $payment] = $this->billingWithSuccessfulTransaction(200000);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -200001,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_transaction_deleted_payment_rejected(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Trx-'.uniqid()));
        $payment = $this->createPayment($billing);
        $paymentId = $payment->id;
        $payment->delete();

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'payment_id' => $paymentId,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_id']);
    }

    // ─── Scholarship ───────────────────────────────────────────

    private function validScholarshipPayload(Student $student, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $student->id,
            'name' => 'PH3-'.uniqid(),
            'provider' => 'PH3-Provider',
            'amount' => 100000,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'status' => 'aktif',
        ], $overrides);
    }

    public function test_scholarship_end_before_start_rejected(): void
    {
        $student = $this->createTestStudent();

        $response = $this->postJson('/api/scholarships', $this->validScholarshipPayload($student, [
            'start_date' => '2026-09-30',
            'end_date' => '2026-09-01',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);
    }

    public function test_scholarship_invalid_status_rejected(): void
    {
        $student = $this->createTestStudent();

        $response = $this->postJson('/api/scholarships', $this->validScholarshipPayload($student, [
            'status' => 'lunas',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_scholarship_negative_amount_rejected(): void
    {
        $student = $this->createTestStudent();

        $response = $this->postJson('/api/scholarships', $this->validScholarshipPayload($student, [
            'amount' => -1,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    // ─── Financial report ──────────────────────────────────────

    private function validReportPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'PH3-'.uniqid(),
            'report_type' => 'bulanan',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'notes' => 'PH3-Report',
        ], $overrides);
    }

    public function test_report_period_end_before_period_start_rejected(): void
    {
        $response = $this->postJson('/api/financial-reports', $this->validReportPayload([
            'period_start' => '2026-08-31',
            'period_end' => '2026-08-01',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['period_end']);
    }

    public function test_report_invalid_report_type_rejected(): void
    {
        $response = $this->postJson('/api/financial-reports', $this->validReportPayload([
            'report_type' => 'mingguan',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['report_type']);
    }

    // ─── Fee types ─────────────────────────────────────────────

    public function test_fee_type_duplicate_name_rejected(): void
    {
        $name = 'VT-Fee-'.uniqid();
        $this->createTestFeeType($name);

        $response = $this->postJson('/api/fee-types', [
            'name' => $name,
            'amount' => 100000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_fee_type_negative_amount_rejected(): void
    {
        $response = $this->postJson('/api/fee-types', [
            'name' => 'VT-Fee-'.uniqid(),
            'amount' => -1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }
}
