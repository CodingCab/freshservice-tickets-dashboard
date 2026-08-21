<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Serves the pipeline metrics dashboard into this app's Pipeline tab.
 *
 * The table itself is not built here and deliberately so. It is computed by one
 * implementation, which writes itself out as a self-contained HTML file every hour;
 * rebuilding the same measurements in PHP would be a second implementation of numbers
 * that already disagree easily enough with one. This hands that file over unchanged.
 *
 * The consequence is honest and stated on the tab: what is shown is up to an hour old,
 * and if the export ever stops the page says when it was last written rather than
 * quietly ageing.
 */
class PipelineMetricsController extends Controller
{
    private const SNAPSHOT = '/shared/pipeline-metrics/dashboard.html';

    public function show(): Response
    {
        if (! is_readable(self::SNAPSHOT)) {
            return response(
                '<!doctype html><meta charset="utf-8">'
                .'<body style="margin:0;background:#141414;color:#898781;'
                .'font:14px system-ui;padding:40px">'
                .'<p>The pipeline metrics snapshot has not been written yet.</p>'
                .'<p style="color:#7d7b74;font-size:12px">It is produced hourly from the '
                .'archive. Nothing is wrong with this tab — there is simply no file to show.</p>'
                .'</body>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        }

        return response(
            (string) file_get_contents(self::SNAPSHOT),
            200,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                // It changes once an hour and weighs a couple of megabytes, so let the
                // browser keep it rather than refetch it on every tab switch.
                'Cache-Control' => 'private, max-age=300',
                'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', (int) filemtime(self::SNAPSHOT)),
            ]
        );
    }

    /** When the snapshot was last written, for the line above the frame. */
    public function status(): JsonResponse
    {
        $written = is_readable(self::SNAPSHOT) ? (int) filemtime(self::SNAPSHOT) : null;

        return response()->json([
            'available' => $written !== null,
            'written_at' => $written ? gmdate('c', $written) : null,
        ]);
    }
}
