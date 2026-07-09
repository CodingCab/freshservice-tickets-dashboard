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
     * PUT `/api/v2/tickets/{id}` to set the ticket's status.
     *
     * FreshService status codes: 2=Open, 3=Pending, 4=Resolved, 5=Closed.
     *
     * @return array<string, mixed>  The parsed `ticket` object.
     */
    public function setStatus(int $ticketId, int $statusCode): array
    {
        $endpoint = '/api/v2/tickets/' . $ticketId;
        $response = $this->callPut($endpoint, ['status' => $statusCode]);

        if (!isset($response['ticket']) || !is_array($response['ticket'])) {
            throw new RuntimeException(
                'Unexpected FreshService response from ticket update endpoint: '
                . json_encode($response)
            );
        }

        return $response['ticket'];
    }

    /**
     * Backwards-compatible shorthand for `setStatus($id, 5)` (Closed).
     *
     * @return array<string, mixed>  The parsed `ticket` object.
     */
    public function closeTicket(int $ticketId): array
    {
        return $this->setStatus($ticketId, 5);
    }

    /**
     * GET `<endpoint>` — used by the dashboard to fetch ticket/conversation
     * HTML payloads for inline-image rendering.
     *
     * @return array<string, mixed>
     */
    public function get(string $endpoint): array
    {
        $process = new Process([
            'python3',
            self::HELPER_PATH,
            'get',
            $endpoint,
        ], null, $this->helperEnv());
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
        ], null, $this->helperEnv());
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

    /**
     * Invoke `python3 freshservice_api.py put <endpoint> <json>` and parse stdout JSON.
     *
     * Mirrors callPost() — only the helper subcommand differs.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function callPut(string $endpoint, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to JSON-encode FreshService payload');
        }

        $process = new Process([
            'python3',
            self::HELPER_PATH,
            'put',
            $endpoint,
            $json,
        ], null, $this->helperEnv());
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

    // Stable, pw-free creds file shared by every worker account. Same source
    // the Python helper reads. Used as the fallback when Laravel's env() yields
    // nothing — which happens whenever config is cached (`php artisan
    // config:cache` makes env() return null outside config files) or the keys
    // were never added to .env. This makes credential resolution immune to both.
    private const SECRETS_FILE = '/shared/secrets/freshservice.env';

    /**
     * Build the env array passed to the Python helper. The helper requires
     * FRESHSERVICE_DOMAIN / FRESHSERVICE_API_KEY in its environment (under
     * php-fpm there is no TTY, so its own interactive password-manager fallback
     * EOFs — we MUST supply both vars).
     *
     * Resolution order, robust against config caching and .env gaps:
     *   1. Laravel env() (works when config is NOT cached and .env has them).
     *   2. The stable, pw-free secrets file at /shared/secrets/freshservice.env.
     *
     * @return array<string, string>
     */
    private function helperEnv(): array
    {
        $domain = (string) env('FRESHSERVICE_DOMAIN', '');
        $apiKey = (string) env('FRESHSERVICE_API_KEY', '');

        if ($domain === '' || $apiKey === '') {
            [$fileDomain, $fileApiKey] = $this->credsFromSecretsFile();
            $domain = $domain !== '' ? $domain : $fileDomain;
            $apiKey = $apiKey !== '' ? $apiKey : $fileApiKey;
        }

        if ($domain === '' || $apiKey === '') {
            throw new RuntimeException(
                'FreshService credentials missing: add FRESHSERVICE_DOMAIN and '
                . 'FRESHSERVICE_API_KEY to the dashboard .env file (then '
                . '`php artisan config:clear`), or to ' . self::SECRETS_FILE . '.'
            );
        }

        return [
            'FRESHSERVICE_DOMAIN'  => $domain,
            'FRESHSERVICE_API_KEY' => $apiKey,
        ];
    }

    /**
     * Parse FRESHSERVICE_DOMAIN / FRESHSERVICE_API_KEY from the stable secrets
     * file. Returns ['', ''] if the file is absent/unreadable/incomplete.
     *
     * @return array{0: string, 1: string}
     */
    private function credsFromSecretsFile(): array
    {
        $domain = '';
        $apiKey = '';
        if (!is_readable(self::SECRETS_FILE)) {
            return [$domain, $apiKey];
        }
        foreach (file(self::SECRETS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k === 'FRESHSERVICE_DOMAIN') {
                $domain = $v;
            } elseif ($k === 'FRESHSERVICE_API_KEY') {
                $apiKey = $v;
            }
        }

        return [$domain, $apiKey];
    }
}
