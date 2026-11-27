<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table): void {
            $table->text('address')->nullable();
            $table->json('address_details')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Saved intervention addresses are retained; use forward recovery.');
    }
};
