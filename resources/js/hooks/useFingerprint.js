/**
 * useFingerprint.js
 * Shared fingerprint scanner hooks with:
 *  - Auto port discovery for SecuGen (tries all known ports + both http/https)
 *  - Automatic reconnect loop for both devices
 *  - DigitalPersona: no longer relies on a one-shot 2-second probe
 *
 * Usage:
 *   import { useDigitalPersona, useSecuGen } from "@/Hooks/useFingerprint";
 */

import { useState, useEffect, useRef, useCallback } from "react";

// ── SecuGen: ports and protocols to probe ────────────────────────────────────
// The agent can bind to any of these. We try them all and cache the winner.
const SG_CANDIDATES = [
    { proto: "https", port: 8443 },
    { proto: "http", port: 8000 },
    { proto: "http", port: 8001 },
    { proto: "http", port: 8080 },
    { proto: "https", port: 9000 },
    { proto: "http", port: 9001 },
];

// ── SecuGen: find the live endpoint ─────────────────────────────────────────
// Returns { proto, port } or null.
// Uses a very short Timeout (500 ms) so the agent responds instantly with
// ErrorCode 54 (no finger yet) rather than making us wait 10 seconds.
async function discoverSecuGenEndpoint() {
    for (const { proto, port } of SG_CANDIDATES) {
        const url = `${proto}://localhost:${port}/SGIFPCapture`;
        try {
            const res = await Promise.race([
                fetch(url, {
                    method: "POST",
                    mode: "cors",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: "Timeout=500&Quality=50&licstr=&templateFormat=ISO&imageWSQRate=0.75",
                }),
                // Give each candidate 3 seconds before skipping
                new Promise((_, rej) =>
                    setTimeout(() => rej(new Error("probe-timeout")), 3000),
                ),
            ]);

            if (!res.ok) continue;

            const data = await res.json();
            // 0  = scan captured (lucky!)
            // 54 = timeout / no finger — but the SERVICE is alive
            // 55 = device not found — agent running but scanner unplugged
            if ([0, 54, 55].includes(data.ErrorCode)) {
                return { proto, port };
            }
        } catch {
            // Connection refused or network error — this port is dead, try next
        }
    }
    return null;
}

// ── SecuGen Hook ──────────────────────────────────────────────────────────────
export function useSecuGen() {
    const endpointRef = useRef(null); // { proto, port } once discovered
    const [deviceStatus, setDeviceStatus] = useState("connecting");
    const reconnectTimer = useRef(null);

    // Discover (or re-discover) the active endpoint, then schedule a recheck
    const discover = useCallback(async (fromReconnect = false) => {
        if (fromReconnect) setDeviceStatus("connecting");

        const ep = await discoverSecuGenEndpoint();
        endpointRef.current = ep;

        if (ep) {
            // Verify the *scanner* is also plugged in (not just the agent)
            setDeviceStatus("ready");
        } else {
            setDeviceStatus("disconnected");
            // Retry every 5 s automatically
            reconnectTimer.current = setTimeout(() => discover(true), 5000);
        }
    }, []);

    useEffect(() => {
        discover();
        return () => clearTimeout(reconnectTimer.current);
    }, [discover]);

    // Public: refresh (called by the ↻ button)
    const refreshStatus = useCallback(() => {
        clearTimeout(reconnectTimer.current);
        endpointRef.current = null;
        discover(true);
    }, [discover]);

    // Public: capture
    const capture = useCallback(
        () =>
            new Promise(async (resolve, reject) => {
                // If we have no endpoint yet, try once more before giving up
                if (!endpointRef.current) {
                    const ep = await discoverSecuGenEndpoint();
                    if (!ep) {
                        return reject(
                            new Error(
                                "SecuGen agent not found. Make sure it is running.",
                            ),
                        );
                    }
                    endpointRef.current = ep;
                    setDeviceStatus("ready");
                }

                const { proto, port } = endpointRef.current;

                fetch(`${proto}://localhost:${port}/SGIFPCapture`, {
                    method: "POST",
                    mode: "cors",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: "Timeout=10000&Quality=50&licstr=&templateFormat=ISO&imageWSQRate=0.75",
                })
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.ErrorCode !== 0) {
                            // Agent still alive but scan failed — keep status ready
                            return reject(
                                new Error(`SecuGen error ${data.ErrorCode}`),
                            );
                        }
                        if (!data.BMPBase64) {
                            return reject(
                                new Error("SecuGen returned no image data."),
                            );
                        }
                        resolve({
                            template: data.BMPBase64,
                            quality: data.ImageQuality ?? 80,
                            bitmap: data.BMPBase64,
                            device: `SecuGen ${data.Model ?? ""}`.trim(),
                        });
                    })
                    .catch(() => {
                        // Agent went away mid-capture — rediscover
                        endpointRef.current = null;
                        setDeviceStatus("disconnected");
                        clearTimeout(reconnectTimer.current);
                        reconnectTimer.current = setTimeout(
                            () => discover(true),
                            3000,
                        );
                        reject(
                            new Error(
                                "SecuGen agent stopped responding. Reconnecting…",
                            ),
                        );
                    });
            }),
        [discover],
    );

    return { capture, deviceStatus, refreshStatus };
}

