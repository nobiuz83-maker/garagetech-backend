<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class CopyCompanySettings extends Command
{
    protected $signature = 'billing:copy-company-settings {source-company-id} {target-company-id} {owner-subject-id} {--expected-trade-name=ViCarTech}';

    protected $description = 'Copy a verified ViCarTech commercial settings row to a new company without changing its source';

    public function handle(): int
    {
        if (DB::connection()->getDatabaseName() !== 'vicartech_integration_billing' && ! app()->environment('testing')) {
            $this->error('Company settings copy is restricted to billing_db.');

            return self::FAILURE;
        }

        $sourceCompanyId = (string) $this->argument('source-company-id');
        $targetCompanyId = (string) $this->argument('target-company-id');
        $ownerSubjectId = filter_var($this->argument('owner-subject-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $expectedTradeName = trim((string) $this->option('expected-trade-name'));
        if (! Str::isUuid($sourceCompanyId) || ! Str::isUuid($targetCompanyId) || $sourceCompanyId === $targetCompanyId
            || $ownerSubjectId === false || $expectedTradeName === '') {
            $this->error('Provide distinct company UUIDs and a valid owner subject ID.');

            return self::FAILURE;
        }

        try {
            $copied = DB::transaction(function () use ($sourceCompanyId, $targetCompanyId, $ownerSubjectId, $expectedTradeName): bool {
                $source = DB::table('company_commercial_settings')->where('company_id', $sourceCompanyId)->lockForUpdate()->first();
                if ($source === null || (json_decode($source->profile, true, 512, JSON_THROW_ON_ERROR)['tradeName'] ?? null) !== $expectedTradeName) {
                    throw new RuntimeException('The source settings do not match the verified ViCarTech trade name.');
                }
                $targetCompany = DB::table('company_subjects')->where('company_id', $targetCompanyId)->lockForUpdate()->first();
                if ($targetCompany === null || $targetCompany->status !== 'active') {
                    throw new RuntimeException('The target company projection is not active in BillingService.');
                }

                $target = DB::table('company_commercial_settings')->where('company_id', $targetCompanyId)->lockForUpdate()->first();
                $values = [
                    'version' => $source->version,
                    'legal_form' => $source->legal_form,
                    'gst_registered' => $source->gst_registered,
                    'qst_registered' => $source->qst_registered,
                    'gst_rate' => $source->gst_rate,
                    'qst_rate' => $source->qst_rate,
                    'tax_effective_date' => $source->tax_effective_date,
                    'invoice_prefix' => $source->invoice_prefix,
                    'profile' => $source->profile,
                    'logo_data' => $source->logo_data,
                    'logo_mime' => $source->logo_mime,
                ];
                if ($target !== null) {
                    foreach ($values as $column => $value) {
                        if ((string) ($target->{$column} ?? '') !== (string) ($value ?? '')) {
                            throw new RuntimeException('The target already has different settings and was left unchanged.');
                        }
                    }

                    return false;
                }

                $now = now();
                DB::table('company_commercial_settings')->insert([
                    'company_id' => $targetCompanyId,
                    ...$values,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('company_security_audits')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $targetCompanyId,
                    'actor_subject' => (string) $ownerSubjectId,
                    'request_id' => (string) Str::uuid(),
                    'action' => 'company.settings.copied',
                    'details' => json_encode(['schemaVersion' => 1, 'sourceTradeName' => $expectedTradeName], JSON_THROW_ON_ERROR),
                    'occurred_at' => $now,
                ]);

                return true;
            });
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($copied ? 'Verified ViCarTech settings were copied to the target company.' : 'The target already contains the same verified settings.');

        return self::SUCCESS;
    }
}
