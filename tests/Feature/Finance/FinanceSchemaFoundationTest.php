<?php

namespace Tests\Feature\Finance;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 — Finance DB foundation schema verification.
 *
 * Asserts the applied schema on the live MySQL database (same pattern as the
 * rest of the Feature suite: the default connection is switched to MySQL).
 * Requires `php artisan migrate` to have been run (phase 1 migrations).
 * These are schema/data checks only — no business logic is exercised here.
 */
class FinanceSchemaFoundationTest extends TestCase
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

    private function hasIndex(string $table, string $index): bool
    {
        return $this->connection()
            ->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    private function isIndexUnique(string $table, string $index): bool
    {
        return $this->connection()
            ->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->where('NON_UNIQUE', 0)
            ->exists();
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

    public function test_billings_columns_exist(): void
    {
        foreach (['period_start', 'period_end', 'uniq_key', 'deleted_at'] as $column) {
            $this->assertTrue($this->hasColumn('billings', $column), "billings.{$column} missing");
        }
    }

    public function test_billings_unique_and_indexes(): void
    {
        $this->assertTrue($this->hasIndex('billings', 'uniq_billings_uniqkey'), 'uniq_billings_uniqkey missing');
        $this->assertTrue($this->isIndexUnique('billings', 'uniq_billings_uniqkey'), 'uniq_billings_uniqkey not unique');
        $this->assertFalse($this->hasIndex('billings', 'uniq_billings'), 'legacy uniq_billings still present');
        $this->assertTrue($this->hasIndex('billings', 'billings_student_idx'), 'billings_student_idx missing');
        $this->assertTrue($this->hasIndex('billings', 'billings_fee_type_idx'), 'billings_fee_type_idx missing');
    }

    public function test_billings_fk_delete_rules(): void
    {
        $this->assertSame('RESTRICT', $this->deleteRule('billings', 'fk_bill_student'));
        $this->assertSame('RESTRICT', $this->deleteRule('billings', 'fk_bill_feetype'));
        $this->assertSame('RESTRICT', $this->deleteRule('billings', 'fk_bill_ay'));
        $this->assertSame('SET NULL', $this->deleteRule('billings', 'fk_bill_semester'));
    }

    public function test_payments_columns_indexes_and_fk(): void
    {
        $this->assertTrue($this->hasColumn('payments', 'ref_key'));
        $this->assertTrue($this->hasColumn('payments', 'deleted_at'));
        $this->assertTrue($this->hasIndex('payments', 'uniq_payments_refkey'));
        $this->assertTrue($this->isIndexUnique('payments', 'uniq_payments_refkey'));
        $this->assertSame('RESTRICT', $this->deleteRule('payments', 'fk_pay_billing'));
        $this->assertSame('RESTRICT', $this->deleteRule('payments', 'fk_pay_student'));
        $this->assertSame('SET NULL', $this->deleteRule('payments', 'fk_pay_user'));
    }

    public function test_payment_transactions_column_and_fk(): void
    {
        $this->assertTrue($this->hasColumn('payment_transactions', 'deleted_at'));
        $this->assertSame('RESTRICT', $this->deleteRule('payment_transactions', 'fk_ptx_payment'));
    }

    public function test_financial_reports_fingerprint_column(): void
    {
        $this->assertTrue($this->hasColumn('financial_reports', 'source_fingerprint'));
    }

    public function test_scholarships_column_and_fk(): void
    {
        $this->assertTrue($this->hasColumn('scholarships', 'deleted_at'));
        $this->assertSame('RESTRICT', $this->deleteRule('scholarships', 'fk_sch_student'));
    }

    public function test_billing_period_backfill_and_uniq_keys(): void
    {
        $billing = $this->connection()->table('billings')->where('id', 101)->first();

        $this->assertSame('2026-08-01', $billing->period_start);
        $this->assertSame('2026-08-31', $billing->period_end);
        $this->assertSame('52|101|2026-08-01|2026-08-31', $billing->uniq_key);

        $cancelled = $this->connection()->table('billings')->where('id', 105)->first();
        $this->assertNull($cancelled->uniq_key);

        $violations = $this->connection()
            ->table('billings')
            ->selectRaw('uniq_key, COUNT(*) AS total')
            ->whereNotNull('uniq_key')
            ->groupBy('uniq_key')
            ->having('total', '>', 1)
            ->count();

        $this->assertSame(0, $violations, 'duplicate uniq_key detected');
    }

    public function test_refund_transactions_are_signed_negative(): void
    {
        $positiveRefunds = $this->connection()
            ->table('payment_transactions')
            ->where('type', 'refund')
            ->where('amount', '>=', 0)
            ->count();

        $this->assertSame(0, $positiveRefunds, 'refund transaction with non-negative amount');
    }

    public function test_payment_ref_keys_backfilled(): void
    {
        $mismatches = $this->connection()
            ->table('payments')
            ->whereNotNull('reference_number')
            ->whereNull('ref_key')
            ->count();

        $this->assertSame(0, $mismatches, 'payment with reference_number but no ref_key');
    }

    public function test_existing_row_counts_are_preserved(): void
    {
        $this->assertSame(5, $this->connection()->table('billings')->count());
        $this->assertSame(3, $this->connection()->table('payments')->count());
        $this->assertSame(4, $this->connection()->table('payment_transactions')->count());
        $this->assertSame(3, $this->connection()->table('financial_reports')->count());
        $this->assertSame(5, $this->connection()->table('scholarships')->count());
    }
}
