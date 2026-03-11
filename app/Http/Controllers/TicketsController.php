<?php

namespace App\Http\Controllers;

class TicketsController extends Controller
{
    private const DB_PATH = '/home/artur/shared/cron/freshservice-tickets-db.json';

    /**
     * Serve the SPA shell — no server-side data.
     */
    public function index()
    {
        return view('tickets');
    }

    /**
     * API endpoint — returns full ticket DB as JSON.
     */
    public function api()
    {
        $data = json_decode(file_get_contents(self::DB_PATH), true);

        return response()->json($data);
    }
}
