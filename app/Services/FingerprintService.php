<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class FingerprintService
{
    const CANDIDATE_PORTS       = [8000, 8001, 8080, 8443, 9000, 9001];
    const CACHE_KEY             = 'secugen_active_port';
    const CACHE_TTL             = 300; // 5 minutes
    const STATUS_TIMEOUT_S      = 2;   // short probe to detect port
    const CAPTURE_BUFFER_S      = 10;  // added on top of SecuGen Timeout param

    const ERROR_MAP = [
        1   => 'Fingerprint reader not correctly installed or driver error.',
        2   => 'Wrong type of fingerprint reader or not correctly installed.',
        54  => 'Timeout — no finger detected. Please try again.',
        55  => 'Device not found. Make sure the HU20-A reader is plugged in.',
        59  => 'Device busy. Please wait and try again.',
        101 => 'Very low minutiae — press your finger firmly and try again.',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Port discovery
    // Tries each candidate port with a short timeout.
    //   timeout (curl error 28) → service IS running, waiting for finger ✅
    //   connection refused      → nothing on this port, try next ❌
    // ─────────────────────────────────────────────────────────────────────────

    private function discoverPort(): ?int
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) return (int) $cached;

        foreach (self::CANDIDATE_PORTS as $port) {
            try {
                Http::timeout(self::STATUS_TIMEOUT_S)
                    ->withHeaders(['Origin' => "http://localhost:{$port}"])
                    ->get("http://127.0.0.1:{$port}/SGIFPCapture", [
                        'Timeout' => 10000,
                        'Quality' => 50,
                    ]);

                // Got a real response — service is on this port
                Cache::put(self::CACHE_KEY, $port, self::CACHE_TTL);
                return $port;

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $msg = strtolower($e->getMessage());

                if (str_contains($msg, 'timed out') || str_contains($msg, 'curl error 28')) {
                    // Timeout = service IS running here, waiting for finger
                    Cache::put(self::CACHE_KEY, $port, self::CACHE_TTL);
                    return $port;
                }

                // Connection refused = nothing on this port, try next
                continue;

            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Capture a fingerprint from the connected device.
    // ─────────────────────────────────────────────────────────────────────────

    public function capture(): array
    {
        $port = $this->discoverPort();

        if ($port === null) {
            throw new \RuntimeException(
                'SecuGen service not found. Make sure "SecuGen Biometric HTTP" is running.'
            );
        }

        $curlTimeout = 15 + self::CAPTURE_BUFFER_S;

        try {
            $response = Http::timeout($curlTimeout)
                ->withHeaders(['Origin' => "http://localhost:{$port}"])
                ->get("http://127.0.0.1:{$port}/SGIFPCapture", [
                    'Timeout'        => 15000,
                    'Quality'        => 50,
                    'TemplateFormat' => 'ISO',
                    'ImageWSQRate'   => 0,
                ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Port went away — clear cache so next request rediscovers
            Cache::forget(self::CACHE_KEY);

            throw new \RuntimeException(
                "SecuGen service on port {$port} stopped responding. Please reconnect and try again."
            );
        }

        if (!$response->successful()) {
            throw new \RuntimeException(
                'SecuGen service returned HTTP ' . $response->status()
            );
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
    // Match two ISO templates.
    // ─────────────────────────────────────────────────────────────────────────

    public function match(string $template1Base64, string $template2Base64): array
    {
        $port = $this->discoverPort();

        if ($port === null) {
            throw new \RuntimeException('SecuGen service not found.');
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Origin' => "http://localhost:{$port}"])
                ->post("http://127.0.0.1:{$port}/SGIMatchScore", [
                    'Template1'      => $template1Base64,
                    'Template2'      => $template2Base64,
                    'TemplateFormat' => 'ISO',
                ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Cache::forget(self::CACHE_KEY);
            throw new \RuntimeException('Match service did not respond.');
        }

        if (!$response->successful()) {
            throw new \RuntimeException('Match service returned HTTP ' . $response->status());
        }

        $data  = $response->json();
        $score = $data['MatchingScore'] ?? 0;

        return [
            'match' => $score >= 40,
            'score' => $score,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Force rediscovery (call this from a status-check endpoint)
    // ─────────────────────────────────────────────────────────────────────────

    public function forgetPort(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function getActivePort(): ?int
    {
        return $this->discoverPort();
    }

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

    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    if ($stderr) \Log::warning('[FP Worker] ' . $stderr);

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
    // Normalize URL-safe base64 → standard base64
    $base64Png = strtr($base64Png, '-_', '+/');

    $result = $this->callWorker([
        'action' => 'extract',
        'image'  => $base64Png,
        'dpi'    => config('fingerprint.dpi'),
    ]);
    return $result['fmd'];
}

public function matchFmd(string $probePng, array $candidates): array
{
    // Normalize URL-safe base64 → standard base64
    $probePng = strtr($probePng, '-_', '+/');

    $result = $this->callWorker([
        'action'     => 'match',
        'probe'      => $probePng,
        'candidates' => $candidates,
        'dpi'        => config('fingerprint.dpi'),
    ]);
    return $result['scores'];
}

}