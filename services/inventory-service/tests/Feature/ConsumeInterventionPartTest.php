<?php

namespace Tests\Feature;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Models\InventoryProduct;
use App\Services\AdjustInventoryStock;
use App\Services\ConsumeInterventionPart;
use App\Services\FreezeInterventionInventory;
use App\Services\ReverseInterventionPart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ConsumeInterventionPartTest extends TestCase
{
    private CompanyActor $actor;

    private InventoryProduct $product;

    /** @var array<string, mixed> */
    private array $intervention;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $companyId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        $this->actor = new CompanyActor('17', $companyId, (string) Str::uuid(), 1, ['inventory.stock.consume'], (string) Str::uuid());
        $this->product = InventoryProduct::create(['company_id' => $companyId, 'sku' => 'FILTER', 'name_fr' => 'Filtre', 'name_en' => 'Filter', 'purchase_price_cents' => 800, 'sale_price_cents' => 1200]);
        app(AdjustInventoryStock::class)->adjust($this->actor, $this->product->id, (string) Str::uuid(), 5000, 'Initial count');
        $this->intervention = ['id' => (string) Str::uuid(), 'clientId' => (string) Str::uuid(), 'vehicleId' => (string) Str::uuid(), 'status' => 'planned'];
    }

    public function test_consumption_is_atomic_and_keeps_the_historical_product_price(): void
    {
        $input = ['id' => (string) Str::uuid(), 'movementId' => (string) Str::uuid(), 'productId' => $this->product->id, 'quantityUnits' => 1500];
        $line = app(ConsumeInterventionPart::class)->consume($this->actor, $this->intervention, $input);
        $this->assertSame(1800, $line->total_cents);
        $this->assertSame(3500, $this->product->fresh()->available_units);
        $this->assertDatabaseHas('inventory_movements', ['line_id' => $line->id, 'quantity_delta_units' => -1500, 'type' => 'consumption']);
        $this->product->update(['sale_price_cents' => 2500]);
        $this->assertSame(1200, $line->fresh()->unit_price_cents);
        try {
            app(ConsumeInterventionPart::class)->consume($this->actor, $this->intervention, $input);
            $this->fail('A duplicate line must be refused.');
        } catch (SecurityFailure $failure) {
            $this->assertSame(409, $failure->status);
        }
        $this->assertSame(3500, $this->product->fresh()->available_units);
        $this->assertDatabaseCount('inventory_intervention_lines', 1);
    }

    public function test_insufficient_stock_does_not_create_a_line_or_movement(): void
    {
        try {
            app(ConsumeInterventionPart::class)->consume($this->actor, $this->intervention, ['id' => (string) Str::uuid(), 'movementId' => (string) Str::uuid(), 'productId' => $this->product->id, 'quantityUnits' => 5001]);
            $this->fail('Insufficient stock must be refused.');
        } catch (ValidationException) {
            $this->assertSame(5000, $this->product->fresh()->available_units);
        }
        $this->assertDatabaseCount('inventory_intervention_lines', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_explicit_reversal_restores_stock_once_and_keeps_both_movements(): void
    {
        $line = app(ConsumeInterventionPart::class)->consume($this->actor, $this->intervention, ['id' => (string) Str::uuid(), 'movementId' => (string) Str::uuid(), 'productId' => $this->product->id, 'quantityUnits' => 1000]);
        app(ReverseInterventionPart::class)->reverse($this->actor, $this->intervention['id'], $line->id, (string) Str::uuid(), 'Cancelled before billing');
        $this->assertSame(5000, $this->product->fresh()->available_units);
        $this->assertNotNull($line->fresh()->reversed_at);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->expectException(SecurityFailure::class);
        app(ReverseInterventionPart::class)->reverse($this->actor, $this->intervention['id'], $line->id, (string) Str::uuid(), 'Repeated return');
    }

    public function test_invoice_freeze_keeps_lines_stable_and_blocks_stock_changes(): void
    {
        $line = app(ConsumeInterventionPart::class)->consume($this->actor, $this->intervention, ['id' => (string) Str::uuid(), 'movementId' => (string) Str::uuid(), 'productId' => $this->product->id, 'quantityUnits' => 1000]);
        $invoiceId = (string) Str::uuid();
        $frozen = app(FreezeInterventionInventory::class)->freeze($this->actor, $this->intervention, $invoiceId, 10000);
        $this->assertSame($line->id, $frozen[0]['id']);
        $this->assertSame(1200, $frozen[0]['totalCents']);
        $this->assertSame($frozen, app(FreezeInterventionInventory::class)->freeze($this->actor, $this->intervention, $invoiceId, 10000));
        try {
            app(ReverseInterventionPart::class)->reverse($this->actor, $this->intervention['id'], $line->id, (string) Str::uuid(), 'Attempt after invoice');
            $this->fail('A frozen line cannot be reversed.');
        } catch (SecurityFailure $failure) {
            $this->assertSame(409, $failure->status);
        }
        $this->expectException(SecurityFailure::class);
        app(ConsumeInterventionPart::class)->consume($this->actor, $this->intervention, ['id' => (string) Str::uuid(), 'movementId' => (string) Str::uuid(), 'productId' => $this->product->id, 'quantityUnits' => 1000]);
    }

    public function test_preview_reads_consumed_parts_without_locking_or_changing_stock(): void
    {
        $line = app(ConsumeInterventionPart::class)->consume($this->actor, $this->intervention, [
            'id' => (string) Str::uuid(), 'movementId' => (string) Str::uuid(),
            'productId' => $this->product->id, 'quantityUnits' => 1500,
        ]);
        $availableBeforePreview = $this->product->fresh()->available_units;
        $preview = app(FreezeInterventionInventory::class)->preview($this->actor, $this->intervention);

        $this->assertCount(1, $preview);
        $this->assertSame($line->id, $preview[0]['id']);
        $this->assertSame(1800, $preview[0]['totalCents']);
        $this->assertSame($availableBeforePreview, $this->product->fresh()->available_units);
        $this->assertDatabaseCount('inventory_intervention_invoice_locks', 0);
    }
}
