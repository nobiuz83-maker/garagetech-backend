<?php

namespace App\Console\Commands;

use App\Maintenance\DomainSnapshot;
use App\Maintenance\LegacyArchiveRestoration;
use Illuminate\Console\Command;
use stdClass;
use Throwable;

class RestoreLegacyArchives extends Command
{
    protected $signature = 'archives:restore {plan} {--sha256=} {--apply}';

    protected $description = 'Review an explicitly selected legacy restoration or guarded rollback; defaults to no writes';

    public function handle(LegacyArchiveRestoration $restoration): int
    {
        try {
            $bytes = file_get_contents($this->argument('plan'));
            DomainSnapshot::require(is_string($bytes) && hash_equals((string) $this->option('sha256'), hash('sha256', $bytes)));
            $plan = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
            DomainSnapshot::require($plan instanceof stdClass);
            $this->line(json_encode($restoration->run($plan, hash('sha256', $bytes), (bool) $this->option('apply')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Restoration refused. Verify the reviewed plan, current state, parent status and maintenance authorization.');

            return self::FAILURE;
        }
    }
}
