<?php

namespace App\Http\Controllers\Api;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Requests\PreviewInvoiceRequest;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Services\CompanyCommercialSettings;
use App\Services\FreezeBillingInventory;
use App\Services\IdempotentBillingOperation;
use App\Services\InvoiceCalculation;
use App\Services\IssueInvoice;
use App\Services\RecordInvoicePayment;
use App\Services\VerifyBillingIntervention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InvoiceController
{
    public function index(Request $request): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $invoices = DB::table('billing_invoices')->where('company_id', $actor->companyId)
            ->orderByDesc('issued_at')->orderBy('id')->paginate(50);

        return response()->json([
            'data' => $invoices->getCollection()->map(fn ($invoice): array => (new InvoiceResource($invoice))->resolve($request))->all(),
            'meta' => ['currentPage' => $invoices->currentPage(), 'lastPage' => $invoices->lastPage(), 'total' => $invoices->total()],
        ]);
    }

    public function preview(PreviewInvoiceRequest $request, VerifyBillingIntervention $references, FreezeBillingInventory $inventory, InvoiceCalculation $calculation, CompanyCommercialSettings $settings): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $input = $settings->applyToInvoice($actor->companyId, $request->validated(), true);
        $intervention = $references->verify($request, $actor, $input['interventionId']);
        $parts = $inventory->preview($request, $actor, $input['interventionId'], $calculation->laborSubtotal($intervention));
        $totals = $calculation->calculate($intervention, $parts, $input['gstRate'], $input['qstRate'], $input['details']['paymentTerms'], false);
        $document = $this->document($input, $intervention, $parts);

        return response()->json(['data' => [
            'interventionId' => $input['interventionId'], ...$totals,
            'snapshot' => ['schemaVersion' => 3, 'gstRate' => $input['gstRate'], 'qstRate' => $input['qstRate'], 'document' => $document],
            'previewHash' => $this->previewHash($document, $totals, $input),
        ]]);
    }

    public function store(IssueInvoiceRequest $request, VerifyBillingIntervention $references, FreezeBillingInventory $inventory, IdempotentBillingOperation $idempotency, IssueInvoice $issuer, InvoiceCalculation $calculation, CompanyCommercialSettings $settings): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $previous = $idempotency->findCompleted($actor, 'invoice.issue', $request->header('Idempotency-Key'), $request->getContent());
        if ($previous !== null) {
            return response()->json(['data' => $previous], 201);
        }
        $input = $settings->applyToInvoice($actor->companyId, $request->validated(), false);
        if (DB::table('billing_invoices')->where('id', $input['id'])->orWhere(fn ($query) => $query->where('company_id', $actor->companyId)->where('intervention_id', $input['interventionId']))->exists()) {
            throw new SecurityFailure('INVOICE_ALREADY_EXISTS', 409);
        }
        $intervention = $references->verify($request, $actor, $input['interventionId']);
        $expectedLinesHash = null;
        if (isset($input['previewHash'])) {
            $previewParts = $inventory->preview($request, $actor, $input['interventionId'], $calculation->laborSubtotal($intervention));
            $previewTotals = $calculation->calculate($intervention, $previewParts, $input['gstRate'], $input['qstRate'], $input['details']['paymentTerms']);
            if (! hash_equals($input['previewHash'], $this->previewHash($this->document($input, $intervention, $previewParts), $previewTotals, $input))) {
                throw new SecurityFailure('INVOICE_PREVIEW_CHANGED', 409);
            }
            $expectedLinesHash = hash('sha256', json_encode($previewParts, JSON_THROW_ON_ERROR));
        }
        $parts = $inventory->freeze($request, $actor, $input['interventionId'], $input['id'], $calculation->laborSubtotal($intervention), $expectedLinesHash);
        $totals = $calculation->calculate($intervention, $parts, $input['gstRate'], $input['qstRate'], $input['details']['paymentTerms']);
        $result = $idempotency->execute($actor, 'invoice.issue', $request->header('Idempotency-Key'), $request->getContent(), function () use ($actor, $input, $intervention, $totals, $parts, $issuer): array {
            DB::table('billing_intervention_references')->upsert([[
                'company_id' => $actor->companyId, 'intervention_id' => $input['interventionId'],
                'client_id' => $intervention['clientId'], 'vehicle_id' => $intervention['vehicleId'],
            ]], ['company_id', 'intervention_id'], ['intervention_id']);

            return $issuer->issue($actor, $input['id'], $input['interventionId'], $totals['subtotalCents'], $input['gstRate'], $input['qstRate'], $this->document($input, $intervention, $parts));
        });

        return response()->json(['data' => $result], 201);
    }

    public function show(Request $request, string $invoice): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $record = $this->invoice($actor, $invoice);
        $payments = DB::table('billing_payments')->where('company_id', $actor->companyId)->where('invoice_id', $invoice)->orderBy('paid_at')->orderBy('id')->get();
        $paidCents = $payments->sum(fn ($payment): int => (int) $payment->amount_cents);

        return response()->json(['data' => [...(new InvoiceResource($record))->resolve($request),
            'paidCents' => $paidCents, 'balanceCents' => (int) $record->total_cents - $paidCents,
            'payments' => $payments->map(fn ($payment): array => [
                'id' => $payment->id, 'amountCents' => (int) $payment->amount_cents,
                'method' => $payment->method, 'paidAt' => $payment->paid_at,
            ])->all(),
        ]]);
    }

    public function payment(RecordPaymentRequest $request, string $invoice, IdempotentBillingOperation $idempotency, RecordInvoicePayment $payments): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $input = $request->validated();
        $result = $idempotency->execute($actor, 'payment.'.$invoice, $request->header('Idempotency-Key'), $request->getContent(),
            fn (): array => $payments->record($actor, $input['id'], $invoice, $input['amountCents'], $input['method']));

        return response()->json(['data' => $result], 201);
    }

    private function invoice(CompanyActor $actor, string $invoice): object
    {
        $record = DB::table('billing_invoices')->where('company_id', $actor->companyId)->where('id', $invoice)->first();
        if ($record === null) {
            throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
        }

        return $record;
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $intervention
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    private function document(array $input, array $intervention, array $parts): array
    {
        return ['sellerName' => $input['sellerName'], 'customerName' => $input['customerName'],
            'vehicleDescription' => $input['vehicleDescription'], 'intervention' => $intervention,
            'details' => $input['details'], 'parts' => $parts];
    }

    /** @param array<string, mixed> $document
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $input
     */
    private function previewHash(array $document, array $totals, array $input): string
    {
        return hash('sha256', json_encode([$document, $totals, $input['gstRate'], $input['qstRate']], JSON_THROW_ON_ERROR));
    }
}
