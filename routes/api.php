<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventController;
use App\Http\Controllers\MetricController;

Route::post('/events', [EventController::class, 'store']);
Route::post('/events/bulk', [EventController::class, 'bulkStore']);
Route::get('/metrics', [MetricController::class, 'index']);
