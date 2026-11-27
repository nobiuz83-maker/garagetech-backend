<?php

namespace Tests\Feature;

use App\Auth\SecurityFailure;
use App\Services\CompanyCommercialSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CompanyCommercialSettingsTest extends TestCase
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

    public function test_settings_are_company_scoped_and_control_future_invoice_taxes(): void
    {
        $firstCompany = (string) Str::uuid();
        $secondCompany = (string) Str::uuid();
        foreach ([$firstCompany, $secondCompany] as $companyId) {
            DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        }
        $settings = app(CompanyCommercialSettings::class);
        $saved = $settings->save($firstCompany, [
            ...$settings->read($firstCompany), 'tradeName' => 'Synthetic Garage',
            'street' => '1 Test Street', 'city' => 'Québec', 'gstRegistered' => false, 'qstRegistered' => false,
            'defaultPaymentTerms' => 'Synthetic terms',
        ]);

        self::assertSame(1, $saved['version']);
        self::assertSame('ViCarTech', $settings->read($secondCompany)['tradeName']);
        $input = ['sellerName' => 'Browser value', 'gstRate' => 5000, 'qstRate' => 9975,
            'details' => ['paymentTerms' => null, 'gstNumber' => 'Browser number', 'qstNumber' => 'Browser number']];
        $invoice = $settings->applyToInvoice($firstCompany, $input, false);
        self::assertSame('Synthetic Garage', $invoice['sellerName']);
        self::assertSame(0, $invoice['gstRate']);
        self::assertSame(0, $invoice['qstRate']);
        self::assertSame('Synthetic terms', $invoice['details']['paymentTerms']);
        self::assertNull($invoice['details']['gstNumber']);
        self::assertDatabaseCount('billing_invoices', 0);
        self::assertDatabaseCount('billing_payments', 0);
    }

    public function test_draft_allows_missing_terms_while_issue_requires_them(): void
    {
        $companyId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        $settings = app(CompanyCommercialSettings::class);
        $settings->save($companyId, [...$settings->read($companyId), 'street' => '1 Test Street', 'city' => 'Québec']);
        $input = ['details' => ['paymentTerms' => null]];

        self::assertNull($settings->applyToInvoice($companyId, $input, true)['details']['paymentTerms']);
        $this->expectException(ValidationException::class);
        $settings->applyToInvoice($companyId, $input, false);
    }

    public function test_concurrent_update_cannot_overwrite_saved_settings(): void
    {
        $companyId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        $settings = app(CompanyCommercialSettings::class);
        $draft = $settings->read($companyId);
        $settings->save($companyId, $draft);

        $this->expectException(SecurityFailure::class);
        $settings->save($companyId, $draft);
    }

    public function test_registered_tax_rates_and_numbers_are_copied_into_new_invoice_details(): void
    {
        $companyId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        $settings = app(CompanyCommercialSettings::class);
        $settings->save($companyId, [
            ...$settings->read($companyId), 'street' => '1 Test Street', 'city' => 'Québec',
            'gstRegistered' => true, 'gstNumber' => '123456789RT0001', 'gstRate' => 5000,
            'qstRegistered' => true, 'qstNumber' => '1234567890TQ0001', 'qstRate' => 9975,
            'defaultPaymentTerms' => 'Synthetic terms',
        ]);
        $invoice = $settings->applyToInvoice($companyId, ['details' => ['paymentTerms' => null]], false);

        self::assertSame(5000, $invoice['gstRate']);
        self::assertSame(9975, $invoice['qstRate']);
        self::assertSame('123456789RT0001', $invoice['details']['gstNumber']);
        self::assertSame('1234567890TQ0001', $invoice['details']['qstNumber']);
    }

    public function test_logo_is_persisted_reloaded_used_for_invoice_previews_and_deleted(): void
    {
        Storage::fake('company_logos');
        $companyId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        $settings = app(CompanyCommercialSettings::class);
        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGNkYPjPwMDAxMDAwMDAAAALHwEDmIWXfgAAAABJRU5ErkJggg==';

        $saved = $settings->save($companyId, [...$settings->read($companyId), 'logo' => $logo]);
        self::assertStringContainsString('/api/v1/company-logo/'.$companyId, $saved['logo']);
        self::assertSame($saved['logo'], $settings->applyToInvoice($companyId, ['details' => ['paymentTerms' => null]], true)['details']['sellerLogo']);
        $file = $settings->logoFile($companyId);
        self::assertSame('image/png', $file['mime']);
        self::assertNotEmpty($file['contents']);
        self::assertDatabaseHas('company_commercial_settings', ['company_id' => $companyId, 'logo_data' => null, 'logo_mime' => null]);

        $settings->save($companyId, [...$saved, 'logo' => null]);
        self::assertNull($settings->read($companyId)['logo']);
        self::assertDatabaseHas('company_commercial_settings', ['company_id' => $companyId, 'logo_data' => null, 'logo_mime' => null]);
    }

    public function test_company_logo_is_stored_as_a_file_and_retrievable_by_company(): void
    {
        Storage::fake('company_logos');
        $companyId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        $settings = app(CompanyCommercialSettings::class);
        $png = base64_encode("\x89PNG\r\n\x1a\n".'test-image-data');
        $saved = $settings->save($companyId, [...$settings->read($companyId), 'logo' => 'data:image/png;base64,'.$png]);

        self::assertStringContainsString('/api/v1/company-logo/'.$companyId, $saved['logo']);
        $file = $settings->logoFile($companyId);
        self::assertSame('image/png', $file['mime']);
        self::assertSame(base64_decode($png), $file['contents']);
        self::assertSame(1, DB::table('company_commercial_settings')->where('company_id', $companyId)->value('version'));
    }
}
