<?php

namespace App\Http\Controllers\Api;

use App\Auth\CompanyActor;
use App\Http\Requests\SaveCompanyCommercialSettingsRequest;
use App\Services\CompanyCommercialSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyCommercialSettingsController
{
    public function show(Request $request, CompanyCommercialSettings $settings): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();

        return response()->json(['data' => $settings->read($actor->companyId)]);
    }

    public function update(SaveCompanyCommercialSettingsRequest $request, CompanyCommercialSettings $settings): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();

        return response()->json(['data' => $settings->save($actor->companyId, $request->validated())]);
    }
}
