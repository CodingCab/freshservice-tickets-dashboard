<?php

use App\Http\Controllers\TicketsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TicketsController::class, 'index']);
Route::get('/api/tickets', [TicketsController::class, 'api']);
