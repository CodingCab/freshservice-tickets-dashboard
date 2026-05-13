<?php

use App\Http\Controllers\TicketActionController;
use App\Http\Controllers\TicketsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TicketsController::class, 'index']);
Route::get('/api/tickets', [TicketsController::class, 'api']);
Route::get('/api/agents', [TicketsController::class, 'agents']);
Route::get('/api/agents/{id}/output', [TicketsController::class, 'agentOutput']);

Route::get('/api/tickets/{id}/detail', [TicketsController::class, 'detail']);

Route::post('/api/tickets/{id}/send-reply',       [TicketActionController::class, 'sendReply']);
Route::post('/api/tickets/{id}/reject-draft',     [TicketActionController::class, 'rejectDraft']);
Route::post('/api/tickets/{id}/add-subtask',      [TicketActionController::class, 'addSubtask']);
Route::post('/api/tickets/{id}/complete-consult', [TicketActionController::class, 'completeConsult']);
Route::post('/api/tickets/{id}/human-review',     [TicketActionController::class, 'humanReview']);
Route::post('/api/tickets/{id}/close',            [TicketActionController::class, 'close']);
