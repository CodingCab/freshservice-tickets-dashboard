<?php

namespace App\Http\Controllers;

use App\Services\LabelStore;
use Illuminate\Http\Request;

/**
 * Shared ticket labels: a common list of coloured labels operators create
 * once (e.g. "Robert", "Chris", "urgent"), assign to any number of tickets,
 * and filter the dashboard by. State lives in a JSON file (LabelStore) — the
 * same file-based house style the rest of this app uses. Labels are shared
 * across everyone who opens the dashboard.
 */
class LabelController extends Controller
{
    private const COLOR_RE = '/^#[0-9a-fA-F]{6}$/';

    /** GET /api/labels — the shared list, each with a live ticket_count. */
    public function index()
    {
        return response()->json(['labels' => LabelStore::labelsWithCounts()]);
    }

    /** POST /api/labels — create a label {name, color}. */
    public function store(Request $request)
    {
        $name = trim((string) $request->input('name', ''));
        $color = (string) $request->input('color', '');

        if ($name === '' || mb_strlen($name) > 40) {
            return response()->json([
                'error' => 'invalid_name',
                'message' => 'Name is required and must be 40 characters or fewer.',
            ], 422);
        }
        if (!preg_match(self::COLOR_RE, $color)) {
            return response()->json([
                'error' => 'invalid_color',
                'message' => 'Color must be a 6-digit hex like #3b82f6.',
            ], 422);
        }
        if (LabelStore::nameExists($name)) {
            return response()->json([
                'error' => 'duplicate_name',
                'message' => 'A label with this name already exists.',
            ], 422);
        }

        $label = LabelStore::create($name, strtolower($color));
        return response()->json(['label' => $label], 201);
    }

    /** DELETE /api/labels/{id} — remove a label and strip it from all tickets. */
    public function destroy(int $id)
    {
        $removedFrom = LabelStore::delete($id);
        if ($removedFrom === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json(['status' => 'deleted', 'removed_from' => $removedFrom]);
    }

    /** POST /api/tickets/{id}/labels — replace the labels on one ticket {label_ids:[]}. */
    public function assign(Request $request, string $id)
    {
        $ids = $request->input('label_ids', []);
        if (!is_array($ids)) {
            return response()->json(['error' => 'invalid_label_ids'], 422);
        }
        foreach ($ids as $labelId) {
            if (!is_numeric($labelId) || !LabelStore::exists((int) $labelId)) {
                return response()->json([
                    'error' => 'unknown_label',
                    'message' => 'One or more label ids do not exist.',
                ], 422);
            }
        }
        $stored = LabelStore::setTicketLabels($id, $ids);
        return response()->json(['status' => 'saved', 'label_ids' => $stored]);
    }
}
