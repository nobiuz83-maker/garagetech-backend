<?php

namespace App\Services;

use App\Auth\SecurityFailure;
use Illuminate\Validation\ValidationException;

final class InvoiceCalculation
{
    /** @param array<string, mixed> $intervention
     * @param list<array<string, mixed>> $parts
     * @return array{subtotalCents: int, gstCents: int, qstCents: int, totalCents: int}
     */
    public function calculate(array $intervention, array $parts, int $gstRate, int $qstRate, ?string $paymentTerms, bool $requiresPaymentTerms = true): array
    {
        $subtotalCents = $this->laborSubtotal($intervention);
        foreach ($parts as $part) {
            if ($part['totalCents'] > 10000000000 - $subtotalCents) {
                throw new SecurityFailure('INVOICE_AMOUNT_INVALID', 422);
            }
            $subtotalCents += $part['totalCents'];
        }

        return $this->totals($subtotalCents, $gstRate, $qstRate, $paymentTerms, $requiresPaymentTerms);
    }

    /** @param array<string, mixed> $intervention */
    public function laborSubtotal(array $intervention): int
    {
        $laborUnits = (int) str_replace('.', '', $intervention['laborHours']);
        $rateCents = $intervention['laborRateCents'];
        if ($rateCents !== 0 && $laborUnits > intdiv(PHP_INT_MAX - 5000, $rateCents)) {
            throw new SecurityFailure('INVOICE_AMOUNT_INVALID', 422);
        }
        $subtotalCents = intdiv($laborUnits * $rateCents + 5000, 10000);
        if ($subtotalCents > 10000000000) {
            throw new SecurityFailure('INVOICE_AMOUNT_INVALID', 422);
        }

        return $subtotalCents;
    }

    /** @return array{subtotalCents: int, gstCents: int, qstCents: int, totalCents: int} */
    public function totals(int $subtotalCents, int $gstRate, int $qstRate, ?string $paymentTerms, bool $requiresPaymentTerms = true): array
    {
        if ($subtotalCents < 0 || $subtotalCents > 10000000000 || $gstRate < 0 || $gstRate > 100000 || $qstRate < 0 || $qstRate > 100000) {
            throw ValidationException::withMessages(['invoice' => 'INVOICE_AMOUNT_INVALID']);
        }
        $gstCents = intdiv($subtotalCents * $gstRate + 50000, 100000);
        $qstCents = intdiv($subtotalCents * $qstRate + 50000, 100000);
        $totalCents = $subtotalCents + $gstCents + $qstCents;
        if ($requiresPaymentTerms && $totalCents >= 50000 && ! filled($paymentTerms)) {
            throw ValidationException::withMessages(['details.paymentTerms' => 'PAYMENT_TERMS_REQUIRED']);
        }

        return compact('subtotalCents', 'gstCents', 'qstCents', 'totalCents');
    }
}
