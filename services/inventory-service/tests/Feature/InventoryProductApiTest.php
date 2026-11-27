<?php

namespace Tests\Feature;

use App\Models\InventoryProduct;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InventoryProductApiTest extends TestCase
{
    private string $companyId;

    private string $requestId;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->companyId = (string) Str::uuid();
        $this->requestId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $this->companyId, 'status' => 'active', 'directory_version' => 1]);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'inventory-auth-');
        file_put_contents($this->keyFile, str_repeat('k', 48));
        config()->set('company_security.enabled', true);
        config()->set('company_security.auth_url', 'https://auth-service:8443');
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            config()->set('company_security.'.$setting, $this->keyFile);
        }
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $read = $request->method() === 'GET';
            if ($read) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $permissions = explode(',', $query['requiredPermissions']);
            } else {
                $permissions = $request->data()['requiredPermissions'];
            }

            return Http::response([
                'schemaVersion' => 2, 'subject' => '17', 'companyId' => $this->companyId,
                'membershipId' => (string) Str::uuid(), 'authorizationVersion' => 1,
                'permissions' => $permissions, 'purpose' => $read ? 'resource.read' : 'resource.write',
                'requestId' => $this->requestId, 'allowed' => true,
            ]);
        });
        $this->withHeaders(['X-Company-ID' => $this->companyId, 'X-Request-ID' => $this->requestId, 'X-XSRF-TOKEN' => 'synthetic-csrf'])->withCredentials();
    }

    protected function tearDown(): void
    {
        unlink($this->keyFile);
        parent::tearDown();
    }

    public function test_product_adjustment_retry_and_movement_history(): void
    {
        $productId = (string) Str::uuid();
        $payload = ['id' => $productId, 'sku' => 'FILTER-001', 'nameFr' => 'Filtre', 'nameEn' => 'Filter', 'purchasePriceCents' => 800, 'salePriceCents' => 1200];
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->withHeader('Idempotency-Key', 'synthetic-product-0001')
            ->postJson('/api/v1/inventory/products', $payload)->assertCreated()->assertJsonPath('data.availableUnits', 0);
        $movement = ['id' => (string) Str::uuid(), 'quantityDeltaUnits' => 2500, 'reason' => 'Initial count'];
        $this->withHeader('Idempotency-Key', 'synthetic-adjustment-0001')
            ->postJson('/api/v1/inventory/products/'.$productId.'/adjustments', $movement)->assertCreated();
        $this->postJson('/api/v1/inventory/products/'.$productId.'/adjustments', $movement)->assertCreated();
        $this->getJson('/api/v1/inventory/products/'.$productId)->assertOk()->assertJsonPath('data.availableUnits', 2500);
        $this->getJson('/api/v1/inventory/products/'.$productId.'/movements')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeader('Idempotency-Key', 'synthetic-adjustment-0002')
            ->postJson('/api/v1/inventory/products/'.$productId.'/adjustments', ['id' => (string) Str::uuid(), 'quantityDeltaUnits' => -2501, 'reason' => 'Invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_products_are_isolated_by_company_and_guests_are_rejected(): void
    {
        $otherCompanyId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $otherCompanyId, 'status' => 'active', 'directory_version' => 1]);
        $product = InventoryProduct::create(['company_id' => $otherCompanyId, 'sku' => 'OTHER', 'name_fr' => 'Autre', 'purchase_price_cents' => 100, 'sale_price_cents' => 200]);
        $this->getJson('/api/v1/inventory/products')->assertUnauthorized();
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->getJson('/api/v1/inventory/products/'.$product->id)->assertNotFound();
    }
}
