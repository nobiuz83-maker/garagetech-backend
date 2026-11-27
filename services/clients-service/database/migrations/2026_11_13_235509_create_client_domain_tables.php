<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') || Schema::hasTable('sessions')) {
            throw new LogicException('Client domain migrations require an independent database without identity tables.');
        }

        Schema::create('owner_subjects', function (Blueprint $table): void {
            $table->unsignedBigInteger('subject_id')->primary();
        });
        Schema::create('clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('subject_id')->on('owner_subjects')->restrictOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->default('');
            $table->string('email')->default('');
            $table->json('data')->nullable();
            $table->timestamps(3);
            $table->softDeletes();
            $table->index(['user_id', 'last_name', 'first_name']);
        });
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->string('make');
            $table->string('model');
            $table->unsignedSmallInteger('year');
            $table->string('vin')->default('')->index();
            $table->string('license_plate')->default('')->index();
            $table->json('data')->nullable();
            $table->timestamps(3);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        throw new LogicException('Client domain rollback requires an export and forward recovery; stored records must be preserved.');
    }
};
