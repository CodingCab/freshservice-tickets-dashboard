<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * FreshServiceClient — thin shell-out wrapper around the Python helper at
 * `/shared/Dropbox/ShipTown/apps/freshservice/freshservice_api.py`.
 *
 * Per the foundation audit (option C), credentials live in `pw` (the Python
 * password manager) — not in Laravel `.env`. We invoke the helper via the
 * Symfony Process component and parse its JSON stdout.
 *
 * Usage of the Python CLI (verified via `python3 freshservice_api.py`):
 *   freshservice_api.py post   <endpoint> <json>
 *   freshservice_api.py put    <endpoint> <json>
 *   freshservice_api.py get    <endpoint>
 *   freshservice_api.py delete <endpoint>
 */
class FreshServiceClient
{
    private const HELPER_PATH = '/shared/Dropbox/ShipTown/apps/freshservice/freshservice_api.py';
    private const TIMEOUT_SECONDS = 30.0;

    /**
     * POST `/api/v2/tickets/{id}/reply`.
     *
     * @param array<int, string> $ccEmails
     * @return array<string, mixed>  The parsed `conversation` object.
     */
    public function postReply(int $ticketId, string $htmlBody, array $ccEmails = []): array
    {
        $payload = ['body' => $htmlBody];
        if (!empty($ccEmails)) {
            $payload['cc_emails'] = array_values($ccEmails);
        }

        $endpoint = '/api/v2/tickets/' . $ticketId . '/reply';
        $response = $this->callPost($endpoint, $payload);

        if (!isset($response['conversation']) || !is_array($response['conversation'])) {
            throw new RuntimeException(
                'Unexpected FreshService response from reply endpoint: '
                . json_encode($response)
            );
        }

        return $response['conversation'];
    }

    /**
     * POST `/api/v2/tickets/{id}/notes` with `private: true`.
     *
     * @return array<string, mixed>  The parsed `conversation` object.
     */
    public function postPrivateNote(int $ticketId, string $htmlBody): array
    {
        $payload = [
            'body' => $htmlBody,
            'private' => true,
        ];

        $endpoint = '/api/v2/tickets/' . $ticketId . '/notes';
        $response = $this->callPost($endpoint, $payload);

        if (!isset($response['conversation']) || !is_array($response['conversation'])) {
            throw new RuntimeException(
                'Unexpected FreshService response from notes endpoint: '
                . json_encode($response)
            );
        }

        return $response['conversation'];
    }

    /**
     * Invoke `python3 freshservice_api.py post <endpoint> <json>` and parse stdout JSON.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function callPost(string $endpoint, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to JSON-encode FreshService payload');
        }

        $process = new Process([
            'python3',
            self::HELPER_PATH,
            'post',
            $endpoint,
            $json,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'FreshService helper failed to launch: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'FreshService helper exited with code ' . $process->getExitCode()
                . ': ' . trim($stderr ?: $stdout)
            );
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'FreshService helper returned unparseable response. '
                . 'stdout: ' . trim($stdout) . ' | stderr: ' . trim($stderr)
            );
        }

        return $decoded;
    }
}
