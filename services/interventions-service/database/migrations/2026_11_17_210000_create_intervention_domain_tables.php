<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These references must be populated only after verification with the owning services.
        Schema::create('intervention_client_vehicle_references', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->uuid('client_id');
            $table->uuid('vehicle_id');
            $table->primary(['company_id', 'client_id', 'vehicle_id'], 'intervention_client_vehicle_reference_pk');
        });

        Schema::create('intervention_appointment_references', function (Blueprint $table): void {
            $table->uuid('company_id');
            $table->uuid('client_id');
            $table->uuid('vehicle_id');
            $table->uuid('appointment_id');
            $table->primary(['company_id', 'client_id', 'vehicle_id', 'appointment_id'], 'intervention_appointment_reference_pk');
            $table->foreign(['company_id', 'client_id', 'vehicle_id'], 'intervention_appointment_owner_fk')
                ->references(['company_id', 'client_id', 'vehicle_id'])->on('intervention_client_vehicle_references')->restrictOnDelete();
        });

        Schema::create('interventions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('client_id');
            $table->uuid('vehicle_id');
            $table->uuid('appointment_id')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20);
            $table->text('description');
            $table->text('diagnosis');
            $table->text('notes');
            $table->unsignedBigInteger('labor_rate_cents');
            $table->decimal('labor_hours', 12, 4);
            $table->unsignedInteger('version')->default(1);
            $table->json('data');
            $table->timestamps(3);
            $table->foreign(['company_id', 'client_id', 'vehicle_id'], 'intervention_client_vehicle_fk')
                ->references(['company_id', 'client_id', 'vehicle_id'])->on('intervention_client_vehicle_references')->restrictOnDelete();
            $table->foreign(['company_id', 'client_id', 'vehicle_id', 'appointment_id'], 'intervention_appointment_fk')
                ->references(['company_id', 'client_id', 'vehicle_id', 'appointment_id'])->on('intervention_appointment_references')->restrictOnDelete();
            $table->index(['company_id', 'start_date'], 'intervention_company_date_idx');
            $table->index(['company_id', 'status', 'start_date'], 'intervention_company_status_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Intervention history must be retained; use forward recovery.');
    }
};
