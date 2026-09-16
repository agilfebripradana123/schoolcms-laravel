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
use App\Services\Finance\PaymentTransactionService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5 — Finance transaction & refund logic.
 *
 * The payment_transactions table is the signed ledger. Refund capacity is
 * derived exclusively from successful ledger rows; payment delete is
 * protected once ledger history exists. Tests exercise the real HTTP
 * transaction endpoints (backed by PaymentTransactionService) plus the
 * service itself.
 *
 * Created rows are tagged with PH5-/VTX-/VP-/VS-/VT- markers and removed in
 * setUp/tearDown so the seeded Phase 1 data stays untouched.
 */
class FinanceTransactionServiceTest extends TestCase
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
            'name' => 'PH5-Test Student',
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
            'description' => 'PH5-Test fee type.',
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
            'notes' => 'PH5-Billing',
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
            'notes' => 'PH5-Payment',
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

    private function transactionService(): PaymentTransactionService
    {
        return app(PaymentTransactionService::class);
    }

    private function billingStatus(int $id): ?string
    {
        return DB::connection()->table('billings')->where('id', $id)->value('status');
    }

    private function transactionCount(string $codeLike = 'VTX-%'): int
    {
        return DB::connection()
            ->table('payment_transactions')
            ->where('transaction_code', 'like', $codeLike)
            ->count();
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
        $db->table('payments')->where('notes', 'like', 'PH5-%')->delete();
        $db->table('billings')->where('notes', 'like', 'PH5-%')->delete();
        $db->table('scholarships')->where('name', 'like', 'PH5-%')->delete();
        $db->table('financial_reports')->where('title', 'like', 'PH5-%')->delete();
        $db->table('fee_types')->where('name', 'like', 'VT-%')->delete();

        Student::where('nisn', 'like', 'VP-%')
            ->orWhere('nis', 'like', 'VS-%')
            ->forceDelete();
    }

    // ─── A. Ledger semantics ───────────────────────────────────

    public function test_ledger_payment_amount_must_be_positive(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph5-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => -10,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_ledger_refund_amount_must_be_negative(): void
    {
        $student = $this->createTestStudent();
        $billing = $this->createBilling($student, $this->createTestFeeType('VT-Ph5-'.uniqid()));
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => 1000,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_ledger_adjustment_positive_accepted(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'adjustment',
            'amount' => 25000,
        ]));

        $response->assertStatus(201);
    }

    public function test_ledger_adjustment_negative_accepted(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'adjustment',
            'amount' => -25000,
        ]));

        $response->assertStatus(201);
    }

    public function test_ledger_adjustment_zero_rejected(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'adjustment',
            'amount' => 0,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    // ─── B. Status participation ───────────────────────────────

    public function test_status_success_affects_billing_balance(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 150000,
        ]));

        $response->assertStatus(201);
        $this->assertSame('partial', $this->billingStatus($billing->id));
    }

    public function test_status_pending_excluded_from_balance(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 150000,
            'status' => 'pending',
        ]));

        $response->assertStatus(201);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    public function test_status_failed_excluded_from_balance(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 150000,
            'status' => 'failed',
        ]));

        $response->assertStatus(201);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    // ─── C. Refund capacity ────────────────────────────────────

    public function test_refund_within_capacity_succeeds(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 500000,
        ]))->assertStatus(201);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -100000,
        ]));

        $response->assertStatus(201);
        $this->assertSame('paid', $this->billingStatus($billing->id));
    }

    public function test_refund_exactly_equal_to_refundable_succeeds(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 500000,
        ]))->assertStatus(201);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -500000,
        ]));

        $response->assertStatus(201);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    public function test_refund_exceeding_capacity_rejected(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 200000,
        ]))->assertStatus(201);

        $before = $this->transactionCount();

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -200001,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
        $this->assertSame($before, $this->transactionCount());
        $this->assertSame('partial', $this->billingStatus($billing->id));
    }

    public function test_multiple_refunds_accumulate_correctly(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 500000,
        ]))->assertStatus(201);

        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -100000,
        ]))->assertStatus(201);

        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -400000,
        ]))->assertStatus(201);

        $this->assertSame('unpaid', $this->billingStatus($billing->id));

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -1,
        ]));
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_pending_and_failed_do_not_increase_refund_capacity(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $this->createTransaction($payment, ['amount' => 500000, 'status' => 'pending']);

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -100000,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    // ─── D. Reconciliation interaction ─────────────────────────

    public function test_refund_changes_billing_status(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing, ['amount' => 350000]);
        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 350000,
        ]))->assertStatus(201);
        $this->assertSame('paid', $this->billingStatus($billing->id));

        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -350000,
        ]))->assertStatus(201);

        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    public function test_deleted_transaction_no_longer_counts(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 350000]);
        $this->assertSame('paid', $this->reconcile($billing)['status']);

        $transaction->delete();

        $this->assertSame('unpaid', $this->reconcile($billing)['status']);
    }

    public function test_update_success_to_failed_reconciles(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 150000]);
        $this->reconcile($billing);
        $this->assertSame('partial', $this->billingStatus($billing->id));

        $response = $this->putJson("/api/payment-transactions/{$transaction->id}", ['status' => 'failed']);

        $response->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }

    public function test_update_failed_to_success_reconciles(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 150000, 'status' => 'failed']);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));

        $response = $this->putJson("/api/payment-transactions/{$transaction->id}", ['status' => 'success']);

        $response->assertStatus(200);
        $this->assertSame('partial', $this->billingStatus($billing->id));
    }

    public function test_payment_id_move_reconciles_both_billings(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType('VT-Ph5-'.uniqid());
        $billingA = $this->createBilling($student, $feeType, ['amount' => 300000]);
        $billingB = $this->createBilling($student, $feeType, ['amount' => 300000]);
        $paymentA = $this->createPayment($billingA);
        $paymentB = $this->createPayment($billingB);
        $transaction = $this->createTransaction($paymentA, ['amount' => 150000]);
        $this->reconcile($billingA);
        $this->assertSame('partial', $this->billingStatus($billingA->id));

        $response = $this->putJson("/api/payment-transactions/{$transaction->id}", ['payment_id' => $paymentB->id]);

        $response->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billingA->id));
        $this->assertSame('partial', $this->billingStatus($billingB->id));
    }

    // ─── E. Payment delete protection ──────────────────────────

    public function test_payment_without_transactions_can_be_deleted(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $response = $this->deleteJson("/api/payments/{$payment->id}");

        $response->assertStatus(200);
    }

    public function test_payment_with_transaction_history_returns_409(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 100000]);

        $response = $this->deleteJson("/api/payments/{$payment->id}");

        $response->assertStatus(409);
        $response->assertJson(['success' => false]);
        $this->assertNull(
            DB::connection()->table('payments')->where('id', $payment->id)->value('deleted_at')
        );
    }

    public function test_payment_delete_does_not_cascade_ledger_history(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 100000]);

        $this->deleteJson("/api/payments/{$payment->id}")->assertStatus(409);

        $this->assertNotNull(
            DB::connection()->table('payment_transactions')->where('id', $transaction->id)->first()
        );
        $this->assertSame('100000.00', DB::connection()
            ->table('payment_transactions')->where('id', $transaction->id)->value('amount'));
    }

    public function test_payment_with_soft_deleted_transaction_still_protected(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 100000]);
        $transaction->delete();

        $response = $this->deleteJson("/api/payments/{$payment->id}");

        $response->assertStatus(409);
    }

    // ─── F. Transaction delete / update ────────────────────────

    public function test_api_deleting_transaction_reconciles_billing(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 350000]);
        $this->reconcile($billing);
        $this->assertSame('paid', $this->billingStatus($billing->id));

        $response = $this->deleteJson("/api/payment-transactions/{$transaction->id}");

        $response->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
        $this->assertNotNull(
            DB::connection()->table('payment_transactions')->where('id', $transaction->id)->value('deleted_at')
        );
    }

    public function test_update_amount_reconciles_billing(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 100000]);
        $this->reconcile($billing);
        $this->assertSame('partial', $this->billingStatus($billing->id));

        $response = $this->putJson("/api/payment-transactions/{$transaction->id}", ['amount' => 350000]);

        $response->assertStatus(200);
        $this->assertSame('paid', $this->billingStatus($billing->id));
    }

    // ─── G. Atomicity ──────────────────────────────────────────

    public function test_failed_refund_rolls_back_transaction_write(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);
        $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 200000,
        ]))->assertStatus(201);

        $before = $this->transactionCount();

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'type' => 'refund',
            'amount' => -999999,
        ]));

        $response->assertStatus(422);
        $this->assertSame($before, $this->transactionCount());
        $this->assertSame('partial', $this->billingStatus($billing->id));
    }

    public function test_failed_reconciliation_rolls_back_transaction_and_billing(): void
    {
        $billing = $this->createBilling(
            $this->createTestStudent(),
            $this->createTestFeeType('VT-Ph5-'.uniqid())
        );
        $payment = $this->createPayment($billing);

        $this->app->instance(BillingService::class, new class extends BillingService
        {
            public function reconcile(Billing $billing): array
            {
                throw new \RuntimeException('reconciliation exploded');
            }
        });

        $before = $this->transactionCount();

        $response = $this->postJson('/api/payment-transactions', $this->validTransactionPayload($payment, [
            'amount' => 150000,
        ]));

        $response->assertStatus(500);
        $this->assertSame($before, $this->transactionCount());
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
    }
}
