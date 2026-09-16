<?php

namespace Tests\Feature\Finance;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Semester;
use App\Models\Finance\Billing;
use App\Models\Finance\FeeType;
use App\Models\Finance\FinancialReport;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentTransaction;
use App\Models\Finance\Scholarship;
use App\Models\Students\Student;
use App\Models\System\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2 — Finance model relationships & SoftDeletes verification.
 *
 * Asserts the model layer on the live MySQL database (same pattern as the rest
 * of the Feature suite: the default connection is switched to MySQL). Uses the
 * seeded Phase 1 data — model/relationship checks only, no business logic.
 */
class FinanceModelRelationshipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();


    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::connection()->hasColumn($table, $column);
    }

    private function deleteRule(string $table, string $fk): string
    {
        $row = $this->connection()
            ->table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $fk)
            ->first();

        return $row ? $row->DELETE_RULE : 'MISSING';
    }

    public function test_soft_deletes_on_finance_models(): void
    {
        $models = [
            Billing::class,
            Payment::class,
            PaymentTransaction::class,
            Scholarship::class,
        ];

        foreach ($models as $model) {
            $this->assertContains(
                SoftDeletes::class,
                class_uses_recursive($model),
                "{$model} does not use SoftDeletes."
            );

            $this->assertSame('deleted_at', (new $model)->getDeletedAtColumn());
        }
    }

    public function test_billing_payments_relationship(): void
    {
        $billing = Billing::with('payments')->first();
        $this->assertInstanceOf(HasMany::class, $billing->payments());
    }

    public function test_payment_billing_relationship(): void
    {
        $payment = Payment::with('billing')->first();
        $this->assertInstanceOf(BelongsTo::class, $payment->billing());
        $this->assertInstanceOf(Billing::class, $payment->billing()->getRelated());
    }

    public function test_payment_transactions_relationship(): void
    {
        $payment = Payment::with('transactions')->first();
        $this->assertInstanceOf(HasMany::class, $payment->transactions());
    }

    public function test_payment_transaction_payment_relationship(): void
    {
        $transaction = PaymentTransaction::with('payment')->first();
        $this->assertInstanceOf(BelongsTo::class, $transaction->payment());
        $this->assertInstanceOf(Payment::class, $transaction->payment()->getRelated());
    }

    public function test_billing_student_relationship(): void
    {
        $billing = Billing::with('student')->first();
        $this->assertInstanceOf(BelongsTo::class, $billing->student());
        $this->assertInstanceOf(Student::class, $billing->student()->getRelated());
    }

    public function test_billing_fee_type_relationship(): void
    {
        $billing = Billing::with('feeType')->first();
        $this->assertInstanceOf(BelongsTo::class, $billing->feeType());
        $this->assertInstanceOf(FeeType::class, $billing->feeType()->getRelated());
    }

    public function test_billing_academic_year_relationship(): void
    {
        $billing = Billing::with('academicYear')->first();
        $this->assertInstanceOf(BelongsTo::class, $billing->academicYear());
        $this->assertInstanceOf(AcademicYear::class, $billing->academicYear()->getRelated());
    }

    public function test_billing_semester_relationship(): void
    {
        $billing = Billing::with('semester')->first();
        $this->assertInstanceOf(BelongsTo::class, $billing->semester());
        $this->assertInstanceOf(Semester::class, $billing->semester()->getRelated());
    }

    public function test_student_billings_payments_scholarships(): void
    {
        $student = Student::first();
        $this->assertInstanceOf(HasMany::class, $student->billings());
        $this->assertInstanceOf(HasMany::class, $student->payments());
        $this->assertInstanceOf(HasMany::class, $student->scholarships());
    }

    public function test_fee_type_billings_relationship(): void
    {
        $feeType = FeeType::first();
        $this->assertInstanceOf(HasMany::class, $feeType->billings());
    }

    public function test_academic_year_billings_relationship(): void
    {
        $academicYear = AcademicYear::first();
        $this->assertInstanceOf(HasMany::class, $academicYear->billings());
    }

    public function test_semester_billings_relationship(): void
    {
        $semester = Semester::first();
        $this->assertInstanceOf(HasMany::class, $semester->billings());
    }

    public function test_user_student_profile(): void
    {
        $user = User::first();
        $this->assertInstanceOf(HasOne::class, $user->studentProfile());
        $this->assertInstanceOf(Student::class, $user->studentProfile()->getRelated());
    }

    public function test_financial_report_generator(): void
    {
        $report = FinancialReport::first();
        $this->assertInstanceOf(BelongsTo::class, $report->generator());
        $this->assertInstanceOf(User::class, $report->generator()->getRelated());
    }
}
