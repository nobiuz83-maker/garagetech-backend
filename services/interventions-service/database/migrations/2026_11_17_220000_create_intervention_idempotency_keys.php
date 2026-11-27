<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervention_idempotency_keys', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->string('actor_subject', 20);
            $table->string('operation', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->json('response');
            $table->timestamp('created_at', 3);
            $table->primary(['company_id', 'actor_subject', 'operation', 'idempotency_key'], 'intervention_idempotency_pk');
        });
    }

    public function down(): void
    {
        throw new LogicException('Idempotency records must be retained to prevent duplicate operations.');
    }
};
