<?php

use App\Http\Controllers\TicketsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TicketsController::class, 'index']);
Route::get('/api/tickets', [TicketsController::class, 'api']);
Route::get('/api/agents', [TicketsController::class, 'agents']);
Route::get('/api/agents/{id}/output', [TicketsController::class, 'agentOutput']);
