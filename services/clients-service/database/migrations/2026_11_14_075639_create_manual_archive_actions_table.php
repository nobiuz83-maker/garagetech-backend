<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_archive_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operation', 16);
            $table->uuid('reverses_action_id')->nullable()->unique();
            $table->foreign('reverses_action_id')->references('id')->on('manual_archive_actions')->restrictOnDelete();
            $table->string('operator', 1000);
            $table->string('reviewer', 1000);
            $table->text('reason');
            $table->string('ticket', 1000);
            $table->char('plan_sha256', 64);
            $table->json('records');
            $table->char('family_digest', 64);
            $table->timestamp('created_at', 3);
        });
    }

    public function down(): void
    {
        throw new LogicException('Manual decisions and rollback audit records must be retained.');
    }
};
