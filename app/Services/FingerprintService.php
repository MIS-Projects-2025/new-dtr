<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FingerprintService
{
    // Ports tried in preference order — add or remove as needed
    const CANDIDATE_PORTS  = [8000, 8001, 8080, 8443, 9000, 9001];
    const CACHE_KEY        = 'secugen_active_port';
    const CACHE_TTL        = 300; // 5 minutes
    const STATUS_TIMEOUT_S = 2;
    const CAPTURE_BUFFER_S = 10;

    const ERROR_MAP = [
        1   => 'Fingerprint reader not correctly installed or driver error.',
        2   => 'Wrong type of fingerprint reader or not correctly installed.',
        54  => 'Timeout — no finger detected. Please try again.',
        55  => 'Device not found. Make sure the reader is plugged in.',
        59  => 'Device busy. Please wait and try again.',
        101 => 'Very low minutiae — press your finger firmly and try again.',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Port discovery (used internally and by the status endpoint)
    //
    // A short POST with Timeout=500 makes the agent reply immediately:
    //   ErrorCode=54  → agent alive, no finger yet        ✅
    //   ErrorCode=55  → agent alive, scanner unplugged    ⚠ (service found)
    //   ErrorCode=0   → agent alive, got a lucky capture  ✅
    //   connection refused → nothing on this port         ❌
    //   curl timeout  → agent IS alive but finger-wait is happening ✅
    // ─────────────────────────────────────────────────────────────────────────

    private function probePort(int $port): bool
    {
        try {
            $response = Http::timeout(self::STATUS_TIMEOUT_S)
                ->withoutVerifying()   // allow self-signed cert on :8443
                ->withHeaders([
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Origin'       => "http://localhost:{$port}",
                ])
                ->post("http://127.0.0.1:{$port}/SGIFPCapture",
                    'Timeout=500&Quality=50&licstr=&templateFormat=ISO&imageWSQRate=0.75'
                );

            $errorCode = $response->json('ErrorCode', -1);
            return in_array($errorCode, [0, 54, 55], true);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $msg = strtolower($e->getMessage());
            // cURL 28 = operation timed out — the agent is alive, waiting for a finger
            return str_contains($msg, 'timed out') || str_contains($msg, 'curl error 28');

        } catch (\Exception) {
            return false;
        }
    }

    private function discoverPort(): ?int
    {
        // Return cached port if still valid
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null && $this->probePort((int) $cached)) {
            return (int) $cached;
        }

        // Cache miss or cached port is dead — scan all candidates
        Cache::forget(self::CACHE_KEY);

        foreach (self::CANDIDATE_PORTS as $port) {
            if ($this->probePort($port)) {
                Cache::put(self::CACHE_KEY, $port, self::CACHE_TTL);
                Log::info("[FingerprintService] SecuGen found on port {$port}");
                return $port;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: device status check (called from the status endpoint)
    // Returns an array the controller can return directly as JSON
    // ─────────────────────────────────────────────────────────────────────────

    public function checkSecuGenStatus(): array
    {
        $port = $this->discoverPort();

        if ($port === null) {
            return [
                'connected' => false,
                'port'      => null,
                'message'   => 'SecuGen agent not found on any known port.',
            ];
        }

        // Also check if the physical scanner is plugged in
        try {
            $response = Http::timeout(self::STATUS_TIMEOUT_S)
                ->withoutVerifying()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->post("http://127.0.0.1:{$port}/SGIFPCapture",
                    'Timeout=500&Quality=50&licstr=&templateFormat=ISO&imageWSQRate=0.75'
                );

            $errorCode = $response->json('ErrorCode', -1);

            if ($errorCode === 55) {
                return [
                    'connected'     => false,
                    'port'          => $port,
                    'scanner_ready' => false,
                    'message'       => 'Agent found but scanner is not plugged in.',
                ];
            }

            return [
                'connected'     => true,
                'port'          => $port,
                'scanner_ready' => true,
                'message'       => 'SecuGen agent and scanner are ready.',
            ];

        } catch (\Exception) {
            return [
                'connected' => true,     // agent WAS found
                'port'      => $port,
                'scanner_ready' => true, // assume ready; cURL timeout = waiting for finger
                'message'   => 'Agent ready (waiting for finger).',
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Capture
    // ─────────────────────────────────────────────────────────────────────────

    public function capture(): array
    {
        $port = $this->discoverPort();

        if ($port === null) {
            throw new \RuntimeException(
                'SecuGen service not found. Make sure the SecuGen Biometric HTTP agent is running.'
            );
        }

        $curlTimeout = 15 + self::CAPTURE_BUFFER_S;

        try {
            $response = Http::timeout($curlTimeout)
                ->withoutVerifying()
                ->withHeaders([
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Origin'       => "http://localhost:{$port}",
                ])
                ->post("http://127.0.0.1:{$port}/SGIFPCapture",
                    'Timeout=15000&Quality=50&licstr=&templateFormat=ISO&imageWSQRate=0'
                );

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Cache::forget(self::CACHE_KEY);
            throw new \RuntimeException(
                "SecuGen service on port {$port} stopped responding. Please reconnect and try again."
            );
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SecuGen service returned HTTP ' . $response->status());
        }

        $data      = $response->json();
        $errorCode = $data['ErrorCode'] ?? -1;

        if ($errorCode !== 0) {
            throw new \RuntimeException(
                self::ERROR_MAP[$errorCode] ?? "SecuGen error code: {$errorCode}"
            );
        }

        if (empty($data['TemplateBase64'])) {
            throw new \RuntimeException('No template returned from SecuGen service.');
        }

        return [
            'template'     => $data['TemplateBase64'],
            'quality'      => $data['ImageQuality'] ?? 0,
            'image'        => $data['BMPBase64']    ?? null,
            'detectedPort' => $port,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utilities
    // ─────────────────────────────────────────────────────────────────────────

    public function forgetPort(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function getActivePort(): ?int
    {
        return $this->discoverPort();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SourceAFIS worker bridge (unchanged from original)
    // ─────────────────────────────────────────────────────────────────────────

    private function callWorker(array $payload): array
    {
        $scriptPath = config('fingerprint.script_path');

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException("Worker not found at: {$scriptPath}");
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(escapeshellarg($scriptPath), $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Could not launch fingerprint worker.');
        }

        $json   = json_encode($payload);
        $length = strlen($json);
        $offset = 0;

        while ($offset < $length) {
            $chunk   = substr($json, $offset, 8192);
            $written = fwrite($pipes[0], $chunk);
            if ($written === false) break;
            $offset += $written;
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if ($stderr) Log::warning('[FP Worker] ' . $stderr);

        $decoded = json_decode(trim($stdout), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Worker returned invalid JSON: ' . $stdout);
        }
        if (isset($decoded['error'])) {
            throw new \RuntimeException('Worker error: ' . $decoded['error']);
        }

        return $decoded;
    }

    public function extractFmd(string $base64Png): string
    {
        $base64Png = strtr(trim($base64Png), '-_', '+/');
        $base64Png = str_pad(
            $base64Png,
            strlen($base64Png) + (4 - strlen($base64Png) % 4) % 4,
            '='
        );

        $result = $this->callWorker([
            'action' => 'extract',
            'image'  => $base64Png,
            'dpi'    => config('fingerprint.dpi'),
        ]);

        return $result['fmd'];
    }

    public function extractFmdFromIso(string $base64IsoTemplate): string
    {
        $result = $this->callWorker([
            'action'   => 'extract_iso',
            'template' => $base64IsoTemplate,
        ]);
        return $result['fmd'];
    }

    public function matchFmd(string $probePng, array $candidates): array
    {
        $probePng  = strtr($probePng, '-_', '+/');
        $isSecuGen = isset($candidates[0]['device_type'])
            && str_contains(strtolower($candidates[0]['device_type'] ?? ''), 'secugen');

        $result = $this->callWorker([
            'action'     => $isSecuGen ? 'match_iso' : 'match',
            'probe'      => $probePng,
            'candidates' => $candidates,
            'dpi'        => config('fingerprint.dpi'),
        ]);

        return $result['scores'];
    }

    public function matchWithFmd(string $probeFmd, array $candidates): array
    {
        $result = $this->callWorker([
            'action'     => 'match_fmd',
            'probe_fmd'  => $probeFmd,
            'candidates' => $candidates,
        ]);

        return $result['scores'];
    }
}