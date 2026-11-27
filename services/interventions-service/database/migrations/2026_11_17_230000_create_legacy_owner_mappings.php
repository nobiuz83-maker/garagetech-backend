<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervention_legacy_owner_mappings', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->primary()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->unsignedBigInteger('owner_subject_id')->unique();
            $table->timestamp('created_at', 3);
        });
    }

    public function down(): void
    {
        throw new LogicException('Owner mappings require a reviewed, non-destructive migration.');
    }
};
