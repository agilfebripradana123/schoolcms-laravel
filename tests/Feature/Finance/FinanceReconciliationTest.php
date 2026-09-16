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
use App\Services\Finance\BillingService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4 — Billing/Payment reconciliation.
 *
 * The billing status is authority-driven from successful payment transactions
 * (payment -> billing). Tests exercise both the BillingService directly and
 * the real HTTP write paths (payment + payment transaction controllers).
 *
 * Created rows are tagged with PH4-/VTX-/VP-/VS-/VT- markers and removed in
 * setUp/tearDown so the seeded Phase 1 data stays untouched.
 */
class FinanceReconciliationTest extends TestCase
{
    private int $adminUserId;

    private int $academicYearId;

    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupFinanceTestData();
        $this->authenticateAsAdmin();

        $this->academicYearId = AcademicYear::whereNull('deleted_at')->orderBy('id')->value('id');
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

    private function createTestStudent(): Student
    {
        return Student::create([
            'nisn' => 'VP-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'VS-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'PH4-Test Student',
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
            'description' => 'PH4-Test fee type.',
            'is_active' => true,
        ]);
    }

    private function createBilling(Student $student, FeeType $feeType, array $overrides = []): Billing
    {
        return Billing::create(array_merge([
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => null,
            'amount' => 350000,
            'status' => 'unpaid',
            'notes' => 'PH4-Billing',
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
            'notes' => 'PH4-Payment',
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

    private function reconcile(Billing $billing): array
    {
        return app(BillingService::class)->reconcile($billing);
    }

    private function billingStatus(int $id): ?string
    {
        return DB::connection()->table('billings')->where('id', $id)->value('status');
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
            'notes' => 'PH4-Payment POST',
        ], $overrides);
    }

    private function validTransactionPayload(Payment $payment, array $overrides = []): array
    {
        return array_merge([
            'payment_id' => $payment->id,
            'transaction_code' => 'VTX-'.uniqid(),
            'type' => 'payment',
            'amount' => 150000,
            'method' => 'cash',
            'status' => 'success',
            'transaction_date' => '2026-08-05 09:00:00',
        ], $overrides);
    }

    private function cleanupFinanceTestData(): void
    {
        $db = DB::connection();

        $db->table('payment_transactions')->where('transaction_code', 'like', 'VTX-%')->delete();
        $db->table('payments')->where('notes', 'like', 'PH4-%')->delete();
        $db->table('billings')->where('notes', 'like', 'PH4-%')->delete();
        $db->table('scholarships')->where('name', 'like', 'PH4-%')->delete();
        $db->table('financial_reports')->where('title', 'like', 'PH4-%')->delete();
        $db->table('fee_types')->where('name', 'like', 'VT-%')->delete();

        Student::where('nisn', 'like', 'VP-%')
            ->orWhere('nis', 'like', 'VS-%')
            ->forceDelete();
    }

    // ─── Service: status derivation ────────────────────────────

    public function test_reconcile_no_successful_transactions_sets_unpaid(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));

        $result = $this->reconcile($billing);

        $this->assertSame('unpaid', $result['status']);
        $this->assertSame(0.0, $result['net_paid']);
        $this->assertSame(350000.0, $result['outstanding']);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    public function test_reconcile_successful_transaction_below_amount_sets_partial(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 150000]);

        $result = $this->reconcile($billing);

