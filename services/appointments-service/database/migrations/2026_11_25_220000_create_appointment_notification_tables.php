<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_notification_settings', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->primary()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->unsignedBigInteger('owner_subject');
            $table->foreign('owner_subject')->references('subject_id')->on('owner_subjects')->restrictOnDelete();
            $table->json('settings');
            $table->timestamps(3);
            $table->index(['owner_subject', 'company_id']);
        });
        Schema::create('appointment_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->foreignUuid('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('channel', 10);
            $table->string('recipient', 255);
            $table->char('dedupe_key', 64)->unique();
            $table->json('payload');
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_reference', 255)->nullable();
            $table->timestamps(3);
            $table->index(['company_id', 'appointment_id', 'created_at'], 'appointment_notification_history');
        });
        Schema::create('appointment_cancellation_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->foreignUuid('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps(3);
        });
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
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
        throw new LogicException('Notification history and queue records must be retained; use forward recovery.');
    }
};
