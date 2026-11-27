<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CopyCompanySettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    public function test_copies_only_verified_settings_and_leaves_the_source_unchanged(): void
    {
        $sourceCompanyId = (string) Str::uuid();
        $targetCompanyId = (string) Str::uuid();
        $sourceSettings = [
            'company_id' => $sourceCompanyId, 'version' => 4, 'legal_form' => 'sole_proprietorship',
            'gst_registered' => 1, 'qst_registered' => 0, 'gst_rate' => 5000, 'qst_rate' => 9975,
            'tax_effective_date' => null, 'invoice_prefix' => 'VT-',
            'profile' => json_encode(['tradeName' => 'ViCarTech', 'city' => 'Québec'], JSON_THROW_ON_ERROR),
            'logo_data' => 'c21hbGw=', 'logo_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('company_subjects')->insert([
            ['company_id' => $sourceCompanyId, 'status' => 'active', 'directory_version' => 1],
            ['company_id' => $targetCompanyId, 'status' => 'active', 'directory_version' => 1],
        ]);
        DB::table('company_commercial_settings')->insert($sourceSettings);

        $this->artisan('billing:copy-company-settings', [
            'source-company-id' => $sourceCompanyId,
            'target-company-id' => $targetCompanyId,
            'owner-subject-id' => 1,
        ])->assertSuccessful();

        $this->assertDatabaseHas('company_commercial_settings', ['company_id' => $sourceCompanyId, 'profile' => $sourceSettings['profile']]);
        $this->assertDatabaseHas('company_commercial_settings', ['company_id' => $targetCompanyId, 'profile' => $sourceSettings['profile'], 'logo_data' => 'c21hbGw=']);
        $this->assertDatabaseHas('company_security_audits', ['company_id' => $targetCompanyId, 'action' => 'company.settings.copied']);
    }

    public function test_rejects_source_settings_that_do_not_belong_to_vicartech(): void
    {
        $sourceCompanyId = (string) Str::uuid();
        $targetCompanyId = (string) Str::uuid();
        DB::table('company_subjects')->insert([
            ['company_id' => $sourceCompanyId, 'status' => 'active', 'directory_version' => 1],
            ['company_id' => $targetCompanyId, 'status' => 'active', 'directory_version' => 1],
        ]);
        DB::table('company_commercial_settings')->insert([
            'company_id' => $sourceCompanyId, 'version' => 1, 'legal_form' => 'sole_proprietorship',
            'gst_registered' => 0, 'qst_registered' => 0, 'gst_rate' => 5000, 'qst_rate' => 9975,
            'invoice_prefix' => '', 'profile' => json_encode(['tradeName' => 'Synthetic A'], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('billing:copy-company-settings', [
            'source-company-id' => $sourceCompanyId,
            'target-company-id' => $targetCompanyId,
            'owner-subject-id' => 1,
        ])->assertFailed();

        $this->assertDatabaseMissing('company_commercial_settings', ['company_id' => $targetCompanyId]);
    }
}
