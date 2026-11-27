<?php

namespace App\Http\Controllers\Api;

use App\Auth\CompanyActor;
use App\Services\FreezeInterventionInventory;
use App\Services\VerifyInventoryIntervention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PreviewInterventionInventoryController
{
    public function __invoke(Request $request, string $intervention, VerifyInventoryIntervention $references, FreezeInterventionInventory $inventory): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $verified = $references->verify($request, $actor, $intervention);

        return response()->json(['data' => [
            'interventionId' => $intervention,
            'lines' => $inventory->preview($actor, $verified),
        ]]);
    }
}
