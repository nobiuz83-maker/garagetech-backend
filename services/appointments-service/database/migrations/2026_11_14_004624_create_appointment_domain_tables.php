<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') || Schema::hasTable('clients')) {
            throw new LogicException('Appointments requires an independent database without identity or client tables.');
        }
        Schema::create('owner_subjects', function (Blueprint $table): void {
            $table->unsignedBigInteger('subject_id')->primary();
        });
        Schema::create('client_vehicle_associations', function (Blueprint $table): void {
            $table->uuid('client_id');
            $table->uuid('vehicle_id');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('subject_id')->on('owner_subjects')->restrictOnDelete();
            $table->primary(['client_id', 'vehicle_id', 'user_id'], 'local_association_identity');
            $table->unique(['client_id', 'vehicle_id']);
        });
        Schema::create('appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->uuid('client_id');
            $table->uuid('vehicle_id');
            $table->foreign(['client_id', 'vehicle_id', 'user_id'], 'appointment_local_association')->references(['client_id', 'vehicle_id', 'user_id'])->on('client_vehicle_associations')->restrictOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status', 20);
            $table->string('service_type');
            $table->text('description');
            $table->text('notes');
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(3);
            $table->softDeletes();
            $table->index(['user_id', 'date', 'start_time']);
            $table->index(['user_id', 'status', 'date']);
        });
        Schema::create('appointment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->foreign('user_id')->references('subject_id')->on('owner_subjects')->restrictOnDelete();
            $table->json('data');
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        throw new LogicException('Appointment history must be retained; use forward recovery.');
    }
};
