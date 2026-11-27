<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_subjects', function (Blueprint $table): void {
            $table->uuid('company_id')->primary();
            $table->string('status', 16);
            $table->unsignedBigInteger('directory_version');
        });
        Schema::create('company_security_policies', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->primary()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->json('data');
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

        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->nullable()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->index(['company_id', 'user_id', 'last_name', 'first_name'], 'clients_company_owner_name_index');
        });
        Schema::create('legacy_client_company_assignments', function (Blueprint $table): void {
            $table->foreignUuid('client_id')->primary()->constrained('clients')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        throw new LogicException('Tenant assignments must be retained; use forward recovery.');
    }
};
