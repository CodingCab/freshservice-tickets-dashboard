<?php

namespace Tests\Feature;

use Tests\TestCase;

class TicketsApiTest extends TestCase
{
    public function test_index_returns_html(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('FreshService Tickets');
        $response->assertSee('app.js');
    }

    public function test_api_returns_json_with_tickets(): void
    {
        $response = $this->getJson('/api/tickets');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'last_synced',
            'tickets',
        ]);
    }

    public function test_api_tickets_have_internal_fields(): void
    {
        $response = $this->getJson('/api/tickets');
        $response->assertStatus(200);

        $data = $response->json();
        $tickets = $data['tickets'] ?? [];

        $this->assertNotEmpty($tickets, 'Should have at least one ticket');

        // Check the first ticket has the internal sub-object
        $firstTicket = array_values($tickets)[0];
        $this->assertArrayHasKey('freshservice', $firstTicket);
        $this->assertArrayHasKey('internal', $firstTicket);
        $this->assertArrayHasKey('ticket_file', $firstTicket['internal']);
        $this->assertArrayHasKey('next_action', $firstTicket['internal']);
        $this->assertArrayHasKey('summary', $firstTicket['internal']);
    }

    public function test_api_tickets_have_freshservice_fields(): void
    {
        $response = $this->getJson('/api/tickets');
        $data = $response->json();
        $firstTicket = array_values($data['tickets'])[0];
        $fs = $firstTicket['freshservice'];

        $this->assertArrayHasKey('id', $fs);
        $this->assertArrayHasKey('subject', $fs);
        $this->assertArrayHasKey('status', $fs);
        $this->assertArrayHasKey('priority', $fs);
    }
}
