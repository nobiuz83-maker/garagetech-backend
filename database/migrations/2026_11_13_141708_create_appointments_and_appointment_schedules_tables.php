<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->json('data');
            $table->timestamps(3);
        });
        Schema::create('appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('vehicle_id')->constrained()->restrictOnDelete();
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
    }

    public function down(): void
    {
        throw new LogicException('Appointment rollback requires an explicit backup and a forward migration to preserve records.');
    }
};
