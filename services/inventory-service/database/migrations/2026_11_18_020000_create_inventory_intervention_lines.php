<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_intervention_references', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->uuid('intervention_id');
            $table->uuid('client_id');
            $table->uuid('vehicle_id');
            $table->primary(['company_id', 'intervention_id'], 'inventory_intervention_reference_pk');
        });
        Schema::create('inventory_intervention_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('intervention_id');
            $table->uuid('product_id');
            $table->string('sku', 100);
            $table->string('name_fr');
            $table->string('name_en')->nullable();
            $table->unsignedBigInteger('quantity_units');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('total_cents');
            $table->timestamp('consumed_at', 3);
            $table->timestamp('reversed_at', 3)->nullable();
            $table->foreign(['company_id', 'intervention_id'], 'inventory_line_intervention_fk')
                ->references(['company_id', 'intervention_id'])->on('inventory_intervention_references')->restrictOnDelete();
            $table->foreign(['company_id', 'product_id'], 'inventory_line_product_fk')
                ->references(['company_id', 'id'])->on('inventory_products')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'inventory_company_line_unique');
            $table->index(['company_id', 'intervention_id'], 'inventory_intervention_lines_idx');
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->uuid('line_id')->nullable();
            $table->foreign(['company_id', 'line_id'], 'inventory_movement_line_fk')
                ->references(['company_id', 'id'])->on('inventory_intervention_lines')->restrictOnDelete();
            $table->unique(['company_id', 'line_id', 'type'], 'inventory_line_movement_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Consumption history must be retained; use forward recovery.');
    }
};
