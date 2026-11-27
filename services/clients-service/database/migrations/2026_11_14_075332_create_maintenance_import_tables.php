<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_locks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('maintenance_locks')->insert(['id' => 1]);
        Schema::create('maintenance_imports', function (Blueprint $table): void {
            $table->uuid('migration_id')->primary();
            $table->char('payload_sha256', 64);
            $table->char('source_sha256', 64);
            $table->char('reference_digest', 64);
            $table->json('counts');
            $table->timestamp('completed_at', 3);
        });
    }

    public function down(): void
    {
        throw new LogicException('Maintenance receipts and imported records must be retained.');
    }
};
