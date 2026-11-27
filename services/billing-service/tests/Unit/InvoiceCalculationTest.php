<?php

namespace Tests\Unit;

use App\Services\InvoiceCalculation;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InvoiceCalculationTest extends TestCase
{
    public function test_preview_and_issue_use_the_same_integer_amounts_for_labor_parts_and_taxes(): void
    {
        $calculation = new InvoiceCalculation();
        $intervention = ['laborHours' => '1.5000', 'laborRateCents' => 12500];
        $parts = [['totalCents' => 1800]];

        $preview = $calculation->calculate($intervention, $parts, 5000, 9975, 'Synthetic terms');
        $issue = $calculation->totals($calculation->laborSubtotal($intervention) + $parts[0]['totalCents'], 5000, 9975, 'Synthetic terms');

        self::assertSame($preview, $issue);
        self::assertSame(['subtotalCents' => 20550, 'gstCents' => 1028, 'qstCents' => 2050, 'totalCents' => 23628], $preview);
    }

    public function test_missing_payment_terms_allow_a_draft_but_block_the_same_amount_at_issue(): void
    {
        $calculation = new InvoiceCalculation();
        $intervention = ['laborHours' => '1.0000', 'laborRateCents' => 50000];

        $draft = $calculation->calculate($intervention, [], 5000, 9975, null, false);
        self::assertSame(57488, $draft['totalCents']);

        $this->expectException(ValidationException::class);
        $calculation->calculate($intervention, [], 5000, 9975, null);
    }
}
