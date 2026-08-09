<?php

namespace App\Console\Commands;

use App\Http\Controllers\TicketActionController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Send a ticket's prepared reply from the server side, with no HTTP hop.
 *
 * The dashboard's send endpoint sits behind the same session auth as every
 * other page, so a background automation calling it over HTTP has no way to
 * authenticate — it gets bounced to the login page and the send never happens,
 * silently. That is exactly what stranded armed auto-sends: the flag stayed
 * armed, the operator saw "armed", and nothing ever went out.
 *
 * This command runs the identical send path in-process instead. One
 * implementation, no credentials, no URL to get wrong.
 *
 * Exit codes: 0 sent (or already sent — nothing left to do), 1 refused/failed.
 */
class SendTicketReply extends Command
{
    protected $signature = 'tickets:send-reply
                            {ticket : Ticket id, with or without the leading T}
                            {--force-section : Send even if the ticket is not in Ready to Send}';

    protected $description = "Send a ticket's prepared reply draft to the customer via FreshService";

    public function handle(TicketActionController $controller): int
    {
        $ticketId = (string) $this->argument('ticket');

        $request = Request::create(
            '/api/tickets/' . ltrim($ticketId, 'Tt') . '/send-reply',
            'POST',
            $this->option('force-section') ? ['confirm_wrong_section' => true] : []
        );

        $response = $controller->sendReply($request, $ticketId);
        $body = json_decode((string) $response->getContent(), true) ?: [];

        $this->line((string) $response->getContent());

        $status = $body['status'] ?? null;
        $error  = $body['error'] ?? null;

        if ($response->getStatusCode() === 200 || $status === 'sent') {
            return self::SUCCESS;
        }
        // Already sent is not a failure — the outcome the caller wanted holds.
        if ($status === 'already_sent' || $error === 'already_sent') {
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
