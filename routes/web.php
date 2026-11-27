<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/login', [App\Http\Controllers\Api\SessionController::class, 'store'])->middleware('throttle:5,1');
Route::post('/logout', [App\Http\Controllers\Api\SessionController::class, 'destroy'])->middleware('auth');
