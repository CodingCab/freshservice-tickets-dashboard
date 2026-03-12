<?php

namespace App\Http\Controllers;

class TicketsController extends Controller
{
    /**
     * Return the path to the tickets database JSON file.
     * Defaults to the shared cron location; override via TICKETS_DB_PATH env var.
     */
    private static function dbPath(): string
    {
        return env('TICKETS_DB_PATH', '/home/artur/shared/cron/freshservice-tickets-db.json');
    }

    /**
     * Serve the SPA shell — no server-side data.
     */
    public function index()
    {
        return view('tickets');
    }

    /**
     * API endpoint — returns full ticket DB as JSON.
     *
     * Each ticket gets an `internal` sub-object with:
     *   - ticket_file: path to a task file referencing this ticket (if any)
     *   - next_action: next action for this ticket
     *   - summary: brief summary of the ticket
     */
    public function api()
    {
        $data = json_decode(file_get_contents(self::dbPath()), true);

        // Ensure every ticket has an `internal` sub-object
        if (isset($data['tickets'])) {
            foreach ($data['tickets'] as $id => &$ticket) {
                if (!isset($ticket['internal'])) {
                    $ticket['internal'] = [];
                }
                $ticket['internal'] = array_merge([
                    'ticket_file' => null,
                    'next_action' => null,
                    'summary' => null,
                ], $ticket['internal']);
            }
            unset($ticket);
        }

        return response()->json($data);
    }
}
