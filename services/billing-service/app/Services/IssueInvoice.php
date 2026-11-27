<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Support\Facades\DB;

final class IssueInvoice
{
    public function __construct(private InvoiceCalculation $calculation) {}
    /**
     * The intervention reference must already have been verified with its owning service.
     * Rates are expressed in hundred-thousandths (5% = 5000, 9.975% = 9975).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function issue(CompanyActor $actor, string $invoiceId, string $interventionId, int $subtotalCents, int $gstRate, int $qstRate, array $snapshot): array
    {
        return DB::transaction(function () use ($actor, $invoiceId, $interventionId, $subtotalCents, $gstRate, $qstRate, $snapshot): array {
            $company = DB::table('company_subjects')->where('company_id', $actor->companyId)->lockForUpdate()->first();
            if ($company === null || $company->status !== 'active') {
                throw new SecurityFailure('MIGRATION_NOT_READY');
            }
            if (! DB::table('billing_intervention_references')->where('company_id', $actor->companyId)->where('intervention_id', $interventionId)->exists()) {
                throw new SecurityFailure('INTERVENTION_REFERENCE_REQUIRED', 422);
            }
            if (DB::table('billing_invoices')->where('id', $invoiceId)->orWhere(fn ($query) => $query->where('company_id', $actor->companyId)->where('intervention_id', $interventionId))->exists()) {
                throw new SecurityFailure('INVOICE_ALREADY_EXISTS', 409);
            }
            DB::table('billing_invoice_sequences')->insertOrIgnore(['company_id' => $actor->companyId, 'next_number' => 1]);
            $sequence = DB::table('billing_invoice_sequences')->where('company_id', $actor->companyId)->lockForUpdate()->first();
            $number = (int) $sequence->next_number;
            $totals = $this->calculation->totals($subtotalCents, $gstRate, $qstRate, $snapshot['details']['paymentTerms'] ?? null, isset($snapshot['details']));
            $gstCents = $totals['gstCents'];
            $qstCents = $totals['qstCents'];
            $totalCents = $totals['totalCents'];
            $issuedAt = now();
            // Earlier invoices remain untouched; version 3 adds frozen inventory lines.
            $document = ['schemaVersion' => array_key_exists('parts', $snapshot) ? 3 : (isset($snapshot['details']) ? 2 : 1), 'gstRate' => $gstRate, 'qstRate' => $qstRate, 'document' => $snapshot];
            DB::table('billing_invoices')->insert([
                'id' => $invoiceId, 'company_id' => $actor->companyId, 'intervention_id' => $interventionId, 'number' => $number,
                'subtotal_cents' => $subtotalCents, 'gst_cents' => $gstCents, 'qst_cents' => $qstCents, 'total_cents' => $totalCents,
                'issued_at' => $issuedAt, 'snapshot' => json_encode($document, JSON_THROW_ON_ERROR), 'created_at' => $issuedAt, 'updated_at' => $issuedAt,
            ]);
            DB::table('billing_invoice_sequences')->where('company_id', $actor->companyId)->update(['next_number' => $number + 1]);

            return ['id' => $invoiceId, 'number' => $number, 'subtotalCents' => $subtotalCents, 'gstCents' => $gstCents, 'qstCents' => $qstCents, 'totalCents' => $totalCents];
        });
    }
}
