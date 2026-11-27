<?php

namespace Tests\Feature;

use App\Services\AppointmentBrandAssetStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentBrandAssetStorageTest extends TestCase
{
    public function test_brand_logo_is_written_to_persistent_file_storage_and_read_as_an_email_data_uri(): void
    {
        Storage::fake('local');
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
        $storage = app(AppointmentBrandAssetStorage::class);

        $path = $storage->store('11111111-1111-4111-8111-111111111111', $image);

        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $storage->dataUri($path));
    }

    public function test_brand_logo_rejects_unsupported_or_invalid_image_content(): void
    {
        Storage::fake('local');

        $this->expectException(ValidationException::class);
        app(AppointmentBrandAssetStorage::class)->store('11111111-1111-4111-8111-111111111111', 'data:image/svg+xml;base64,PHN2Zy8+');
    }
}
