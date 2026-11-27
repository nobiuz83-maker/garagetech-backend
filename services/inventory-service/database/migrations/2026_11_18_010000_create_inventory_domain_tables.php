<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->string('sku', 100);
            $table->string('name_fr');
            $table->string('name_en')->nullable();
            $table->unsignedBigInteger('purchase_price_cents');
            $table->unsignedBigInteger('sale_price_cents');
            $table->unsignedBigInteger('available_units')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(3);
            $table->unique(['company_id', 'sku'], 'inventory_company_sku_unique');
            $table->unique(['company_id', 'id'], 'inventory_company_product_unique');
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('product_id');
            $table->string('type', 20);
            $table->bigInteger('quantity_delta_units');
            $table->string('reason', 1000);
            $table->string('actor_subject', 20);
            $table->timestamp('occurred_at', 3);
            $table->foreign(['company_id', 'product_id'], 'inventory_movement_product_fk')
                ->references(['company_id', 'id'])->on('inventory_products')->restrictOnDelete();
            $table->index(['company_id', 'product_id', 'occurred_at'], 'inventory_product_history_idx');
        });

        Schema::create('inventory_idempotency_keys', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->string('actor_subject', 20);
            $table->string('operation', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->json('response');
            $table->timestamp('created_at', 3);
            $table->primary(['company_id', 'actor_subject', 'operation', 'idempotency_key'], 'inventory_idempotency_pk');
        });
    }

    public function down(): void
    {
        throw new LogicException('Inventory and movement history must be retained; use forward recovery.');
    }
};
