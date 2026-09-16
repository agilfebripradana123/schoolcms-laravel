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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7 — Finance Admin API regression coverage.
 *
 * HTTP-level tests for the whole Admin Finance surface: six resources
 * (fee-types, billings, payments, payment-transactions, financial-reports,
 * scholarships) plus /reports/finance/summary, the authorization matrix
 * (Admin/Administrator/Guru/Siswa/Guest), response envelope, pagination and
 * the key financial invariants from Phases 1–6.
 *
 * Created rows use PH7-/VTX-/VP-/VS-/VT- markers and are removed in setUp /
 * tearDown so seeded Phase 1 data stays untouched.
 */
class FinanceAdminApiTest extends TestCase
{
    private int $adminUserId;

    private int $academicYearId;

    private int $semesterId;

    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupFinanceTestData();

        $this->adminUserId = $this->ensureRoleUser('Admin')->id;
        $this->academicYearId = AcademicYear::orderByDesc('id')->value('id');
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
        $this->authenticateAsRole('Admin');
    }

    private function authenticateAsAdministrator(): void
    {
        $this->authenticateAsRole('Administrator');
    }

    private function authenticateAsGuru(): void
    {
        $this->authenticateAsRole('Guru');
    }

    private function authenticateAsSiswa(): void
    {
        $this->authenticateAsRole('Siswa');
    }

    private function authenticateAsRole(string $roleName): void
    {
        Sanctum::actingAs($this->ensureRoleUser($roleName));
    }

    private function ensureRoleUser(string $roleName): User
    {
        $roleId = (int) Role::where('name', $roleName)->value('id');
        $user = User::where('role_id', $roleId)->first();

        if ($user !== null) {
            return $user;
        }

        return User::create([
            'username' => 'PH7R-'.$roleName.'-'.mt_rand(100000, 999999),
            'name' => 'PH7-Role '.$roleName,
            'email' => 'ph7role.'.$roleName.'.'.mt_rand(100000, 999999).'@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    // ─── Data helpers ──────────────────────────────────────────

    private function createTestStudent(): Student
    {
        return Student::create([
            'nisn' => 'VP-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'VS-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'PH7-Test Student',
            'gender' => 'L',
            'birth_place' => 'Test City',
            'birth_date' => '2008-01-01',
            'address' => 'Test Address',
        ]);
    }

    private function createTestFeeType(string $name = ''): FeeType
    {
        return FeeType::create([
            'name' => $name !== '' ? $name : 'VT-Ph7-'.uniqid(),
            'amount' => 350000,
            'description' => 'PH7-Test fee type.',
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
            'notes' => 'PH7-Billing',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ], $overrides));
    }

    private function createPayment(Billing $billing, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'billing_id' => $billing->id,
            'student_id' => $billing->student_id,
            'payment_date' => '2026-09-05',
            'amount' => 100000,
            'method' => 'cash',
            'received_by' => $this->adminUserId,
            'notes' => 'PH7-Payment',
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

    private function dbValue(string $table, int $id, string $column): mixed
    {
        return DB::connection()->table($table)->where('id', $id)->value($column);
    }

    private function billingStatus(int $id): ?string
    {
        return $this->dbValue('billings', $id, 'status');
    }

    private function reconcileBilling(Billing $billing): void
    {
        app(BillingService::class)->reconcile($billing);
    }

    private function cleanupFinanceTestData(): void
    {
        $db = DB::connection();

        $db->table('payment_transactions')->where('transaction_code', 'like', 'VTX-%')->delete();
        $db->table('payments')->where('notes', 'like', 'PH7-%')->delete();
        $db->table('billings')->where('notes', 'like', 'PH7-%')->delete();
        $db->table('scholarships')->where('name', 'like', 'PH7-%')->delete();
        $db->table('financial_reports')->where('title', 'like', 'PH7-%')->delete();
        $db->table('fee_types')->where('name', 'like', 'VT-%')->delete();
        $db->table('users')->where('username', 'like', 'PH7R-%')->delete();

        Student::where('nisn', 'like', 'VP-%')
            ->orWhere('nis', 'like', 'VS-%')
            ->forceDelete();
    }

    // ─── Envelope helpers ──────────────────────────────────────

    private function assertListEnvelope(TestResponse $response): void
    {
        $response->assertJsonStructure(['success', 'message', 'data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $response->assertJsonPath('success', true);
    }

    private function assertItemEnvelope(TestResponse $response): void
    {
        $response->assertJsonStructure(['success', 'message', 'data']);
        $response->assertJsonPath('success', true);
    }

    // ─── FEE TYPES ─────────────────────────────────────────────

    public function test_fee_types_guest_list_returns_401(): void
    {
        $this->getJson('/api/fee-types')->assertStatus(401);
    }

    public function test_admin_lists_fee_types_with_envelope_and_pagination(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/fee-types');

        $this->assertListEnvelope($response);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 15);
        $response->assertJsonPath('meta.last_page', 1);
    }

    public function test_admin_shows_fee_type(): void
    {
        $this->authenticateAsAdmin();
        $feeType = $this->createTestFeeType();

        $response = $this->getJson("/api/fee-types/{$feeType->id}");

        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.id', $feeType->id);
        $response->assertJsonPath('data.name', $feeType->name);
    }

    public function test_admin_creates_fee_type(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/fee-types', [
            'name' => 'VT-Ph7-Create-'.uniqid(),
            'amount' => 125000,
            'description' => 'PH7-test',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertItemEnvelope($response);
        $id = $response->json('data.id');
        $this->assertSame('125000.00', $this->dbValue('fee_types', $id, 'amount'));
        $this->assertSame(1, (int) $this->dbValue('fee_types', $id, 'is_active'));
    }

    public function test_administrator_creates_fee_type(): void
    {
        $this->authenticateAsAdministrator();

        $response = $this->postJson('/api/fee-types', [
            'name' => 'VT-Ph7-Adminstr-'.uniqid(),
            'amount' => 50000,
        ]);

        $response->assertStatus(201);
        $this->assertItemEnvelope($response);
    }

    public function test_admin_updates_fee_type_with_put_and_patch(): void
    {
        $this->authenticateAsAdmin();
        $feeType = $this->createTestFeeType();

        $put = $this->putJson("/api/fee-types/{$feeType->id}", [
            'amount' => 99999,
            'description' => 'PH7-updated',
        ]);
        $put->assertStatus(200);
        $this->assertSame('99999.00', $this->dbValue('fee_types', $feeType->id, 'amount'));

        $patch = $this->patchJson("/api/fee-types/{$feeType->id}", ['is_active' => false]);
        $patch->assertStatus(200);
        $patch->assertJsonPath('data.is_active', false);
    }

    public function test_admin_deletes_fee_type(): void
    {
        $this->authenticateAsAdmin();
        $feeType = $this->createTestFeeType();

        $response = $this->deleteJson("/api/fee-types/{$feeType->id}");

        $response->assertStatus(200);
        $this->assertNull($this->dbValue('fee_types', $feeType->id, 'id'));
    }

    public function test_fee_type_validation_rules(): void
    {
        $this->authenticateAsAdmin();
        $base = ['amount' => 100000];

        $this->postJson('/api/fee-types', $base)->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson('/api/fee-types', array_merge($base, ['name' => str_repeat('x', 101)]))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson('/api/fee-types', array_merge($base, ['name' => 'VT-Ph7-V-'.uniqid(), 'amount' => 'abc']))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/fee-types', array_merge($base, ['name' => 'VT-Ph7-V-'.uniqid(), 'amount' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/fee-types', array_merge($base, ['name' => 'VT-Ph7-V-'.uniqid(), 'is_active' => 'not-a-boolean']))
            ->assertStatus(422)->assertJsonValidationErrors(['is_active']);
    }

    public function test_fee_type_duplicate_name_rejected(): void
    {
        $this->authenticateAsAdmin();
        $name = 'VT-Ph7-Dup-'.uniqid();
        $this->createTestFeeType($name);

        $this->postJson('/api/fee-types', ['name' => $name, 'amount' => 100000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_guru_and_siswa_cannot_write_fee_types(): void
    {
        $this->authenticateAsGuru();
        $this->postJson('/api/fee-types', ['name' => 'VT-Ph7-403-'.uniqid(), 'amount' => 1])->assertStatus(403);

        $this->authenticateAsSiswa();
        $this->postJson('/api/fee-types', ['name' => 'VT-Ph7-403-'.uniqid(), 'amount' => 1])->assertStatus(403);
    }

    // ─── BILLINGS ──────────────────────────────────────────────

    public function test_billings_guest_list_returns_401(): void
    {
        $this->getJson('/api/billings')->assertStatus(401);
    }

    public function test_admin_lists_billings_with_relations(): void
    {
        $this->authenticateAsAdmin();
        $feeType = $this->createTestFeeType();
        $student = $this->createTestStudent();
        $this->createBilling($student, $feeType);

        $response = $this->getJson('/api/billings');

        $this->assertListEnvelope($response);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 15);

        $item = collect($response->json('data'))->firstWhere('notes', 'PH7-Billing');
        $this->assertNotNull($item);
        $this->assertArrayHasKey('student', $item);
        $this->assertArrayHasKey('fee_type', $item);
        $this->assertArrayHasKey('academic_year', $item);
        $this->assertArrayHasKey('semester', $item);
    }

    public function test_admin_creates_valid_billing(): void
    {
        $this->authenticateAsAdmin();
        $feeType = $this->createTestFeeType();
        $student = $this->createTestStudent();

        $response = $this->postJson('/api/billings', $this->billingPayload($student, $feeType));

        $response->assertStatus(201);
        $this->assertItemEnvelope($response);
        $id = $response->json('data.id');
        $this->assertSame('2026-09-01', $this->dbValue('billings', $id, 'period_start'));
        $this->assertSame('2026-09-30', $this->dbValue('billings', $id, 'period_end'));
        $this->assertSame('unpaid', $this->billingStatus($id));
    }

    private function billingPayload(Student $student, FeeType $feeType, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'amount' => 350000,
            'due_date' => '2026-09-10',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'notes' => 'PH7-Billing POST',
        ], $overrides);
    }

    public function test_billing_creation_validation(): void
    {
        $this->authenticateAsAdmin();
        $feeType = $this->createTestFeeType();
        $student = $this->createTestStudent();

        $base = $this->billingPayload($student, $feeType);

        $this->postJson('/api/billings', array_merge($base, ['student_id' => 9999999]))
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
        $this->postJson('/api/billings', array_merge($base, ['fee_type_id' => 9999999]))
            ->assertStatus(422)->assertJsonValidationErrors(['fee_type_id']);
        $this->postJson('/api/billings', array_merge($base, ['academic_year_id' => 9999999]))
            ->assertStatus(422)->assertJsonValidationErrors(['academic_year_id']);
        $this->postJson('/api/billings', array_merge($base, ['semester_id' => 9999999]))
            ->assertStatus(422)->assertJsonValidationErrors(['semester_id']);
    }

    public function test_billing_amount_and_period_validation(): void
    {
        $this->authenticateAsAdmin();
        $feeType = $this->createTestFeeType();
        $student = $this->createTestStudent();
        $base = $this->billingPayload($student, $feeType);

        $this->postJson('/api/billings', array_merge($base, ['amount' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/billings', array_merge($base, ['amount' => 'abc']))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/billings', array_merge($base, ['period_start' => '2026-09-30', 'period_end' => '2026-09-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['period_end']);
        $this->postJson('/api/billings', array_merge($base, ['period_end' => 'not-a-date']))
            ->assertStatus(422)->assertJsonValidationErrors(['period_end']);
    }

    public function test_billing_duplicate_active_period_blocked(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();

        $this->postJson('/api/billings', $this->billingPayload($student, $this->createTestFeeType()))->assertStatus(201);

        $student2 = $this->createTestStudent();
        $feeType2 = $this->createTestFeeType();
        $first = $this->postJson('/api/billings', $this->billingPayload($student2, $feeType2));
        $first->assertStatus(201);

        $this->postJson('/api/billings', $this->billingPayload($student2, $feeType2))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_cancelled_billing_does_not_block_rebilling(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $payload = $this->billingPayload($student, $feeType);

        $this->postJson('/api/billings', $payload + ['status' => 'cancelled'])->assertStatus(201);
        $this->postJson('/api/billings', $payload)->assertStatus(201);
    }

    public function test_billing_status_lifecycle_unpaid_partial_paid(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType, ['amount' => 350000]);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));

        // First payment + successful transaction → partial.
        $paymentOne = $this->postJson('/api/payments', $this->paymentPayload($billing, ['amount' => 150000]));
        $paymentOne->assertStatus(201);
        $paymentOneId = $paymentOne->json('data.id');
        $this->postJson('/api/payment-transactions', $this->transactionPayload($paymentOneId, ['amount' => 150000]))->assertStatus(201);
        $this->assertSame('partial', $this->billingStatus($billing->id));

        // Full settlement → paid.
        $paymentTwo = $this->postJson('/api/payments', $this->paymentPayload($billing, ['amount' => 200000]));
        $paymentTwo->assertStatus(201);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($paymentTwo->json('data.id'), ['amount' => 200000]))->assertStatus(201);
        $this->assertSame('paid', $this->billingStatus($billing->id));
    }

    public function test_cancelled_billing_preserved_after_payment_writes(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType, ['status' => 'cancelled']);
        $payment = $this->postJson('/api/payments', $this->paymentPayload($billing));
        $payment->assertStatus(201);

        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment->json('data.id'), ['amount' => 350000]))->assertStatus(201);

        $this->assertSame('cancelled', $this->billingStatus($billing->id));
    }

    public function test_admin_updates_billing(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType);

        $response = $this->putJson("/api/billings/{$billing->id}", [
            'amount' => 400000,
            'notes' => 'PH7-Billing Updated',
        ]);

        $response->assertStatus(200);
        $this->assertSame('400000.00', $this->dbValue('billings', $billing->id, 'amount'));
        $this->assertSame('PH7-Billing Updated', $this->dbValue('billings', $billing->id, 'notes'));
        $this->assertSame('2026-09-01', $this->dbValue('billings', $billing->id, 'period_start'));
    }

    public function test_billing_update_validation_and_duplicates(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $student2 = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType);

        $this->putJson("/api/billings/{$billing->id}", ['period_start' => '2026-09-30', 'period_end' => '2026-09-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['period_end']);

        // Collide with an existing active billing for the same identity.
        $payload = $this->billingPayload($student2, $feeType);
        $this->postJson('/api/billings', $payload)->assertStatus(201);

        $this->putJson("/api/billings/{$billing->id}", [
            'student_id' => $student2->id,
            'fee_type_id' => $feeType->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ])->assertStatus(422)->assertJsonValidationErrors(['student_id']);
    }

    public function test_admin_deletes_billing_softly(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType);

        $response = $this->deleteJson("/api/billings/{$billing->id}");

        $response->assertStatus(200);
        $this->assertNotNull($this->dbValue('billings', $billing->id, 'deleted_at'));
    }

    public function test_billing_show_missing_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $this->getJson('/api/billings/9999999')->assertStatus(404);
    }

    public function test_admin_shows_billing_detail_relations_and_zero_totals(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType(), ['amount' => 350000]);

        $response = $this->getJson("/api/billings/{$billing->id}");

        $response->assertStatus(200);
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.id', $billing->id);
        $response->assertJsonPath('data.status', 'unpaid');
        $response->assertJsonPath('data.paid', '0.00');
        $response->assertJsonPath('data.outstanding', '350000.00');
        $response->assertJsonCount(0, 'data.payments');
        $response->assertJsonStructure([
            'data' => ['student', 'fee_type', 'academic_year', 'semester', 'payments', 'paid', 'outstanding'],
        ]);
    }

    public function test_admin_shows_billing_detail_paid_and_outstanding(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType(), ['amount' => 350000]);
        $payment = $this->createPayment($billing, ['amount' => 150000]);
        $this->createTransaction($payment, ['amount' => 150000, 'status' => 'success']);

        $response = $this->getJson("/api/billings/{$billing->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.paid', '150000.00');
        $response->assertJsonPath('data.outstanding', '200000.00');
        $response->assertJsonCount(1, 'data.payments');
        $response->assertJsonCount(1, 'data.payments.0.transactions');
        $response->assertJsonPath('data.payments.0.transactions.0.status', 'success');
    }

    public function test_billing_detail_pending_transaction_does_not_count_toward_paid(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType(), ['amount' => 350000]);
        $payment = $this->createPayment($billing, ['amount' => 150000]);
        $this->createTransaction($payment, ['amount' => 150000, 'status' => 'success']);
        $this->createTransaction($payment, ['amount' => 50000, 'status' => 'pending']);

        $response = $this->getJson("/api/billings/{$billing->id}");

        $response->assertStatus(200);
        // Only the successful transaction counts toward the ledger-paid total.
        $response->assertJsonPath('data.paid', '150000.00');
        $response->assertJsonPath('data.outstanding', '200000.00');
        $response->assertJsonCount(2, 'data.payments.0.transactions');
    }

    public function test_guru_and_siswa_cannot_write_billings(): void
    {
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();

        $this->authenticateAsGuru();
        $this->postJson('/api/billings', $this->billingPayload($student, $feeType))->assertStatus(403);

        $this->authenticateAsSiswa();
        $this->postJson('/api/billings', $this->billingPayload($student, $feeType))->assertStatus(403);
    }

    // ─── PAYMENTS ──────────────────────────────────────────────

    private function paymentPayload(Billing $billing, array $overrides = []): array
    {
        return array_merge([
            'billing_id' => $billing->id,
            'student_id' => $billing->student_id,
            'payment_date' => '2026-09-05',
            'amount' => 100000,
            'method' => 'cash',
            'received_by' => $this->adminUserId,
            'notes' => 'PH7-Payment POST',
        ], $overrides);
    }

    private function transactionPayload(int $paymentId, array $overrides = []): array
    {
        return array_merge([
            'payment_id' => $paymentId,
            'transaction_code' => 'VTX-'.uniqid(),
            'type' => 'payment',
            'amount' => 100000,
            'method' => 'cash',
            'status' => 'success',
            'transaction_date' => '2026-09-05 09:00:00',
        ], $overrides);
    }

    public function test_payments_guest_list_returns_401(): void
    {
        $this->getJson('/api/payments')->assertStatus(401);
    }

    public function test_admin_creates_valid_payment(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());

        $response = $this->postJson('/api/payments', $this->paymentPayload($billing));

        $response->assertStatus(201);
        $this->assertItemEnvelope($response);
        $id = $response->json('data.id');
        $this->assertSame((int) $billing->student_id, (int) $this->dbValue('payments', $id, 'student_id'));
    }

    public function test_payment_validation_rules(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $otherStudent = $this->createTestStudent();
        $base = $this->paymentPayload($billing);

        $this->postJson('/api/payments', array_merge($base, ['billing_id' => 9999999]))
            ->assertStatus(422)->assertJsonValidationErrors(['billing_id']);
        $this->postJson('/api/payments', array_merge($base, ['student_id' => $otherStudent->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
        $this->postJson('/api/payments', array_merge($base, ['amount' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/payments', array_merge($base, ['method' => 'cheque']))
            ->assertStatus(422)->assertJsonValidationErrors(['method']);
    }

    public function test_payment_allows_each_valid_method(): void
    {
        $this->authenticateAsAdmin();
        foreach (['cash', 'transfer', 'qris', 'lainnya'] as $method) {
            $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
            $this->postJson('/api/payments', $this->paymentPayload($billing, ['method' => $method]))
                ->assertStatus(201);
        }
    }

    public function test_payment_soft_deleted_billing_rejected(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType);
        $billingId = $billing->id;
        $billing->delete();

        $this->postJson('/api/payments', array_merge($this->paymentPayload($billing), ['billing_id' => $billingId]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['billing_id']);
    }

    public function test_payment_exceeding_outstanding_rejected(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType, ['amount' => 200000]);

        $this->postJson('/api/payments', array_merge($this->paymentPayload($billing), ['amount' => 200000.01]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        // Exactly outstanding is accepted.
        $this->postJson('/api/payments', array_merge($this->paymentPayload($billing), ['amount' => 200000]))
            ->assertStatus(201);
    }

    public function test_payment_duplicate_and_nullable_reference(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();

        $billingA = $this->createBilling($student, $feeType, ['period_start' => '2026-09-01']);
        $billingB = $this->createBilling($student, $feeType, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31']);

        // Nullable reference allowed.
        $this->postJson('/api/payments', $this->paymentPayload($billingA))->assertStatus(201);

        $reference = 'PH7-REF-'.uniqid();
        $this->postJson('/api/payments', $this->paymentPayload($billingA, ['reference_number' => $reference]))->assertStatus(201);
        $this->postJson('/api/payments', $this->paymentPayload($billingB, ['reference_number' => $reference]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_number']);
    }

    public function test_payment_shows_billing_student_cashier(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);

        $response = $this->getJson("/api/payments/{$payment->id}");

        $this->assertItemEnvelope($response);
        $response->assertJsonStructure(['data' => ['billing', 'student', 'cashier']]);
    }

    public function test_payment_update_valid_and_partial(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);

        $response = $this->putJson("/api/payments/{$payment->id}", [
            'amount' => 120000,
            'method' => 'transfer',
        ]);

        $response->assertStatus(200);
        $this->assertSame('120000.00', $this->dbValue('payments', $payment->id, 'amount'));
        $this->assertSame('transfer', $this->dbValue('payments', $payment->id, 'method'));
    }

    public function test_payment_update_invalid_amount_rejected(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType(), ['amount' => 100000]);
        $payment = $this->createPayment($billing);

        $this->putJson("/api/payments/{$payment->id}", ['amount' => 100000.01])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_delete_without_ledger_history_allowed(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);

        $response = $this->deleteJson("/api/payments/{$payment->id}");

        $response->assertStatus(200);
        $this->assertNotNull($this->dbValue('payments', $payment->id, 'deleted_at'));
    }

    public function test_payment_delete_with_ledger_history_returns_409(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment);

        $response = $this->deleteJson("/api/payments/{$payment->id}");

        $response->assertStatus(409);
        $response->assertJson(['success' => false]);
        $this->assertNull($this->dbValue('payments', $payment->id, 'deleted_at'));
        $this->assertNotNull($this->dbValue('payment_transactions', $transaction->id, 'id'));
    }

    public function test_payment_delete_soft_deleted_transaction_still_protected(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment);
        $transaction->delete();

        $this->deleteJson("/api/payments/{$payment->id}")->assertStatus(409);
    }

    public function test_guru_and_siswa_cannot_write_payments(): void
    {
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());

        $this->authenticateAsGuru();
        $this->postJson('/api/payments', $this->paymentPayload($billing))->assertStatus(403);

        $this->authenticateAsSiswa();
        $this->postJson('/api/payments', $this->paymentPayload($billing))->assertStatus(403);
    }

    // ─── PAYMENT TRANSACTIONS ──────────────────────────────────

    public function test_transactions_guest_list_returns_401(): void
    {
        $this->getJson('/api/payment-transactions')->assertStatus(401);
    }

    public function test_transaction_create_reconciles_billing(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->postJson('/api/payments', $this->paymentPayload($billing))->json('data.id');

        $response = $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['amount' => 150000]));

        $response->assertStatus(201);
        $this->assertSame('partial', $this->billingStatus($billing->id));
        $this->assertSame('150000.00', $response->json('data.amount'));
        $this->assertStringStartsWith('VTX-', $response->json('data.transaction_code'));
    }

    public function test_transaction_ledger_sign_rules(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->postJson('/api/payments', $this->paymentPayload($billing))->json('data.id');

        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'payment', 'amount' => -10]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'refund', 'amount' => 100]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'adjustment', 'amount' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'adjustment', 'amount' => 25000]))
            ->assertStatus(201);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'adjustment', 'amount' => -25000]))
            ->assertStatus(201);
    }

    public function test_transaction_enum_validation(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->postJson('/api/payments', $this->paymentPayload($billing))->json('data.id');

        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'other']))
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['method' => 'cheque']))
            ->assertStatus(422)->assertJsonValidationErrors(['method']);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['status' => 'weird']))
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['transaction_code' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['transaction_code']);
    }

    public function test_transaction_duplicate_code_rejected(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->postJson('/api/payments', $this->paymentPayload($billing))->json('data.id');
        $code = 'VTX-DUP-'.uniqid();

        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['transaction_code' => $code]))
            ->assertStatus(201);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['transaction_code' => $code]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transaction_code']);
    }

    public function test_transaction_refund_cap(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->postJson('/api/payments', $this->paymentPayload($billing, ['amount' => 200000]))->json('data.id');
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['amount' => 200000]))
            ->assertStatus(201);

        // Equal to refundable → allowed.
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'refund', 'amount' => -200000]))
            ->assertStatus(201);

        // Exceed refundable (now 0) → rejected.
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'refund', 'amount' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_pending_and_failed_transactions_do_not_affect_billing_balance(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->postJson('/api/payments', $this->paymentPayload($billing))->json('data.id');

        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['amount' => 150000, 'status' => 'pending']))
            ->assertStatus(201);
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['amount' => 150000, 'status' => 'failed']))
            ->assertStatus(201);

        $this->assertSame('unpaid', $this->billingStatus($billing->id));

        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment, ['type' => 'refund', 'amount' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_transaction_update_status_and_amount_reconcile(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 150000]);
        $this->reconcileBilling($billing);
        $this->assertSame('partial', $this->billingStatus($billing->id));

        // pending → success / amount changes still reconcile.
        $this->putJson("/api/payment-transactions/{$transaction->id}", ['status' => 'pending'])
            ->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));

        $this->putJson("/api/payment-transactions/{$transaction->id}", ['status' => 'success', 'amount' => 350000])
            ->assertStatus(200);
        $this->assertSame('paid', $this->billingStatus($billing->id));
    }

    public function test_transaction_payment_id_move_reconciles_both_billings(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billingA = $this->createBilling($student, $feeType, ['amount' => 300000]);
        $billingB = $this->createBilling($student, $feeType, ['amount' => 300000]);
        $paymentA = $this->createPayment($billingA);
        $paymentB = $this->createPayment($billingB);
        $transaction = $this->createTransaction($paymentA, ['amount' => 150000]);
        $this->reconcileBilling($billingA);
        $this->assertSame('partial', $this->billingStatus($billingA->id));

        $this->putJson("/api/payment-transactions/{$transaction->id}", ['payment_id' => $paymentB->id])
            ->assertStatus(200);

        $this->assertSame('unpaid', $this->billingStatus($billingA->id));
        $this->assertSame('partial', $this->billingStatus($billingB->id));
    }

    public function test_transaction_delete_excludes_it_from_balance(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment, ['amount' => 350000]);
        $this->reconcileBilling($billing);
        $this->assertSame('paid', $this->billingStatus($billing->id));

        $response = $this->deleteJson("/api/payment-transactions/{$transaction->id}");

        $response->assertStatus(200);
        $this->assertSame('unpaid', $this->billingStatus($billing->id));
        $this->assertNotNull($this->dbValue('payment_transactions', $transaction->id, 'deleted_at'));
    }

    public function test_transaction_show_loads_payment(): void
    {
        $this->authenticateAsAdmin();
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);
        $transaction = $this->createTransaction($payment);

        $response = $this->getJson("/api/payment-transactions/{$transaction->id}");

        $this->assertItemEnvelope($response);
        $response->assertJsonStructure(['data' => ['payment']]);
    }

    public function test_guru_and_siswa_cannot_write_transactions(): void
    {
        $billing = $this->createBilling($this->createTestStudent(), $this->createTestFeeType());
        $payment = $this->createPayment($billing);

        $this->authenticateAsGuru();
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment->id))->assertStatus(403);

        $this->authenticateAsSiswa();
        $this->postJson('/api/payment-transactions', $this->transactionPayload($payment->id))->assertStatus(403);
    }

    // ─── SCHOLARSHIPS ──────────────────────────────────────────

    private function scholarshipPayload(Student $student, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $student->id,
            'name' => 'PH7-'.uniqid(),
            'provider' => 'PH7-Provider',
            'amount' => 500000,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'status' => 'aktif',
        ], $overrides);
    }

    public function test_scholarships_guest_list_returns_401(): void
    {
        $this->getJson('/api/scholarships')->assertStatus(401);
    }

    public function test_admin_lists_create_updates_and_deletes_scholarship(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();

        $list = $this->getJson('/api/scholarships');
        $this->assertListEnvelope($list);
        $list->assertJsonPath('meta.per_page', 15);

        $created = $this->postJson('/api/scholarships', $this->scholarshipPayload($student));
        $created->assertStatus(201);
        $this->assertItemEnvelope($created);
        $id = $created->json('data.id');
        $created->assertJsonStructure(['data' => ['student']]);
        $this->assertSame('aktif', $this->dbValue('scholarships', $id, 'status'));

        $updated = $this->putJson("/api/scholarships/{$id}", ['status' => 'selesai', 'amount' => 600000]);
        $updated->assertStatus(200);
        $this->assertSame('600000.00', $this->dbValue('scholarships', $id, 'amount'));

        $patched = $this->patchJson("/api/scholarships/{$id}", ['status' => 'dibatalkan']);
        $patched->assertStatus(200);
        $this->assertSame('dibatalkan', $this->dbValue('scholarships', $id, 'status'));

        $deleted = $this->deleteJson("/api/scholarships/{$id}");
        $deleted->assertStatus(200);
        $this->assertNotNull($this->dbValue('scholarships', $id, 'deleted_at'));
    }

    public function test_scholarship_validation(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();

        $this->postJson('/api/scholarships', $this->scholarshipPayload($student, ['student_id' => 9999999]))
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
        $this->postJson('/api/scholarships', $this->scholarshipPayload($student, ['start_date' => '2026-09-30', 'end_date' => '2026-09-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->postJson('/api/scholarships', $this->scholarshipPayload($student, ['amount' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/scholarships', $this->scholarshipPayload($student, ['status' => 'lunas']))
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_guru_and_siswa_cannot_write_scholarships(): void
    {
        $student = $this->createTestStudent();

        $this->authenticateAsGuru();
        $this->postJson('/api/scholarships', $this->scholarshipPayload($student))->assertStatus(403);

        $this->authenticateAsSiswa();
        $this->postJson('/api/scholarships', $this->scholarshipPayload($student))->assertStatus(403);
    }

    // ─── FINANCIAL REPORTS ─────────────────────────────────────

    private function reportPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'PH7-'.uniqid(),
            'report_type' => 'bulanan',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'notes' => 'PH7-Report',
        ], $overrides);
    }

    public function test_reports_guest_list_returns_401(): void
    {
        $this->getJson('/api/financial-reports')->assertStatus(401);
    }

    public function test_admin_generates_report_server_side(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType, [
            'amount' => 400000,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 250000]);
        $this->createTransaction($payment, ['type' => 'refund', 'amount' => -50000]);
        $this->createTransaction($payment, ['status' => 'pending', 'amount' => 100000]);

        $response = $this->postJson('/api/financial-reports', $this->reportPayload([
            'total_billed' => 999999,
            'total_paid' => 1,
            'total_outstanding' => 0,
            'generated_by' => 1,
        ]));

        $response->assertStatus(201);
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.total_billed', '400000.00');
        $response->assertJsonPath('data.total_paid', '200000.00');
        $response->assertJsonPath('data.total_outstanding', '200000.00');
        $response->assertJsonPath('data.generated_by', $this->adminUserId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('data.source_fingerprint'));
    }

    public function test_report_fingerprint_deterministic_and_sensitive_to_data(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType, ['amount' => 400000]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 250000]);

        $first = $this->postJson('/api/financial-reports', $this->reportPayload())->json('data.source_fingerprint');
        $second = $this->postJson('/api/financial-reports', $this->reportPayload(['title' => 'PH7-Other-'.uniqid()]))->json('data.source_fingerprint');
        $this->assertSame($first, $second);

        $this->createTransaction($payment, ['amount' => 150000]);
        $third = $this->postJson('/api/financial-reports', $this->reportPayload())->json('data.source_fingerprint');
        $this->assertNotSame($first, $third);
    }

    public function test_report_metadata_update_preserves_totals(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();
        $billing = $this->createBilling($student, $feeType, ['amount' => 400000]);
        $payment = $this->createPayment($billing);
        $this->createTransaction($payment, ['amount' => 250000]);

        $created = $this->postJson('/api/financial-reports', $this->reportPayload());
        $created->assertStatus(201);
        $id = $created->json('data.id');

        $response = $this->putJson("/api/financial-reports/{$id}", [
            'title' => 'PH7-Renamed',
            'total_billed' => 777,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'PH7-Renamed');
        $response->assertJsonPath('data.total_billed', '400000.00');
        $response->assertJsonPath('data.total_paid', '250000.00');
        $this->assertSame($created->json('data.source_fingerprint'), $response->json('data.source_fingerprint'));
    }

    public function test_admin_deletes_report(): void
    {
        $this->authenticateAsAdmin();
        $created = $this->postJson('/api/financial-reports', $this->reportPayload());
        $created->assertStatus(201);
        $id = $created->json('data.id');

        $this->deleteJson("/api/financial-reports/{$id}")->assertStatus(200);
        $this->assertNull($this->dbValue('financial_reports', $id, 'id'));
    }

    public function test_guru_and_siswa_cannot_write_reports(): void
    {
        $this->authenticateAsGuru();
        $this->postJson('/api/financial-reports', $this->reportPayload())->assertStatus(403);

        $this->authenticateAsSiswa();
        $this->postJson('/api/financial-reports', $this->reportPayload())->assertStatus(403);
    }

    // ─── SUMMARY ───────────────────────────────────────────────

    public function test_summary_envelope_and_shape(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/reports/finance/summary');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['totals' => ['total_billed', 'total_paid', 'total_outstanding'], 'per_fee_type', 'monthly_trend'],
        ]);
    }

    public function test_summary_date_filter_uses_one_consistent_scope(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent();
        $feeType = $this->createTestFeeType();

        $september = $this->createBilling($student, $feeType, [
            'amount' => 400000,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
        $septemberPayment = $this->createPayment($september);
        $this->createTransaction($septemberPayment, ['amount' => 150000]);

        $october = $this->createBilling($student, $feeType, [
            'amount' => 200000,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);
        $octoberPayment = $this->createPayment($october);
        $this->createTransaction($octoberPayment, [
            'amount' => 200000,
            'transaction_date' => '2026-10-05 09:00:00',
        ]);

        $septSummary = $this->getJson('/api/reports/finance/summary?date_from=2026-09-01&date_to=2026-09-30');
        $septSummary->assertStatus(200);
        $septSummary->assertJsonPath('data.totals.total_billed', 400000);
        $septSummary->assertJsonPath('data.totals.total_paid', 150000);
        $septSummary->assertJsonPath('data.totals.total_outstanding', 250000);
        collect($septSummary->json('data.monthly_trend'))->first(fn ($row) => $row['month'] === '2026-09' && $row['total_paid'] === 150000);
        $this->assertSame(150000, collect($septSummary->json('data.monthly_trend'))->firstWhere('month', '2026-09')['total_paid']);

        $octSummary = $this->getJson('/api/reports/finance/summary?date_from=2026-10-01&date_to=2026-10-31');
        $octSummary->assertStatus(200);
        $octSummary->assertJsonPath('data.totals.total_billed', 200000);
        $octSummary->assertJsonPath('data.totals.total_paid', 200000);
        $octSummary->assertJsonPath('data.totals.total_outstanding', 0);
        $this->assertSame(200000, collect($octSummary->json('data.monthly_trend'))->firstWhere('month', '2026-10')['total_paid']);
    }

    public function test_guru_can_read_summary(): void
    {
        $this->authenticateAsGuru();
        $this->getJson('/api/reports/finance/summary')->assertStatus(200);
    }
}
