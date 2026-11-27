<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unique(['id', 'user_id']);
        });
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->unique(['id', 'client_id']);
        });
        Schema::create('appointment_subjects', function (Blueprint $table): void {
            $table->uuid('appointment_id')->primary();
            $table->unsignedBigInteger('owner_subject_id');
            $table->foreign('owner_subject_id')->references('subject_id')->on('owner_subjects')->restrictOnDelete();
            $table->unique(['appointment_id', 'owner_subject_id']);
        });
        Schema::create('appointment_references', function (Blueprint $table): void {
            $table->id();
            $table->uuid('appointment_id');
            $table->uuid('client_id');
            $table->uuid('vehicle_id');
            $table->unsignedBigInteger('owner_subject_id');
            $table->foreign(['appointment_id', 'owner_subject_id'])->references(['appointment_id', 'owner_subject_id'])->on('appointment_subjects')->restrictOnDelete();
            $table->foreign(['client_id', 'owner_subject_id'])->references(['id', 'user_id'])->on('clients')->restrictOnDelete();
            $table->foreign(['vehicle_id', 'client_id'])->references(['id', 'client_id'])->on('vehicles')->restrictOnDelete();
            $table->timestamp('recorded_at', 3);
            $table->unique(['appointment_id', 'client_id', 'vehicle_id'], 'appointment_reference_identity');
        });
        Schema::create('archive_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->string('entity_type', 16);
            $table->uuid('entity_id')->index();
            $table->string('action', 16);
            $table->unsignedBigInteger('actor_subject_id');
            $table->foreign('actor_subject_id')->references('subject_id')->on('owner_subjects')->restrictOnDelete();
            $table->timestamp('occurred_at', 3);
            $table->index(['entity_type', 'entity_id', 'action']);
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    public function down(): void
    {
        throw new LogicException('Relationship and archive history must be retained; use forward recovery.');
    }
};
