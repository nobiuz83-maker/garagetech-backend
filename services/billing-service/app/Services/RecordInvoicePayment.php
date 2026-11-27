<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordInvoicePayment
{
    /** @return array{id: string, invoiceId: string, amountCents: int, balanceCents: int} */
    public function record(CompanyActor $actor, string $paymentId, string $invoiceId, int $amountCents, string $method): array
    {
        if ($amountCents < 1 || $amountCents > 9007199254740991 || ! in_array($method, ['cash', 'debit', 'credit', 'interac', 'bank', 'cheque', 'other'], true)) {
            throw ValidationException::withMessages(['payment' => 'PAYMENT_INVALID']);
        }

        return DB::transaction(function () use ($actor, $paymentId, $invoiceId, $amountCents, $method): array {
            $invoice = DB::table('billing_invoices')->where('company_id', $actor->companyId)->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null) {
                throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
            }
            $existing = DB::table('billing_payments')->where('id', $paymentId)->first();
            if ($existing !== null && ($existing->company_id !== $actor->companyId || $existing->invoice_id !== $invoiceId
                || (int) $existing->amount_cents !== $amountCents || $existing->method !== $method)) {
                throw new SecurityFailure('IDEMPOTENCY_CONFLICT', 409);
            }
            $paidCents = (int) DB::table('billing_payments')->where('company_id', $actor->companyId)->where('invoice_id', $invoiceId)->sum('amount_cents');
            $balanceCents = (int) $invoice->total_cents - $paidCents;
            if ($existing === null) {
                if ($amountCents > $balanceCents) {
                    throw ValidationException::withMessages(['amountCents' => 'PAYMENT_EXCEEDS_BALANCE']);
                }
                DB::table('billing_payments')->insert([
                    'id' => $paymentId, 'company_id' => $actor->companyId, 'invoice_id' => $invoiceId,
                    'amount_cents' => $amountCents, 'method' => $method, 'paid_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $balanceCents -= $amountCents;
            }

            return ['id' => $paymentId, 'invoiceId' => $invoiceId, 'amountCents' => $amountCents, 'balanceCents' => $balanceCents];
        });
    }
}
