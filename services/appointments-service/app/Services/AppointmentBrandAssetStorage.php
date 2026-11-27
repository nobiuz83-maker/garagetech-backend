<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class AppointmentBrandAssetStorage
{
    public function store(string $companyId, string $imageData): string
    {
        if (! preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=]+)$/D', $imageData, $matches)) {
            throw ValidationException::withMessages(['notificationDetails.companyLogo' => 'APPOINTMENT_LOGO_INVALID']);
        }
        $contents = base64_decode($matches[2], true);
        $image = is_string($contents) ? getimagesizefromstring($contents) : false;
        if (! is_string($contents) || strlen($contents) > 500_000 || $image === false) {
            throw ValidationException::withMessages(['notificationDetails.companyLogo' => 'APPOINTMENT_LOGO_INVALID']);
        }
        $extension = match ($image['mime'] ?? '') {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['notificationDetails.companyLogo' => 'APPOINTMENT_LOGO_INVALID']),
        };
        $companyIdentifier = str_replace('-', '', $companyId);
        $path = 'notification-logos/'.$companyIdentifier.'-'.hash('sha256', $contents).'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    public function dataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $contents = Storage::disk('local')->get($path);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => null,
        };

        return $mime ? 'data:'.$mime.';base64,'.base64_encode($contents) : null;
    }
}