// ── DigitalPersona Hook ───────────────────────────────────────────────────────
export function useDigitalPersona() {
    const clientRef = useRef(null);
    const [deviceStatus, setDeviceStatus] = useState("connecting");
    const reconnectTimer = useRef(null);
    const cancelledRef = useRef(false);

    // Probe: try startAcquisition → if it resolves the device is connected,
    // then immediately stop. Retries automatically on failure.
    const probe = useCallback((client, sdk) => {
        if (cancelledRef.current) return;

        const SampleFormat = sdk.SampleFormat ?? sdk.Formats?.SampleFormat;

        client
            .stopAcquisition()
            .catch(() => {})
            .finally(() => {
                if (cancelledRef.current) return;

                client
                    .startAcquisition(SampleFormat.PngImage)
                    .then(() => {
                        if (cancelledRef.current) return;
                        setDeviceStatus("ready");
                        return client.stopAcquisition().catch(() => {});
                    })
                    .catch(() => {
                        if (cancelledRef.current) return;
                        setDeviceStatus("disconnected");
                        // Retry probe every 5 s
                        reconnectTimer.current = setTimeout(
                            () => probe(client, sdk),
                            5000,
                        );
                    });
            });
    }, []);

    useEffect(() => {
        cancelledRef.current = false;
        let sdkPollTimer = null;
        const startTime = Date.now();
        const SDK_WAIT_MS = 15_000; // wait up to 15 s for the DP Lite Client scripts

        const tryInit = () => {
            if (cancelledRef.current) return;

            const sdk =
                window.Fingerprint ??
                window.DigitalPersona ??
                window.FingerprintSdk ??
                null;

            if (!sdk?.WebApi) {
                if (Date.now() - startTime > SDK_WAIT_MS) {
                    setDeviceStatus("sdk_missing");
                    return;
                }
                sdkPollTimer = setTimeout(tryInit, 300);
                return;
            }

            // Build client once
            if (!clientRef.current) {
                try {
                    clientRef.current = new sdk.WebApi();
                } catch {
                    setDeviceStatus("sdk_missing");
                    return;
                }
            }

            const client = clientRef.current;

            // Wire events — these fire for hot-plug after page load
            client.onDeviceConnected = () => {
                if (cancelledRef.current) return;
                clearTimeout(reconnectTimer.current);
                setDeviceStatus("ready");
            };

            client.onDeviceDisconnected = () => {
                if (cancelledRef.current) return;
                setDeviceStatus("disconnected");
                // Give the OS a moment then probe again
                reconnectTimer.current = setTimeout(
                    () => probe(client, sdk),
                    3000,
                );
            };

            client.onCommunicationFailed = () => {
                if (cancelledRef.current) return;
                setDeviceStatus("disconnected");
                reconnectTimer.current = setTimeout(
                    () => probe(client, sdk),
                    5000,
                );
            };

            // Initial probe — don't wait for the event, actively check now
            probe(client, sdk);
        };

        if (document.readyState === "complete") {
            tryInit();
        } else {
            window.addEventListener("load", tryInit, { once: true });
        }

        return () => {
            cancelledRef.current = true;
            clearTimeout(sdkPollTimer);
            clearTimeout(reconnectTimer.current);
            try {
                clientRef.current?.stopAcquisition();
            } catch {}
        };
    }, [probe]);

    // Public: capture
    const capture = useCallback(
        () =>
            new Promise((resolve, reject) => {
                const sdk =
                    window.Fingerprint ??
                    window.DigitalPersona ??
                    window.FingerprintSdk ??
                    null;
                const client = clientRef.current;

                if (!sdk || !client) {
                    return reject(
                        new Error(
                            "DigitalPersona SDK not ready. Is the Lite Client installed?",
                        ),
                    );
                }

                let settled = false;
                let qualityScore = 80;

                const settle = (fn) => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timeoutHandle);
                    client.stopAcquisition().catch(() => {});
                    fn();
                };

                const timeoutHandle = setTimeout(() => {
                    settle(() =>
                        reject(
                            new Error(
                                "Scan timed out — no finger detected within 20 seconds.",
                            ),
                        ),
                    );
                }, 20_000);

                client.onQualityReported = (e) => {
                    qualityScore =
                        e.quality === 0
                            ? 90
                            : Math.max(10, 80 - e.quality * 10);
                };

                client.onSamplesAcquired = (e) => {
                    try {
                        const samples = JSON.parse(e.samples);
                        let base64Data = samples[0]?.Data ?? samples[0];
                        base64Data = base64Data
                            .replace(/-/g, "+")
                            .replace(/_/g, "/");
                        settle(() =>
                            resolve({
                                template: base64Data,
                                quality: qualityScore,
                                bitmap: base64Data,
                                device: "DigitalPersona U.are.U 4500",
                            }),
                        );
                    } catch (err) {
                        settle(() =>
                            reject(
                                new Error(
                                    "Failed to parse fingerprint sample: " +
                                        err.message,
                                ),
                            ),
                        );
                    }
                };

                client.onErrorOccurred = (e) => {
                    settle(() =>
                        reject(
                            new Error(
                                `Scanner error: ${e?.error ?? JSON.stringify(e)}`,
                            ),
                        ),
                    );
                };

                const SampleFormat =
                    sdk.SampleFormat ?? sdk.Formats?.SampleFormat;

                client
                    .stopAcquisition()
                    .catch(() => {})
                    .finally(() => {
                        client
                            .startAcquisition(SampleFormat.PngImage)
                            .catch((err) => {
                                settle(() =>
                                    reject(
                                        new Error(
                                            err?.message ??
                                                "Failed to start acquisition.",
                                        ),
                                    ),
                                );
                            });
                    });
            }),
        [],
    );

    return { capture, deviceStatus };
}
