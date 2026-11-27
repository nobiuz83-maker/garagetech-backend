<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportClientsRequest;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;

class ClientImportController extends Controller
{
    public function __invoke(ImportClientsRequest $request, ClientService $service): JsonResponse
    {
        return response()->json(['data' => $service->import($request->user(), $request->validated())]);
    }
}
