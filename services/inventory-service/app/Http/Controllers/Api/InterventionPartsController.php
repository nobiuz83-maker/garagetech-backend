<?php

namespace App\Http\Controllers\Api;

use App\Auth\CompanyActor;
use App\Http\Requests\ConsumeInterventionPartRequest;
use App\Http\Requests\ReverseInterventionPartRequest;
use App\Http\Resources\InventoryInterventionLineResource;
use App\Models\InventoryInterventionLine;
use App\Services\ConsumeInterventionPart;
use App\Services\EnsureInterventionNotInvoiced;
use App\Services\IdempotentInventoryOperation;
use App\Services\ReverseInterventionPart;
use App\Services\VerifyInventoryIntervention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InterventionPartsController
{
    public function index(Request $request, string $intervention, VerifyInventoryIntervention $references): AnonymousResourceCollection
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $references->verify($request, $actor, $intervention);

        return InventoryInterventionLineResource::collection(InventoryInterventionLine::where('company_id', $actor->companyId)
            ->where('intervention_id', $intervention)->orderBy('consumed_at')->orderBy('id')->paginate(50));
    }

    public function store(ConsumeInterventionPartRequest $request, string $intervention, VerifyInventoryIntervention $references, EnsureInterventionNotInvoiced $billing, IdempotentInventoryOperation $idempotency, ConsumeInterventionPart $stock): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $verified = $references->verify($request, $actor, $intervention);
        $billing->check($actor, $intervention);
        $input = $request->validated();
        $result = $idempotency->execute($actor, 'line.consume.'.$intervention, $request->header('Idempotency-Key'), $request->getContent(), function () use ($actor, $verified, $input, $request, $stock): array {
            $line = $stock->consume($actor, $verified, $input);

            return (new InventoryInterventionLineResource($line))->resolve($request);
        });

        return response()->json(['data' => $result], 201);
    }

    public function reverse(ReverseInterventionPartRequest $request, string $intervention, string $line, VerifyInventoryIntervention $references, EnsureInterventionNotInvoiced $billing, IdempotentInventoryOperation $idempotency, ReverseInterventionPart $stock): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $references->verify($request, $actor, $intervention);
        $billing->check($actor, $intervention);
        $input = $request->validated();
        $result = $idempotency->execute($actor, 'line.reverse.'.$line, $request->header('Idempotency-Key'), $request->getContent(), function () use ($actor, $intervention, $line, $input, $request, $stock): array {
            $record = $stock->reverse($actor, $intervention, $line, $input['movementId'], $input['reason']);

            return (new InventoryInterventionLineResource($record))->resolve($request);
        });

        return response()->json(['data' => $result]);
    }
}
