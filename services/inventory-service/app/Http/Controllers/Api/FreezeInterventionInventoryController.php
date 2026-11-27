<?php

namespace App\Http\Controllers\Api;

use App\Auth\CompanyActor;
use App\Http\Requests\FreezeInterventionInventoryRequest;
use App\Services\FreezeInterventionInventory;
use App\Services\IdempotentInventoryOperation;
use App\Services\VerifyInventoryIntervention;
use Illuminate\Http\JsonResponse;

final class FreezeInterventionInventoryController
{
    public function __invoke(FreezeInterventionInventoryRequest $request, string $intervention, VerifyInventoryIntervention $references, IdempotentInventoryOperation $idempotency, FreezeInterventionInventory $freeze): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $verified = $references->verify($request, $actor, $intervention);
        $input = $request->validated();
        $result = $idempotency->execute($actor, 'invoice.freeze.'.$intervention, $request->header('Idempotency-Key'), $request->getContent(),
            fn (): array => ['invoiceId' => $input['invoiceId'], 'interventionId' => $intervention, 'lines' => $freeze->freeze($actor, $verified, $input['invoiceId'], $input['laborSubtotalCents'], $input['expectedLinesHash'] ?? null)]);

        return response()->json(['data' => $result]);
    }
}
