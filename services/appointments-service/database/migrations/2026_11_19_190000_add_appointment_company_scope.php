<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_subjects')) {
            Schema::create('company_subjects', function (Blueprint $table): void {
                $table->uuid('company_id')->primary();
                $table->string('status', 16);
                $table->unsignedBigInteger('directory_version');
            });
        }
        if (! Schema::hasTable('company_security_policies')) {
            Schema::create('company_security_policies', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->primary()->constrained('company_subjects', 'company_id')->restrictOnDelete();
                $table->unsignedBigInteger('version')->default(1);
                $table->json('data');
            });
        }
        if (! Schema::hasTable('company_security_nonces')) {
            Schema::create('company_security_nonces', function (Blueprint $table): void {
                $table->string('caller', 64);
                $table->char('nonce_hash', 64);
                $table->timestamp('expires_at')->index();
                $table->primary(['caller', 'nonce_hash']);
            });
        }
        if (! Schema::hasTable('company_security_audits')) {
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

        if (! Schema::hasColumn('appointments', 'company_id')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            });
        }
        if (! Schema::hasIndex('appointments', 'appointments_company_owner_date_index')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->index(['company_id', 'user_id', 'date'], 'appointments_company_owner_date_index');
            });
        }
        if (! Schema::hasIndex('appointment_schedules', 'appointment_schedules_user_id_index')) {
            Schema::table('appointment_schedules', function (Blueprint $table): void {
                // MariaDB requires a supporting index to remain when replacing the unique owner index.
                $table->index('user_id', 'appointment_schedules_user_id_index');
            });
        }
        if (Schema::hasIndex('appointment_schedules', 'appointment_schedules_user_id_unique')) {
            Schema::table('appointment_schedules', function (Blueprint $table): void {
                $table->dropUnique('appointment_schedules_user_id_unique');
            });
        }
        if (! Schema::hasColumn('appointment_schedules', 'company_id')) {
            Schema::table('appointment_schedules', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            });
        }
        if (! Schema::hasIndex('appointment_schedules', 'appointment_schedules_company_owner_unique')) {
            Schema::table('appointment_schedules', function (Blueprint $table): void {
                $table->unique(['company_id', 'user_id'], 'appointment_schedules_company_owner_unique');
            });
        }
        if (! Schema::hasColumn('client_vehicle_associations', 'company_id')) {
            Schema::table('client_vehicle_associations', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('legacy_appointment_company_assignments')) {
            Schema::create('legacy_appointment_company_assignments', function (Blueprint $table): void {
                $table->foreignUuid('appointment_id')->primary()->constrained('appointments')->restrictOnDelete();
                $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
                $table->timestamp('created_at');
                $table->index('company_id');
            });
        }
        if (! Schema::hasTable('legacy_schedule_company_assignments')) {
            Schema::create('legacy_schedule_company_assignments', function (Blueprint $table): void {
                $table->foreignId('schedule_id')->primary()->constrained('appointment_schedules')->restrictOnDelete();
                $table->foreignUuid('company_id')->constrained('company_subjects', 'company_id')->restrictOnDelete();
                $table->timestamp('created_at');
                $table->index('company_id');
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Tenant assignments must be retained; use forward recovery.');
    }
};