        $this->assertSame('partial', $result['status']);
        $this->assertSame(150000.0, $result['net_paid']);
        $this->assertSame('partial', $this->billingStatus($billing->id));
    }

    public function test_reconcile_successful_transaction_equal_amount_sets_paid(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 350000]);

        $result = $this->reconcile($billing);

        $this->assertSame('paid', $result['status']);
        $this->assertSame(0.0, $result['outstanding']);
        $this->assertSame('paid', $this->billingStatus($billing->id));
    }

    public function test_reconcile_successful_transaction_over_amount_sets_paid(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 400000]);

        $result = $this->reconcile($billing);

        $this->assertSame('paid', $result['status']);
        $this->assertSame(-50000.0, $result['outstanding']);
        $this->assertSame('paid', $this->billingStatus($billing->id));
    }

    public function test_reconcile_pending_transaction_does_not_affect_status(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 350000, 'status' => 'pending']);

        $result = $this->reconcile($billing);

        $this->assertSame('unpaid', $result['status']);
    }

    public function test_reconcile_failed_transaction_does_not_affect_status(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 350000, 'status' => 'failed']);

        $result = $this->reconcile($billing);

        $this->assertSame('unpaid', $result['status']);
    }

    public function test_reconcile_successful_negative_refund_reduces_net_paid(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 350000]);

        $this->assertSame('paid', $this->reconcile($billing)['status']);

        $this->createTransaction($payment, [
            'type' => 'refund',
            'amount' => -100000,
        ]);

        $result = $this->reconcile($billing);

        $this->assertSame('partial', $result['status']);
        $this->assertSame(250000.0, $result['net_paid']);
        $this->assertSame(100000.0, $result['outstanding']);
    }

    public function test_reconcile_cancelled_billing_stays_cancelled(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()), [
            'status' => 'cancelled',
        ]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 350000]);

        $result = $this->reconcile($billing);

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(350000.0, $result['net_paid']);
        $this->assertSame('cancelled', $this->billingStatus($billing->id));
    }

    // ─── API: payment writes ───────────────────────────────────

    public function test_api_creating_payment_triggers_reconciliation(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billing));

        $response->assertStatus(201);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    public function test_api_updating_payment_amount_triggers_reconciliation(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing, ['amount' => 150000]);
        $this->createTransaction($payment, ['amount' => 150000]);
        $this->reconcile($billing);
        $this->assertSame('partial', $this->billingStatus($billing->id));

        $response = $this->putJson("/api/payments/{$payment->id}", ['amount' => 200000]);

        $response->assertStatus(200);
        $this->assertSame('partial', $this->billingStatus($billing->id));
    }

    public function test_api_moving_payment_reconciles_both_billings(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph4-'.uniqid());
        $billingA = $this->createBilling($student, $feeType, [
            'amount' => 350000,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
        $billingB = $this->createBilling($student, $feeType, [
            'amount' => 200000,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);

        $payment = $this->createPayment($billingA, ['amount' => 350000]);
        $this->createTransaction($payment, ['amount' => 350000]);
        $this->reconcile($billingA);
        $this->assertSame('paid', $this->billingStatus($billingA->id));

        $response = $this->putJson("/api/payments/{$payment->id}", ['billing_id' => $billingB->id]);

        $response->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billingA->id));
        $this->assertSame('paid', $this->billingStatus($billingB->id));
    }

    public function test_api_deleting_payment_reconciles_billing(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->deleteJson("/api/payments/{$payment->id}");

        $response->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
        $this->assertNotNull(
            DB::connection()->table('payments')->where('id', $payment->id)->value('deleted_at')
        );
    }

    // ─── API: transaction writes ───────────────────────────────

    public function test_api_creating_successful_transaction_reconciles_billing(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 150000,
        ]));

        $response->assertStatus(201);
        $this->assertSame('partial', $this->billingStatus($billing->id));
    }

    public function test_api_updating_transaction_status_pending_to_success_reconciles(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 150000, 'status' => 'pending']);
        $this->reconcile($billing);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));

        $response = $this->putJson("/api/payment-transactions/{$transaction->id}", ['status' => 'success']);

        $response->assertStatus(200);
        $this->assertSame('partial', $this->billingStatus($billing->id));
    }

    public function test_api_updating_transaction_status_success_to_failed_reconciles(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 150000]);
        $this->reconcile($billing);
        $this->assertSame('partial', $this->billingStatus($billing->id));

        $response = $this->putJson("/api/payment-transactions/{$transaction->id}", ['status' => 'failed']);

        $response->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    public function test_api_updating_transaction_amount_reconciles(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 100000]);
        $this->reconcile($billing);
        $this->assertSame('partial', $this->billingStatus($billing->id));

        $response = $this->putJson("/api/payment-transactions/{$transaction->id}", ['amount' => 350000]);

        $response->assertStatus(200);
        $this->assertSame('paid', $this->billingStatus($billing->id));
    }

    public function test_api_deleting_transaction_reconciles_billing(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 350000]);
        $this->reconcile($billing);
        $this->assertSame('paid', $this->billingStatus($billing->id));

        $response = $this->deleteJson("/api/payment-transactions/{$transaction->id}");

        $response->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    // ─── Soft delete behavior ──────────────────────────────────

    public function test_soft_deleted_payment_is_excluded(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing, ['amount' => 350000]);
        $this->createTransaction($payment, ['amount' => 350000]);
        $this->assertSame('paid', $this->reconcile($billing)['status']);

        $payment->delete();

        $result = $this->reconcile($billing);

        $this->assertSame('unpaid', $result['status']);
        $this->assertSame(0.0, $result['net_paid']);
    }

    public function test_soft_deleted_successful_transaction_is_excluded(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 350000]);
        $this->assertSame('paid', $this->reconcile($billing)['status']);

        $transaction->delete();

        $result = $this->reconcile($billing);

        $this->assertSame('unpaid', $result['status']);
        $this->assertSame(0.0, $result['net_paid']);
    }

    // ─── Derived figures & edge sums ───────────────────────────

    public function test_outstanding_calculation_is_correct(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 150000]);

        $result = $this->reconcile($billing);

        $this->assertSame(150000.0, $result['net_paid']);
        $this->assertSame(200000.0, $result['outstanding']);
        $this->assertSame('partial', $result['status']);
    }

    public function test_payment_without_successful_transaction_leaves_billing_unpaid(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $this->createPayment($billing);

        $result = $this->reconcile($billing);

        $this->assertSame('unpaid', $result['status']);
        $this->assertSame(0.0, $result['net_paid']);
    }

    public function test_multiple_successful_transactions_are_summed(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $paymentA = $this->createPayment($billing);
        $paymentB = $this->createPayment($billing);
        $this->createTransaction($paymentA, ['amount' => 100000]);
        $this->createTransaction($paymentB, ['amount' => 150000]);
        $this->createTransaction($paymentB, ['amount' => 100000]);

        $result = $this->reconcile($billing);

        $this->assertSame(350000.0, $result['net_paid']);
        $this->assertSame('paid', $result['status']);
    }

    public function test_mixed_transaction_statuses_sum_only_successful(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 100000, 'status' => 'success']);
        $this->createTransaction($payment, ['amount' => 50000, 'status' => 'pending']);
        $this->createTransaction($payment, ['amount' => 30000, 'status' => 'failed']);

        $result = $this->reconcile($billing);

        $this->assertSame(100000.0, $result['net_paid']);
        $this->assertSame('partial', $result['status']);
    }

    // ─── API contract & atomicity ──────────────────────────────

    public function test_api_response_follows_existing_envelope(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph4-'.uniqid());
        $billing = $this->createBilling($student, $feeType);
        $payment = $this->createPayment($billing);

        $billingResponse = $this->postJson('/api/billings', [
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'academic_year_id' => $this->academicYearId,
            'amount' => 100000,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'notes' => 'PH4-Envelope',
        ]);
        $billingResponse->assertStatus(201);
        $billingResponse->assertJsonStructure(['success', 'message', 'data']);
        $billingResponse->assertJsonPath('success', true);

        $paymentResponse = $this->postJson('/api/payments', $this->validPaymentPayload($billing));
        $paymentResponse->assertStatus(201);
        $paymentResponse->assertJsonStructure(['success', 'message', 'data']);
        $paymentResponse->assertJsonPath('success', true);
    }

    public function test_reconciliation_failure_rolls_back_payment_write(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph4-'.uniqid()));

        $this->app->instance(BillingService::class, new class extends BillingService
        {
            public function reconcile(Billing $billing): array
            {
                throw new \RuntimeException('reconciliation exploded');
            }
        });

        $response = $this->postJson('/api/payments', $this->validPaymentPayload($billing));

        $response->assertStatus(500);
        $this->assertSame(0, DB::connection()
            ->table('payments')
            ->where('notes', 'like', 'PH4-%')
            ->count());
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }
}
