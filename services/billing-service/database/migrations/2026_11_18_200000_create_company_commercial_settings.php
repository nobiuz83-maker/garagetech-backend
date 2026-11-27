<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_commercial_settings', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->primary()->constrained('company_subjects', 'company_id')->restrictOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->string('legal_form', 32);
            $table->boolean('gst_registered');
            $table->boolean('qst_registered');
            $table->unsignedInteger('gst_rate');
            $table->unsignedInteger('qst_rate');
            $table->date('tax_effective_date')->nullable();
            $table->string('invoice_prefix', 30)->default('');
            $table->json('profile');
            $table->longText('logo_data')->nullable();
            $table->string('logo_mime', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new LogicException('Commercial settings require a reviewed non-destructive rollback.');
    }
};
