<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_intervention_invoice_locks', function (Blueprint $table): void {
            $table->uuid('company_id');
            $table->uuid('intervention_id');
            $table->uuid('invoice_id');
            $table->timestamp('created_at', 3);
            $table->primary(['company_id', 'intervention_id'], 'inventory_invoice_lock_pk');
            $table->unique(['company_id', 'invoice_id'], 'inventory_invoice_lock_invoice_unique');
            $table->foreign(['company_id', 'intervention_id'], 'inventory_invoice_lock_reference_fk')
                ->references(['company_id', 'intervention_id'])->on('inventory_intervention_references')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Invoice locks preserve the integrity of issued inventory snapshots.');
    }
};
