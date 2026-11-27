<?php

namespace Tests\Feature;

use App\Auth\CompanyActor;
use App\Services\RecordInvoicePayment;
use App\Services\IssueInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RecordInvoicePaymentTest extends TestCase
{
    private CompanyActor $actor;

    private string $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $companyId = (string) Str::uuid();
        $interventionId = (string) Str::uuid();
        $this->invoiceId = (string) Str::uuid();
        $this->actor = new CompanyActor('17', $companyId, (string) Str::uuid(), 1, ['billing.payments.record'], (string) Str::uuid());
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        DB::table('billing_intervention_references')->insert(['company_id' => $companyId, 'intervention_id' => $interventionId, 'client_id' => (string) Str::uuid(), 'vehicle_id' => (string) Str::uuid()]);
        DB::table('billing_invoices')->insert([
            'id' => $this->invoiceId, 'company_id' => $companyId, 'intervention_id' => $interventionId,
            'number' => 1, 'subtotal_cents' => 10000, 'gst_cents' => 500, 'qst_cents' => 998,
            'total_cents' => 11498, 'issued_at' => now(), 'snapshot' => json_encode(['schemaVersion' => 1]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_invoice_sequences')->insert(['company_id' => $companyId, 'next_number' => 2]);
    }

    public function test_partial_payments_reduce_the_balance_and_retries_do_not_duplicate_them(): void
    {
        $service = app(RecordInvoicePayment::class);
        $paymentId = (string) Str::uuid();
        $payment = $service->record($this->actor, $paymentId, $this->invoiceId, 5000, 'cash');
        $retry = $service->record($this->actor, $paymentId, $this->invoiceId, 5000, 'cash');

        $this->assertSame(6498, $payment['balanceCents']);
        $this->assertSame($payment, $retry);
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertSame(0, $service->record($this->actor, (string) Str::uuid(), $this->invoiceId, 6498, 'debit')['balanceCents']);
    }

    public function test_a_payment_cannot_exceed_the_invoice_balance(): void
    {
        $this->expectException(ValidationException::class);
        app(RecordInvoicePayment::class)->record($this->actor, (string) Str::uuid(), $this->invoiceId, 11499, 'cash');
    }

    public function test_invoice_issue_freezes_integer_taxes_and_allocates_a_unique_number(): void
    {
        $interventionId = (string) Str::uuid();
        DB::table('billing_intervention_references')->insert([
            'company_id' => $this->actor->companyId, 'intervention_id' => $interventionId,
            'client_id' => (string) Str::uuid(), 'vehicle_id' => (string) Str::uuid(),
        ]);
        $invoiceId = (string) Str::uuid();
        $invoice = app(IssueInvoice::class)->issue($this->actor, $invoiceId, $interventionId, 10000, 5000, 9975, ['description' => 'Inspection']);

        $this->assertSame(500, $invoice['gstCents']);
        $this->assertSame(998, $invoice['qstCents']);
        $this->assertSame(11498, $invoice['totalCents']);
        $this->assertSame(2, $invoice['number']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoiceId, 'total_cents' => 11498]);
    }
}
