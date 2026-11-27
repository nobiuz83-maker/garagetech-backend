<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_intervention_references', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->uuid('intervention_id');
            $table->uuid('client_id');
            $table->uuid('vehicle_id');
            $table->primary(['company_id', 'intervention_id'], 'billing_intervention_reference_pk');
        });
        Schema::create('billing_invoice_sequences', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->primary()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
        });
        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('intervention_id');
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('gst_cents');
            $table->unsignedBigInteger('qst_cents');
            $table->unsignedBigInteger('total_cents');
            $table->timestamp('issued_at', 3);
            $table->json('snapshot');
            $table->timestamps(3);
            $table->foreign(['company_id', 'intervention_id'], 'billing_invoice_intervention_fk')
                ->references(['company_id', 'intervention_id'])->on('billing_intervention_references')->restrictOnDelete();
            $table->unique(['company_id', 'number'], 'billing_invoice_number_unique');
            $table->unique(['company_id', 'intervention_id'], 'billing_intervention_invoice_unique');
            $table->unique(['company_id', 'id'], 'billing_company_invoice_unique');
        });
        Schema::create('billing_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('invoice_id');
            $table->unsignedBigInteger('amount_cents');
            $table->string('method', 32);
            $table->timestamp('paid_at', 3);
            $table->timestamps(3);
            $table->foreign(['company_id', 'invoice_id'], 'billing_payment_invoice_fk')
                ->references(['company_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Invoices and payment history must be retained; use forward recovery.');
    }
};
