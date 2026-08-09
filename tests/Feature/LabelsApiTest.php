<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Covers the shared ticket-labels feature: a common list of coloured labels
 * that operators create once, assign to many tickets, and filter by.
 *
 * The label store is a JSON file isolated per test run via the
 * LABELS_DB_PATH env (set in phpunit.xml). Each test starts from a clean
 * slate — see setUp().
 */
class LabelsApiTest extends TestCase
{
    private string $storePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storePath = env('LABELS_DB_PATH');
        $this->assertNotEmpty($this->storePath, 'LABELS_DB_PATH must be set for tests');
        // Start every test from an empty store.
        if (is_file($this->storePath)) {
            @unlink($this->storePath);
        }
        @mkdir(dirname($this->storePath), 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->storePath)) {
            @unlink($this->storePath);
        }
        parent::tearDown();
    }

    public function test_labels_list_is_empty_initially(): void
    {
        $response = $this->getJson('/api/labels');
        $response->assertStatus(200);
        $response->assertExactJson(['labels' => []]);
    }

    public function test_create_label_returns_it_with_id_and_zero_count(): void
    {
        $response = $this->postJson('/api/labels', ['name' => 'Robert', 'color' => '#3b82f6']);
        $response->assertStatus(201);
        $response->assertJsonPath('label.name', 'Robert');
        $response->assertJsonPath('label.color', '#3b82f6');
        $response->assertJsonPath('label.ticket_count', 0);
        $this->assertIsInt($response->json('label.id'));

        // And it now appears in the list.
        $list = $this->getJson('/api/labels');
        $list->assertJsonCount(1, 'labels');
        $list->assertJsonPath('labels.0.name', 'Robert');
    }

    public function test_create_label_requires_a_name(): void
    {
        $this->postJson('/api/labels', ['name' => '', 'color' => '#3b82f6'])
            ->assertStatus(422);
        $this->postJson('/api/labels', ['color' => '#3b82f6'])
            ->assertStatus(422);
    }

    public function test_create_label_validates_the_colour(): void
    {
        $this->postJson('/api/labels', ['name' => 'Bad', 'color' => 'blue'])
            ->assertStatus(422);
        $this->postJson('/api/labels', ['name' => 'Bad2', 'color' => '#ZZZZZZ'])
            ->assertStatus(422);
        // Valid 6-hex is accepted.
        $this->postJson('/api/labels', ['name' => 'Good', 'color' => '#AABBCC'])
            ->assertStatus(201);
    }

    public function test_create_label_rejects_duplicate_name_case_insensitive(): void
    {
        $this->postJson('/api/labels', ['name' => 'Chris', 'color' => '#ef4444'])
            ->assertStatus(201);
        $this->postJson('/api/labels', ['name' => 'chris', 'color' => '#10b981'])
            ->assertStatus(422);
    }

    public function test_assign_labels_to_ticket_is_reflected_in_tickets_api(): void
    {
        $robert = $this->postJson('/api/labels', ['name' => 'Robert', 'color' => '#3b82f6'])->json('label.id');
        $urgent = $this->postJson('/api/labels', ['name' => 'urgent', 'color' => '#ef4444'])->json('label.id');

        // Ticket 1001 exists in the test fixture DB.
        $this->postJson('/api/tickets/1001/labels', ['label_ids' => [$robert, $urgent]])
            ->assertStatus(200)
            ->assertJsonPath('label_ids', [$robert, $urgent]);

        // The main tickets endpoint now carries the assignment.
        $tickets = $this->getJson('/api/tickets')->json('tickets');
        $this->assertEqualsCanonicalizing([$robert, $urgent], $tickets['1001']['internal']['label_ids']);
    }

    public function test_reassigning_replaces_previous_labels(): void
    {
        $a = $this->postJson('/api/labels', ['name' => 'A', 'color' => '#111111'])->json('label.id');
        $b = $this->postJson('/api/labels', ['name' => 'B', 'color' => '#222222'])->json('label.id');

        $this->postJson('/api/tickets/1001/labels', ['label_ids' => [$a, $b]])->assertStatus(200);
        $this->postJson('/api/tickets/1001/labels', ['label_ids' => [$b]])->assertStatus(200);

        $tickets = $this->getJson('/api/tickets')->json('tickets');
        $this->assertSame([$b], array_values($tickets['1001']['internal']['label_ids']));
    }

    public function test_assign_rejects_unknown_label_id(): void
    {
        $this->postJson('/api/tickets/1001/labels', ['label_ids' => [99999]])
            ->assertStatus(422);
    }

    public function test_labels_list_includes_ticket_count(): void
    {
        $robert = $this->postJson('/api/labels', ['name' => 'Robert', 'color' => '#3b82f6'])->json('label.id');
        $this->postJson('/api/tickets/1001/labels', ['label_ids' => [$robert]])->assertStatus(200);
        $this->postJson('/api/tickets/1002/labels', ['label_ids' => [$robert]])->assertStatus(200);

        $labels = $this->getJson('/api/labels')->json('labels');
        $this->assertSame($robert, $labels[0]['id']);
        $this->assertSame(2, $labels[0]['ticket_count']);
    }

    public function test_delete_label_removes_it_and_strips_assignments(): void
    {
        $robert = $this->postJson('/api/labels', ['name' => 'Robert', 'color' => '#3b82f6'])->json('label.id');
        $chris = $this->postJson('/api/labels', ['name' => 'Chris', 'color' => '#ef4444'])->json('label.id');
        $this->postJson('/api/tickets/1001/labels', ['label_ids' => [$robert, $chris]])->assertStatus(200);

        $this->deleteJson('/api/labels/' . $robert)
            ->assertStatus(200)
            ->assertJsonPath('removed_from', 1);

        // Gone from the list.
        $labels = $this->getJson('/api/labels')->json('labels');
        $this->assertCount(1, $labels);
        $this->assertSame($chris, $labels[0]['id']);

        // Stripped from the ticket, but the other label survives.
        $tickets = $this->getJson('/api/tickets')->json('tickets');
        $this->assertSame([$chris], array_values($tickets['1001']['internal']['label_ids']));
    }

    public function test_delete_unknown_label_returns_404(): void
    {
        $this->deleteJson('/api/labels/424242')->assertStatus(404);
    }
}
