<?php

use App\Http\Controllers\TicketActionController;
use App\Http\Controllers\TicketsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TicketsController::class, 'index']);
Route::get('/api/tickets', [TicketsController::class, 'api']);
Route::get('/api/health/freshservice', [TicketsController::class, 'health']);
Route::get('/api/agents', [TicketsController::class, 'agents']);
Route::get('/api/automation-feedback', [TicketsController::class, 'automationFeedback']);
Route::get('/api/agents/{id}/output', [TicketsController::class, 'agentOutput']);

Route::get('/api/tickets/{id}/detail', [TicketsController::class, 'detail']);
Route::post('/api/tickets/{id}/operator-note', [TicketsController::class, 'saveOperatorNote']);
Route::get('/api/tickets/{id}/attachment/{filename}', [TicketsController::class, 'attachment'])
    ->where('filename', '[A-Za-z0-9._\-]+');
Route::get('/api/tickets/{id}/subtask/{filename}', [TicketsController::class, 'subtask'])
    ->where('filename', '[A-Za-z0-9._\-]+');

Route::post('/api/tickets/{id}/send-reply',       [TicketActionController::class, 'sendReply']);
Route::post('/api/tickets/{id}/send-manual-reply', [TicketActionController::class, 'sendManualReply']);
Route::post('/api/tickets/{id}/reject-draft',     [TicketActionController::class, 'rejectDraft']);
Route::post('/api/tickets/{id}/add-subtask',      [TicketActionController::class, 'addSubtask']);
Route::post('/api/tickets/{id}/complete-consult', [TicketActionController::class, 'completeConsult']);
Route::post('/api/tickets/{id}/human-review',     [TicketActionController::class, 'humanReview']);
Route::post('/api/tickets/{id}/close',            [TicketActionController::class, 'close']);
Route::post('/api/tickets/{id}/status',           [TicketActionController::class, 'setStatus']);
Route::post('/api/tickets/{id}/ai-compose',       [TicketActionController::class, 'aiCompose']);
Route::post('/api/tickets/{id}/report-automation', [TicketActionController::class, 'reportAutomationIssue']);
Route::post('/api/tickets/{id}/add-knowledge-fact', [TicketActionController::class, 'addKnowledgeFact']);
Route::post('/api/automation-feedback/{id}/approve', [TicketActionController::class, 'approveFeedback']);
Route::post('/api/automation-feedback/{id}/reject',  [TicketActionController::class, 'rejectFeedback']);
