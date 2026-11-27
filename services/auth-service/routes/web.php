<?php

use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:5,1');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth:web');
