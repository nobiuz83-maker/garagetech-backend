<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_commercial_settings', function (Blueprint $table): void {
            $table->string('logo_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Company logo references require a reviewed non-destructive rollback.');
    }
};
