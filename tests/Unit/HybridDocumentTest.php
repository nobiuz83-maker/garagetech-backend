<?php

namespace Tests\Unit;

use App\Support\HybridDocument;
use PHPUnit\Framework\TestCase;

class HybridDocumentTest extends TestCase
{
    public function test_partial_changes_preserve_sibling_fields_and_unknown_archived_properties(): void
    {
        $document = ['schemaVersion' => 1, 'engine' => ['fuelType' => 'gasoline', 'engineCode' => 'ABC'], 'legacyProperties' => ['custom' => true]];
        $updated = HybridDocument::merge($document, ['engine' => ['fuelType' => 'diesel']]);
        $this->assertSame('diesel', $updated['engine']['fuelType']);
        $this->assertSame('ABC', $updated['engine']['engineCode']);
        $this->assertTrue($updated['legacyProperties']['custom']);
        $this->assertSame(['schemaVersion' => 1], HybridDocument::merge(null, []));
    }
}
