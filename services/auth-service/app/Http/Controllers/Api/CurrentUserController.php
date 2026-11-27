<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json((new UserResource($request->user()))->resolve())
            ->header('Cache-Control', 'no-store');
    }
}
