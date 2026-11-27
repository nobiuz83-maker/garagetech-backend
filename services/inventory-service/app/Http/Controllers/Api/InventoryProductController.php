<?php

namespace App\Http\Controllers\Api;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Http\Requests\AdjustInventoryRequest;
use App\Http\Requests\SaveInventoryProductRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\InventoryProductResource;
use App\Models\InventoryProduct;
use App\Policies\CompanyResourceAccess;
use App\Services\AdjustInventoryStock;
use App\Services\IdempotentInventoryOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InventoryProductController
{
    public function index(Request $request, CompanyResourceAccess $access): AnonymousResourceCollection
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();

        return InventoryProductResource::collection($access->scope($actor, InventoryProduct::query())->orderBy('sku')->orderBy('id')->paginate(50));
    }

    public function show(Request $request, string $product, CompanyResourceAccess $access): InventoryProductResource
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();

        return new InventoryProductResource($access->scope($actor, InventoryProduct::query())->findOrFail($product));
    }

    public function store(SaveInventoryProductRequest $request, IdempotentInventoryOperation $idempotency): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $input = $request->validated();
        $result = $idempotency->execute($actor, 'product.create', $request->header('Idempotency-Key'), $request->getContent(), function () use ($actor, $input, $request): array {
            $product = InventoryProduct::create([
                'id' => $input['id'], 'company_id' => $actor->companyId, 'sku' => $input['sku'],
                'name_fr' => $input['nameFr'], 'name_en' => $input['nameEn'],
                'purchase_price_cents' => $input['purchasePriceCents'], 'sale_price_cents' => $input['salePriceCents'],
            ]);

            return (new InventoryProductResource($product->refresh()))->resolve($request);
        });

        return response()->json(['data' => $result], 201);
    }

    public function update(SaveInventoryProductRequest $request, string $product, IdempotentInventoryOperation $idempotency): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $input = $request->validated();
        abort_unless($input['id'] === $product, 422, 'INVENTORY_ID_MISMATCH');
        $result = $idempotency->execute($actor, 'product.update.'.$product, $request->header('Idempotency-Key'), $request->getContent(), function () use ($actor, $input, $product, $request): array {
            $record = InventoryProduct::where('company_id', $actor->companyId)->whereKey($product)->lockForUpdate()->first();
            if ($record === null) {
                throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
            }
            if ($record->version !== $input['version']) {
                throw new SecurityFailure('VERSION_CONFLICT', 409);
            }
            $record->fill([
                'sku' => $input['sku'], 'name_fr' => $input['nameFr'], 'name_en' => $input['nameEn'],
                'purchase_price_cents' => $input['purchasePriceCents'], 'sale_price_cents' => $input['salePriceCents'],
                'version' => $record->version + 1,
            ])->save();

            return (new InventoryProductResource($record->refresh()))->resolve($request);
        });

        return response()->json(['data' => $result]);
    }

    public function movements(Request $request, string $product, CompanyResourceAccess $access): AnonymousResourceCollection
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $record = $access->scope($actor, InventoryProduct::query())->findOrFail($product);

        return InventoryMovementResource::collection($record->movements()->where('company_id', $actor->companyId)->orderByDesc('occurred_at')->orderBy('id')->paginate(50));
    }

    public function adjust(AdjustInventoryRequest $request, string $product, IdempotentInventoryOperation $idempotency, AdjustInventoryStock $stock): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $input = $request->validated();
        $result = $idempotency->execute($actor, 'stock.adjust.'.$product, $request->header('Idempotency-Key'), $request->getContent(), function () use ($actor, $input, $product, $request, $stock): array {
            $movement = $stock->adjust($actor, $product, $input['id'], $input['quantityDeltaUnits'], $input['reason']);

            return (new InventoryMovementResource($movement))->resolve($request);
        });

        return response()->json(['data' => $result], 201);
    }
}
