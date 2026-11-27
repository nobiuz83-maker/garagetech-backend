<?php

namespace App\Console\Commands;

use App\Maintenance\DomainImporter;
use App\Maintenance\DomainSnapshot;
use Illuminate\Console\Command;
use Throwable;

class ImportDomainSnapshot extends Command
{
    protected $signature = 'domain:import {snapshot} {--sha256=} {--apply} {--verify}';

    protected $description = 'Plan, import or verify an approved snapshot in this application’s own empty candidate database';

    public function handle(DomainImporter $importer): int
    {
        try {
            DomainSnapshot::require(! ($this->option('apply') && $this->option('verify')));
            $snapshot = DomainSnapshot::read($this->argument('snapshot'), (string) $this->option('sha256'));
            $receipt = $importer->run($snapshot, (bool) $this->option('apply'), (bool) $this->option('verify'));
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Import refused. Check the approved digest, candidate database, maintenance state and snapshot integrity.');

            return self::FAILURE;
        }
    }
}
