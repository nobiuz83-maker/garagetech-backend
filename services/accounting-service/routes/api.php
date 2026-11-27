<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn (): array => ['service' => 'accounting-service', 'status' => 'ok']);
