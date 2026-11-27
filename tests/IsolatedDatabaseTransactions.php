<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;

trait IsolatedDatabaseTransactions
{
    use DatabaseTransactions {
        beginDatabaseTransaction as private beginIsolatedTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        $connection = $this->app['db']->connection();
        $database = $connection->getDatabaseName();
        $isMemoryDatabase = $connection->getDriverName() === 'sqlite' && $database === ':memory:';
        $isNamedTestDatabase = $connection->getDriverName() === 'mysql' && str_starts_with($database, 'vicartech_test');
        if (! $isMemoryDatabase && ! $isNamedTestDatabase) {
            throw new RuntimeException('Database tests require SQLite :memory: or an isolated vicartech_test database.');
        }

        // Ordinary migrations create missing tables; each test rolls its data back without resetting the schema.
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->beginIsolatedTransaction();
    }
}
