<?php

namespace App\Services;

/**
 * JSON-backed store for the shared ticket-labels feature.
 *
 * The dashboard has no real database for tickets (everything is JSON /
 * markdown files), so labels follow the same house style: a single JSON
 * file holding the shared label list plus the per-ticket assignments.
 *
 * File shape:
 *   {
 *     "next_id": 3,
 *     "labels":  [ {"id":1,"name":"Robert","color":"#3b82f6"}, ... ],
 *     "assignments": { "66115": [1], "66120": [1,2] }
 *   }
 *
 * The store is process-safe via an exclusive lock around read-modify-write.
 * Path is overridable with LABELS_DB_PATH (used by the test suite); it
 * defaults to storage/tickets-labels.json.
 */
class LabelStore
{
    public static function path(): string
    {
        $p = env('LABELS_DB_PATH');
        if (is_string($p) && $p !== '') {
            // Allow a path relative to the app base (as configured in phpunit.xml).
            return str_starts_with($p, '/') ? $p : base_path($p);
        }
        return storage_path('tickets-labels.json');
    }

    /** Read the raw store, normalised to the full shape. */
    private static function read(): array
    {
        $path = self::path();
        $data = null;
        if (is_readable($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
            }
        }
        if (!is_array($data)) {
            $data = [];
        }
        return [
            'next_id' => isset($data['next_id']) ? (int) $data['next_id'] : 1,
            'labels' => is_array($data['labels'] ?? null) ? array_values($data['labels']) : [],
            'assignments' => is_array($data['assignments'] ?? null) ? $data['assignments'] : [],
        ];
    }

    private static function write(array $data): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    /**
     * The shared label list, each with a live ticket_count.
     *
     * @return array<int, array{id:int,name:string,color:string,ticket_count:int}>
     */
    public static function labelsWithCounts(): array
    {
        $data = self::read();
        $counts = [];
        foreach ($data['assignments'] as $ids) {
            foreach ((array) $ids as $id) {
                $counts[(int) $id] = ($counts[(int) $id] ?? 0) + 1;
            }
        }
        return array_map(fn ($l) => [
            'id' => (int) $l['id'],
            'name' => (string) $l['name'],
            'color' => (string) $l['color'],
            'ticket_count' => $counts[(int) $l['id']] ?? 0,
        ], $data['labels']);
    }

    /** True when a label with this (case-insensitive) name already exists. */
    public static function nameExists(string $name): bool
    {
        foreach (self::read()['labels'] as $l) {
            if (mb_strtolower(trim((string) $l['name'])) === mb_strtolower(trim($name))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create a label. Returns the created record (with ticket_count 0).
     */
    public static function create(string $name, string $color): array
    {
        $data = self::read();
        $id = (int) $data['next_id'];
        $label = ['id' => $id, 'name' => trim($name), 'color' => $color];
        $data['labels'][] = $label;
        $data['next_id'] = $id + 1;
        self::write($data);
        return $label + ['ticket_count' => 0];
    }

    public static function exists(int $id): bool
    {
        foreach (self::read()['labels'] as $l) {
            if ((int) $l['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Delete a label and strip it from every ticket assignment.
     * Returns the number of tickets it was removed from, or null if the
     * label did not exist.
     */
    public static function delete(int $id): ?int
    {
        $data = self::read();
        $found = false;
        $data['labels'] = array_values(array_filter($data['labels'], function ($l) use ($id, &$found) {
            if ((int) $l['id'] === $id) {
                $found = true;
                return false;
            }
            return true;
        }));
        if (!$found) {
            return null;
        }
        $removedFrom = 0;
        foreach ($data['assignments'] as $ticketId => $ids) {
            $ids = array_values($ids);
            $filtered = array_values(array_filter($ids, fn ($x) => (int) $x !== $id));
            if (count($filtered) !== count($ids)) {
                $removedFrom++;
            }
            if ($filtered) {
                $data['assignments'][$ticketId] = $filtered;
            } else {
                unset($data['assignments'][$ticketId]);
            }
        }
        self::write($data);
        return $removedFrom;
    }

    /**
     * Replace the full set of labels on one ticket. Unknown ids are the
     * caller's responsibility to validate. Returns the stored id list.
     *
     * @param  array<int, int>  $labelIds
     * @return array<int, int>
     */
    public static function setTicketLabels(string $ticketId, array $labelIds): array
    {
        $data = self::read();
        // Keep order, drop dupes, cast to int.
        $clean = [];
        foreach ($labelIds as $id) {
            $id = (int) $id;
            if (!in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        if ($clean) {
            $data['assignments'][$ticketId] = $clean;
        } else {
            unset($data['assignments'][$ticketId]);
        }
        self::write($data);
        return $clean;
    }

    /**
     * Map of ticket id (string) → array of assigned label ids, for merging
     * into the tickets API response.
     *
     * @return array<string, array<int,int>>
     */
    public static function assignments(): array
    {
        $out = [];
        foreach (self::read()['assignments'] as $ticketId => $ids) {
            $out[(string) $ticketId] = array_map('intval', array_values((array) $ids));
        }
        return $out;
    }
}
