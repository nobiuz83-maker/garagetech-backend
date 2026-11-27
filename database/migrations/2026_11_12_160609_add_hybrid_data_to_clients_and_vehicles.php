<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')->whereNotNull('address')->orderBy('id')->chunkById(100, function ($clients): void {
            foreach ($clients as $client) {
                json_decode($client->address, true, 512, JSON_THROW_ON_ERROR);
            }
        });
        foreach (['clients', 'vehicles'] as $tableName) {
            if (Schema::hasColumn($tableName, 'data')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('data')->nullable();
            });
        }
        DB::table('clients')->whereNotNull('address')->orderBy('id')->chunkById(100, function ($clients): void {
            foreach ($clients as $client) {
                $address = json_decode($client->address, true, 512, JSON_THROW_ON_ERROR);
                DB::table('clients')->where('id', $client->id)->whereNull('data')->update([
                    'data' => json_encode(['schemaVersion' => 1, 'address' => $address], JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Retain documents: a rollback must not discard newly collected information.
        throw new LogicException('Hybrid data rollback requires an explicit export and forward recovery migration.');
    }
};
