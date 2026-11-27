<?php

namespace App\Services;

use App\Auth\SecurityFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CompanyCommercialSettings
{
    /** @return array<string, mixed> */
    public function read(string $companyId): array
    {
        $record = DB::table('company_commercial_settings')->where('company_id', $companyId)->first();
        if ($record === null) {
            return $this->defaults();
        }

        return [
            ...json_decode($record->profile, true, 512, JSON_THROW_ON_ERROR),
            'version' => (int) $record->version,
            'legalForm' => $record->legal_form,
            'gstRegistered' => (bool) $record->gst_registered,
            'qstRegistered' => (bool) $record->qst_registered,
            'gstRate' => (int) $record->gst_rate,
            'qstRate' => (int) $record->qst_rate,
            'taxEffectiveDate' => $record->tax_effective_date,
            'invoicePrefix' => $record->invoice_prefix,
            'logo' => $this->logoUrl($record),
        ];
    }

    /** @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function save(string $companyId, array $settings): array
    {
        $settings = array_replace($this->defaults(), $settings);
        $newLogoPath = null;
        $oldLogoPath = null;
        DB::transaction(function () use ($companyId, $settings, &$newLogoPath, &$oldLogoPath): void {
            $company = DB::table('company_subjects')->where('company_id', $companyId)->lockForUpdate()->first();
            if ($company === null || $company->status !== 'active') {
                throw new SecurityFailure('COMPANY_NOT_ACTIVE', 403);
            }
            $existing = DB::table('company_commercial_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            if (($existing === null ? 0 : (int) $existing->version) !== $settings['version']) {
                throw new SecurityFailure('SETTINGS_VERSION_CONFLICT', 409);
            }
            $profile = $settings;
            foreach (['version', 'legalForm', 'gstRegistered', 'qstRegistered', 'gstRate', 'qstRate', 'taxEffectiveDate', 'invoicePrefix', 'logo'] as $column) {
                unset($profile[$column]);
            }
            $logo = $settings['logo'] ?? null;
            $logoPath = $existing?->logo_path;
            if ($logo !== $this->logoUrl($existing)) {
                $logoPath = $this->storeLogo($companyId, $logo);
                $newLogoPath = $logoPath;
                $oldLogoPath = $existing?->logo_path;
            }
            $values = [
                'version' => $settings['version'] + 1,
                'legal_form' => $settings['legalForm'],
                'gst_registered' => $settings['gstRegistered'],
                'qst_registered' => $settings['qstRegistered'],
                'gst_rate' => $settings['gstRate'],
                'qst_rate' => $settings['qstRate'],
                'tax_effective_date' => $settings['taxEffectiveDate'] ?? null,
                'invoice_prefix' => $settings['invoicePrefix'] ?? '',
                'profile' => json_encode($profile, JSON_THROW_ON_ERROR),
                'logo_path' => $logoPath,
                'updated_at' => now(),
            ];
            if ($existing === null) {
                DB::table('company_commercial_settings')->insert(['company_id' => $companyId, 'created_at' => now(), ...$values]);
            } else {
                DB::table('company_commercial_settings')->where('company_id', $companyId)->update($values);
            }
        });

        if ($oldLogoPath && $oldLogoPath !== $newLogoPath) {
            Storage::disk('company_logos')->delete($oldLogoPath);
        }

        return $this->read($companyId);
    }

    private function logoUrl(?object $record): ?string
    {
        if ($record === null) return null;
        if (! empty($record->logo_path)) return url('/api/v1/company-logo/'.$record->company_id);
        return $record->logo_data === null ? null : 'data:'.$record->logo_mime.';base64,'.$record->logo_data;
    }

    private function storeLogo(string $companyId, ?string $logo): ?string
    {
        if ($logo === null || $logo === '') return null;
        if (! preg_match('/^data:image\/(png|jpeg|webp);base64,([A-Za-z0-9+\/=]+)$/D', $logo, $matches)) {
            throw ValidationException::withMessages(['logo' => 'LOGO_INVALID']);
        }
        $bytes = base64_decode($matches[2], true);
        if ($bytes === false || strlen($bytes) > 500000 || strlen($bytes) === 0) {
            throw ValidationException::withMessages(['logo' => 'LOGO_INVALID']);
        }
        $extension = match ($matches[1]) { 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp' };
        $path = $companyId.'/'.Str::uuid().'.'.$extension;
        Storage::disk('company_logos')->put($path, $bytes);
        return $path;
    }

    public function logoFile(string $companyId): ?array
    {
        $record = DB::table('company_commercial_settings')->where('company_id', $companyId)->first();
        if ($record === null) return null;
        if (! empty($record->logo_path) && Storage::disk('company_logos')->exists($record->logo_path)) {
            return ['contents' => Storage::disk('company_logos')->get($record->logo_path), 'mime' => Storage::disk('company_logos')->mimeType($record->logo_path) ?: 'application/octet-stream'];
        }
        if ($record->logo_data !== null) return ['contents' => base64_decode($record->logo_data, true) ?: '', 'mime' => $record->logo_mime];
        return null;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function applyToInvoice(string $companyId, array $input, bool $isDraft): array
    {
        $settings = $this->read($companyId);
        if ($settings['version'] === 0) {
            return $input;
        }
        $effective = ! $settings['taxEffectiveDate'] || $settings['taxEffectiveDate'] <= now()->toDateString();
        $gstRate = $effective && $settings['gstRegistered'] ? $settings['gstRate'] : 0;
        $qstRate = $effective && $settings['qstRegistered'] ? $settings['qstRate'] : 0;
        $address = implode(', ', array_filter([
            $settings['street'], $settings['city'], $settings['province'], $settings['postalCode'], $settings['country'],
        ], fn (?string $part): bool => filled($part)));
        $details = [
            ...$input['details'],
            'sellerLegalName' => $settings['legalName'],
            'sellerAddress' => filled($settings['street']) && filled($settings['city']) ? $address : null,
            'sellerPhone' => $settings['phone'],
            'sellerEmail' => $settings['email'],
            'sellerWebsite' => $settings['website'],
            'sellerSecondaryPhone' => $settings['secondaryPhone'],
            'sellerNeq' => $settings['neq'],
            'gstNumber' => $gstRate ? $settings['gstNumber'] : null,
            'qstNumber' => $qstRate ? $settings['qstNumber'] : null,
            'paymentTerms' => $input['details']['paymentTerms'] ?: $settings['defaultPaymentTerms'],
            'sellerLogo' => $settings['logo'],
            'invoicePrefix' => $settings['invoicePrefix'],
            'paymentDueDays' => $settings['paymentDueDays'],
            'documentLanguage' => $settings['defaultLanguage'],
            'commercialNotes' => $settings['notes'],
            'thankYouMessage' => $settings['thankYouMessage'],
            'warrantyTerms' => $settings['warrantyTerms'],
        ];
        if (! $isDraft) {
            if (! filled($details['sellerAddress'])) {
                throw ValidationException::withMessages(['details.sellerAddress' => 'SELLER_ADDRESS_REQUIRED']);
            }
            if ($gstRate && ! filled($details['gstNumber'])) {
                throw ValidationException::withMessages(['details.gstNumber' => 'GST_NUMBER_REQUIRED']);
            }
            if ($qstRate && ! filled($details['qstNumber'])) {
                throw ValidationException::withMessages(['details.qstNumber' => 'QST_NUMBER_REQUIRED']);
            }
            if (! filled($details['paymentTerms'])) {
                throw ValidationException::withMessages(['details.paymentTerms' => 'PAYMENT_TERMS_REQUIRED']);
            }
        }

        return [...$input, 'sellerName' => $settings['tradeName'], 'gstRate' => $gstRate, 'qstRate' => $qstRate, 'details' => $details];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'version' => 0, 'tradeName' => 'ViCarTech', 'legalName' => null,
            'legalForm' => 'sole_proprietorship', 'neq' => null, 'street' => null,
            'city' => null, 'province' => 'Québec', 'postalCode' => null,
            'country' => 'Canada', 'phone' => null, 'secondaryPhone' => null,
            'email' => null, 'website' => null, 'currency' => 'CAD',
            'gstRegistered' => false, 'gstNumber' => null, 'gstRate' => 5000,
            'qstRegistered' => false, 'qstNumber' => null, 'qstRate' => 9975,
            'taxEffectiveDate' => null, 'invoicePrefix' => '', 'defaultPaymentTerms' => null,
            'paymentDueDays' => null, 'notes' => null, 'thankYouMessage' => null,
            'warrantyTerms' => null, 'defaultLanguage' => 'fr-CA', 'logo' => null,
        ];
    }
}
