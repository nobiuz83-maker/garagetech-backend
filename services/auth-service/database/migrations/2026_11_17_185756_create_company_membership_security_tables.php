<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('display_name');
            $table->string('status', 16);
            $table->unsignedBigInteger('authorization_version')->default(1);
        });
        Schema::create('company_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16);
            $table->unique(['company_id', 'user_id']);
        });
        Schema::create('company_membership_roles', function (Blueprint $table): void {
            $table->foreignUuid('membership_id')->constrained('company_memberships')->restrictOnDelete();
            $table->string('role_id', 64);
            $table->timestamp('revoked_at')->nullable();
            $table->primary(['membership_id', 'role_id']);
        });
        Schema::create('company_permission_grants', function (Blueprint $table): void {
            $table->foreignUuid('membership_id')->constrained('company_memberships')->restrictOnDelete();
            $table->string('permission', 100);
            $table->timestamp('revoked_at')->nullable();
            $table->primary(['membership_id', 'permission']);
        });
        Schema::create('company_security_nonces', function (Blueprint $table): void {
            $table->string('caller', 64);
            $table->char('nonce_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->primary(['caller', 'nonce_hash']);
        });
        Schema::create('company_security_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('actor_subject', 20)->nullable();
            $table->uuid('request_id')->index();
            $table->string('action', 80);
            $table->json('details');
            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        throw new LogicException('Memberships and audits require a reviewed non-destructive rollback.');
    }
};
