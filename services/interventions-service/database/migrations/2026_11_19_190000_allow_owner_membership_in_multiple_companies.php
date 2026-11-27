<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intervention_legacy_owner_mappings', function ($table): void {
            $table->dropUnique(['owner_subject_id']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Owner mappings must not be collapsed; use forward recovery.');
    }
};
