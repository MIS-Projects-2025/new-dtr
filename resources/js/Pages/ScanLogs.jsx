import { useState, useEffect, useRef, useCallback } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import axios from "axios";

// ── Constants ─────────────────────────────────────────────────────────────────

const LOG_TYPES = [
    { key: "check_in", label: "Time In", color: "green" },
    { key: "break_out1", label: "Break Out 1", color: "amber" },
    { key: "break_in1", label: "Break In 1", color: "amber" },
    { key: "lunch_out", label: "Lunch Out", color: "orange" },
    { key: "lunch_in", label: "Lunch In", color: "orange" },
    { key: "break_out2", label: "Break Out 2", color: "amber" },
    { key: "break_in2", label: "Break In 2", color: "amber" },
    { key: "check_out", label: "Time Out", color: "red" },
];

const LOG_COLOR = {
    green: "border-green-400  bg-green-50  dark:bg-green-900/30  text-green-700  dark:text-green-300",
    amber: "border-amber-400  bg-amber-50  dark:bg-amber-900/30  text-amber-700  dark:text-amber-300",
    orange: "border-orange-400 bg-orange-50 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300",
    red: "border-red-400    bg-red-50    dark:bg-red-900/30    text-red-700    dark:text-red-300",
};

const LOG_COLOR_SELECTED = {
    green: "border-green-500  bg-green-500  text-white",
    amber: "border-amber-500  bg-amber-500  text-white",
    orange: "border-orange-500 bg-orange-500 text-white",
    red: "border-red-500    bg-red-500    text-white",
};

// ── DigitalPersona hook (same as RegisterFingerprint) ─────────────────────────

function useDigitalPersona() {
    const clientRef = useRef(null);
    const deviceRef = useRef(null);
    const [deviceStatus, setDeviceStatus] = useState("connecting");

    useEffect(() => {
        let cancelled = false;
        let pollTimer = null;

        const tryInit = () => {
            if (cancelled) return;
            const sdk = window.Fingerprint ?? window.DigitalPersona ?? null;
            if (!sdk?.WebApi) {
                pollTimer = setTimeout(tryInit, 300);
                return;
            }

            let client;
            try {
                client = new sdk.WebApi();
            } catch {
                setDeviceStatus("sdk_missing");
                return;
            }
            clientRef.current = client;

            client.onDeviceConnected = (e) => {
                deviceRef.current =
                    e?.deviceUid ?? e?.deviceID ?? e?.uid ?? "auto";
                setDeviceStatus("ready");
            };
            client.onDeviceDisconnected = () => {
                deviceRef.current = null;
                setDeviceStatus("disconnected");
            };
            client.onCommunicationFailed = (e) => {
                console.warn("[DP] Communication failed:", e);
                setDeviceStatus("disconnected");
            };

            // One-time probe after 2s — only if onDeviceConnected hasn't fired yet
            setTimeout(() => {
                if (deviceRef.current !== null || cancelled) return;
                const SampleFormat =
                    sdk.SampleFormat ?? sdk.Formats?.SampleFormat;
                client
                    .startAcquisition(SampleFormat.PngImage)
                    .then(() => {
                        deviceRef.current = "auto";
                        setDeviceStatus("ready");
                        return client.stopAcquisition();
                    })
                    .catch(() => {
                        if (!cancelled) setDeviceStatus("disconnected");
                    });
            }, 2000);
        };

        if (document.readyState === "complete") tryInit();
        else window.addEventListener("load", tryInit, { once: true });

        return () => {
            cancelled = true;
            clearTimeout(pollTimer);
            try {
                clientRef.current?.stopAcquisition();
            } catch {}
        };
    }, []);

    const capture = useCallback(
        () =>
            new Promise((resolve, reject) => {
                const sdk = window.Fingerprint ?? window.DigitalPersona ?? null;
                const client = clientRef.current;
                if (!sdk || !client)
                    return reject(new Error("Scanner not ready."));

                let qualityScore = 80;
                let settled = false;

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
                }, 20000);

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

// ── SecuGen Hook ──────────────────────────────────────────────────────────────

function useSecuGen() {
    const [deviceStatus, setDeviceStatus] = useState("connecting");

    useEffect(() => {
        fetch("http://localhost:8000/SGIFPCapture?Timeout=1000&Quality=50", {
            mode: "cors",
        })
            .then(() => setDeviceStatus("ready"))
            .catch(() => setDeviceStatus("disconnected"));
    }, []);

    const capture = useCallback(
        () =>
            new Promise((resolve, reject) => {
                fetch(
                    "http://localhost:8000/SGIFPCapture?Timeout=15000&Quality=50&TemplateFormat=ISO&ImageWSQRate=0",
                    { mode: "cors" },
                )
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.ErrorCode !== 0)
                            return reject(
                                new Error(`SecuGen error ${data.ErrorCode}`),
                            );
                        const imageData =
                            data.BMPBase64 ?? data.ImageDataBase64;
                        if (!imageData)
                            return reject(
                                new Error("SecuGen returned no image data."),
                            );
                        resolve({
                            template: imageData,
                            quality: data.ImageQuality ?? 80,
                            bitmap: data.BMPBase64,
                            device: `SecuGen ${data.Model ?? ""}`.trim(),
                        });
                    })
                    .catch(() =>
                        reject(
                            new Error(
                                "SecuGen service not reachable. Is SgiBioSrv running?",
                            ),
                        ),
                    );
            }),
        [],
    );

    return { capture, deviceStatus };
}

// ── Sub-components ────────────────────────────────────────────────────────────

function FingerprintIcon({ className }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.4"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M12 2C6.477 2 2 6.477 2 12" />
            <path d="M12 6c3.314 0 6 2.686 6 6" />
            <path d="M12 10a2 2 0 0 1 2 2c0 3-3 6-3 6" />
            <path d="M12 10a2 2 0 0 0-2 2c0 3 2 5 2 8" />
            <path d="M8 12a4 4 0 0 1 8 0" />
            <path d="M5 12a7 7 0 0 1 14 0" />
            <path d="M2 12a10 10 0 0 1 20 0" />
        </svg>
    );
}

function DeviceBadge({ status }) {
    const map = {
        ready: {
            label: "🟢 Scanner Ready",
            cls: "bg-green-50 dark:bg-green-900/20 text-green-600 border-green-200",
        },
        connecting: {
            label: "🟡 Connecting…",
            cls: "bg-amber-50  dark:bg-amber-900/20  text-amber-600  border-amber-200",
        },
        disconnected: {
            label: "🔴 Disconnected",
            cls: "bg-red-50   dark:bg-red-900/20   text-red-600   border-red-200",
        },
        sdk_missing: {
            label: "⚠ SDK not loaded",
            cls: "bg-red-50   dark:bg-red-900/20   text-red-600   border-red-200",
        },
    };
    const { label, cls } = map[status] ?? map.connecting;
    return (
        <span
            className={`text-[9px] font-semibold px-2 py-1 rounded-full border ${cls}`}
        >
            {label}
        </span>
    );
}

function ResultCard({ result, onReset }) {
    if (!result) return null;

    const isMatch = result.matched && !result.duplicate;
    const isDuplicate = result.matched && result.duplicate;
    const logLabel =
        LOG_TYPES.find((l) => l.key === result.logType)?.label ??
        result.logType;

    return (
        <div
            className={`rounded-xl border-2 p-5 flex flex-col items-center gap-3 text-center transition-all
            ${
                isMatch
                    ? "border-green-400 bg-green-50 dark:bg-green-900/20"
                    : isDuplicate
                      ? "border-amber-400  bg-amber-50  dark:bg-amber-900/20"
                      : "border-red-400    bg-red-50    dark:bg-red-900/20"
            }`}
        >
            {/* Icon */}
            <div
                className={`w-12 h-12 rounded-full flex items-center justify-center text-2xl
                ${isMatch ? "bg-green-100 dark:bg-green-800" : isDuplicate ? "bg-amber-100 dark:bg-amber-800" : "bg-red-100 dark:bg-red-800"}`}
            >
                {isMatch ? "✓" : isDuplicate ? "⚠" : "✗"}
            </div>

            {/* Status */}
            <p
                className={`text-sm font-bold
                ${isMatch ? "text-green-700 dark:text-green-300" : isDuplicate ? "text-amber-700 dark:text-amber-300" : "text-red-700 dark:text-red-300"}`}
            >
                {isMatch
                    ? "Match Found"
                    : isDuplicate
                      ? "Already Logged"
                      : "No Match"}
            </p>

            {/* Employee info */}
            {result.employee && (
                <div className="flex flex-col gap-0.5">
                    <p className="text-base font-semibold text-zinc-800 dark:text-zinc-100">
                        {result.employee.name}
                    </p>
                    <p className="text-[10px] text-zinc-500 dark:text-zinc-400">
                        {result.employee.employid} ·{" "}
                        {result.employee.department}
                    </p>
                    {result.employee.finger && (
                        <p className="text-[9px] text-zinc-400 dark:text-zinc-500 mt-0.5">
                            Matched: {result.employee.finger}
                        </p>
                    )}
                </div>
            )}

            {/* Log type + time */}
            {isMatch && result.log && (
                <div className="flex items-center gap-2">
                    <span className="text-[10px] font-bold px-2 py-1 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300">
                        {logLabel}
                    </span>
                    <span className="text-[10px] text-zinc-500 dark:text-zinc-400">
                        {result.log.logged_at}
                    </span>
                </div>
            )}

            {/* Score */}
            {result.score > 0 && (
                <p className="text-[9px] text-zinc-400 dark:text-zinc-500">
                    Similarity: {result.score}%
                </p>
            )}

            {/* Message for no-match / duplicate */}
            {!isMatch && (
                <p className="text-[10px] text-zinc-500 dark:text-zinc-400 italic">
                    {result.message}
                </p>
            )}

            {/* Reset button */}
            <button
                onClick={onReset}
                className="mt-1 px-4 py-1.5 rounded-lg text-[11px] font-semibold bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:opacity-80 active:scale-95 transition-all"
            >
                Scan Again
            </button>
        </div>
    );
}

function RecentLog({ log }) {
    const label =
        LOG_TYPES.find((l) => l.key === log.log_type)?.label ?? log.log_type;
    return (
        <div className="flex items-center justify-between px-3 py-2 rounded-lg bg-zinc-50 dark:bg-zinc-800 border border-zinc-100 dark:border-zinc-700">
            <div className="min-w-0">
                <p className="text-[10px] font-semibold text-zinc-700 dark:text-zinc-200 truncate">
                    {log.employee_name}
                </p>
                <p className="text-[8px] text-zinc-400 dark:text-zinc-500 truncate">
                    {log.department}
                </p>
            </div>
            <div className="flex items-center gap-2 flex-shrink-0 ml-2">
                <span className="text-[8px] font-bold px-1.5 py-0.5 rounded bg-zinc-200 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300">
                    {label}
                </span>
                <span className="text-[8px] text-zinc-400 dark:text-zinc-500 w-10 text-right">
                    {log.logged_at
                        ? new Date(log.logged_at).toLocaleTimeString([], {
                              hour: "2-digit",
                              minute: "2-digit",
                          })
                        : ""}
                </span>
            </div>
        </div>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function ScanLog() {
    const { app_name } = usePage().props;

    const [scannerType, setScannerType] = useState("digitalpersona");
    const dp = useDigitalPersona();
    const secugen = useSecuGen();
    const { capture, deviceStatus } = scannerType === "secugen" ? secugen : dp;

    const [selectedLogType, setSelectedLogType] = useState("check_in");
    const [scanState, setScanState] = useState("idle"); // idle | scanning | verifying | done
    const [result, setResult] = useState(null);
    const [errorMsg, setErrorMsg] = useState("");
    const [recentLogs, setRecentLogs] = useState([]);
    const [clock, setClock] = useState(new Date());

    // Clock ticker
    useEffect(() => {
        const t = setInterval(() => setClock(new Date()), 1000);
        return () => clearInterval(t);
    }, []);

    // Fetch recent logs on mount and after each successful scan
    const fetchRecent = useCallback(async () => {
        try {
            const res = await axios.get(`/${app_name}/scan-logs/recent`);
            setRecentLogs(res.data ?? []);
        } catch {}
    }, [app_name]);

    useEffect(() => {
        fetchRecent();
    }, [fetchRecent]);

    const handleScan = useCallback(async () => {
        if (deviceStatus !== "ready") {
            setErrorMsg(
                "Scanner not ready. Check the DigitalPersona Lite Client.",
            );
            return;
        }
        setErrorMsg("");
        setResult(null);
        setScanState("scanning");

        try {
            const captured = await capture();

            setScanState("verifying");

            const res = await axios.post(`/${app_name}/scan-logs/verify`, {
                template_data: captured.template,
                fmd_data: captured.fmd,
                log_type: selectedLogType,
                quality: captured.quality,
                device_type: captured.device,
            });

            setResult({ ...res.data, logType: selectedLogType });
            setScanState("done");

            if (res.data.matched) fetchRecent();
        } catch (err) {
            setErrorMsg(
                err.response?.data?.message ?? err.message ?? "Scan failed.",
            );
            setScanState("idle");
        }
    }, [capture, deviceStatus, selectedLogType, app_name, fetchRecent]);

    const handleReset = useCallback(() => {
        setResult(null);
        setScanState("idle");
        setErrorMsg("");
    }, []);

    const selectedColor =
        LOG_TYPES.find((l) => l.key === selectedLogType)?.color ?? "green";

    return (
        <AuthenticatedLayout>
            <Head title="Scan Logs" />

            <style>{`
                @keyframes scanline {
                    0%   { top: 0%; }
                    50%  { top: 100%; }
                    100% { top: 0%; }
                }
            `}</style>

            <div className="h-full flex flex-col overflow-hidden p-4 gap-4">
                {/* Header */}
                <div className="flex items-center justify-between flex-shrink-0">
                    <div>
                        <h1 className="text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                            Fingerprint Attendance
                        </h1>
                        <p className="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">
                            {clock.toLocaleDateString("en-US", {
                                weekday: "long",
                                month: "long",
                                day: "numeric",
                                year: "numeric",
                            })}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {/* Scanner toggle */}
                        <div className="flex items-center gap-0.5 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-0.5">
                            <button
                                onClick={() => setScannerType("digitalpersona")}
                                className={`px-2.5 py-1 rounded-md text-[9px] font-semibold transition-all
                                    ${
                                        scannerType === "digitalpersona"
                                            ? "bg-white dark:bg-zinc-700 text-zinc-800 dark:text-zinc-100 shadow-sm"
                                            : "text-zinc-400 dark:text-zinc-500 hover:text-zinc-600 dark:hover:text-zinc-300"
                                    }`}
                            >
                                HID DigitalPersona
                            </button>
                            <button
                                onClick={() => setScannerType("secugen")}
                                className={`px-2.5 py-1 rounded-md text-[9px] font-semibold transition-all
                                    ${
                                        scannerType === "secugen"
                                            ? "bg-white dark:bg-zinc-700 text-zinc-800 dark:text-zinc-100 shadow-sm"
                                            : "text-zinc-400 dark:text-zinc-500 hover:text-zinc-600 dark:hover:text-zinc-300"
                                    }`}
                            >
                                SecuGen
                            </button>
                        </div>
                        <DeviceBadge status={deviceStatus} />
                        <span className="text-lg font-bold tabular-nums text-zinc-700 dark:text-zinc-200">
                            {clock.toLocaleTimeString([], {
                                hour: "2-digit",
                                minute: "2-digit",
                                second: "2-digit",
                            })}
                        </span>
                    </div>
                </div>

                {/* Main content */}
                <div className="flex-1 grid grid-cols-[1fr_300px] gap-4 min-h-0 overflow-hidden">
                    {/* LEFT — Scanner panel */}
                    <div className="flex flex-col gap-4 overflow-auto">
                        {/* Log type selector */}
                        <div className="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                            <p className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest mb-3">
                                Select Log Type
                            </p>
                            <div className="grid grid-cols-4 gap-2">
                                {LOG_TYPES.map((lt) => {
                                    const isSelected =
                                        selectedLogType === lt.key;
                                    return (
                                        <button
                                            key={lt.key}
                                            onClick={() => {
                                                setSelectedLogType(lt.key);
                                                handleReset();
                                            }}
                                            className={`px-2 py-2 rounded-lg border text-[10px] font-semibold transition-all duration-150 active:scale-95
                                                ${
                                                    isSelected
                                                        ? LOG_COLOR_SELECTED[
                                                              lt.color
                                                          ]
                                                        : `${LOG_COLOR[lt.color]} hover:opacity-80`
                                                }`}
                                        >
                                            {lt.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Scanner area */}
                        <div className="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 flex flex-col items-center gap-5">
                            <p className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">
                                Place Finger on Scanner
                            </p>

                            {/* Scanner window */}
                            <div
                                className={`relative w-40 h-48 rounded-2xl border-2 overflow-hidden flex items-center justify-center transition-all duration-300
                                ${
                                    scanState === "scanning"
                                        ? "border-blue-400 dark:border-blue-500 bg-blue-50 dark:bg-blue-950/30"
                                        : scanState === "verifying"
                                          ? "border-violet-400 dark:border-violet-500 bg-violet-50 dark:bg-violet-950/30"
                                          : scanState === "done" &&
                                              result?.matched
                                            ? "border-green-400 dark:border-green-500 bg-green-50 dark:bg-green-950/30"
                                            : scanState === "done"
                                              ? "border-red-400 dark:border-red-500 bg-red-50 dark:bg-red-950/30"
                                              : "border-dashed border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-800/50"
                                }`}
                            >
                                {scanState === "scanning" && (
                                    <div className="absolute inset-0 overflow-hidden">
                                        <div
                                            className="absolute left-0 right-0 h-0.5 bg-blue-400 shadow-[0_0_8px_2px_rgba(96,165,250,0.6)]"
                                            style={{
                                                animation:
                                                    "scanline 1.4s ease-in-out infinite",
                                            }}
                                        />
                                    </div>
                                )}
                                {scanState === "verifying" && (
                                    <div className="absolute inset-0 flex items-center justify-center">
                                        <div className="w-10 h-10 border-2 border-violet-400 border-t-transparent rounded-full animate-spin" />
                                    </div>
                                )}
                                <FingerprintIcon
                                    className={`w-20 h-20 transition-all duration-300
                                    ${
                                        scanState === "scanning"
                                            ? "text-blue-300   dark:text-blue-600 animate-pulse"
                                            : scanState === "verifying"
                                              ? "text-violet-200 dark:text-violet-700 opacity-30"
                                              : scanState === "done" &&
                                                  result?.matched
                                                ? "text-green-400 dark:text-green-500"
                                                : scanState === "done"
                                                  ? "text-red-300   dark:text-red-600"
                                                  : "text-zinc-300   dark:text-zinc-600"
                                    }`}
                                />
                            </div>

                            {/* State label */}
                            <p
                                className={`text-[11px] font-medium text-center
                                ${
                                    scanState === "scanning"
                                        ? "text-blue-600   dark:text-blue-400"
                                        : scanState === "verifying"
                                          ? "text-violet-600 dark:text-violet-400"
                                          : scanState === "done" &&
                                              result?.matched
                                            ? "text-green-600 dark:text-green-400"
                                            : scanState === "done"
                                              ? "text-red-600    dark:text-red-400"
                                              : "text-zinc-400   dark:text-zinc-500"
                                }`}
                            >
                                {scanState === "idle" && "Ready to scan"}
                                {scanState === "scanning" &&
                                    "Scanning… hold still"}
                                {scanState === "verifying" &&
                                    "Verifying fingerprint…"}
                                {scanState === "done" &&
                                    (result?.matched
                                        ? "Attendance recorded"
                                        : "Scan failed")}
                            </p>

                            {/* Error */}
                            {errorMsg && (
                                <div className="w-full text-[10px] text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2 text-center">
                                    {errorMsg}
                                </div>
                            )}

                            {/* Scan button — hidden when result is showing */}
                            {scanState !== "done" && (
                                <button
                                    onClick={handleScan}
                                    disabled={
                                        scanState !== "idle" ||
                                        deviceStatus !== "ready"
                                    }
                                    className={`px-8 py-2.5 rounded-xl text-[12px] font-semibold transition-all duration-200
                                        ${
                                            scanState !== "idle" ||
                                            deviceStatus !== "ready"
                                                ? "bg-zinc-200 dark:bg-zinc-700 text-zinc-400 cursor-not-allowed"
                                                : "bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:opacity-80 active:scale-95"
                                        }`}
                                >
                                    {scanState === "scanning"
                                        ? "Scanning…"
                                        : scanState === "verifying"
                                          ? "Verifying…"
                                          : "Start Scan"}
                                </button>
                            )}
                        </div>

                        {/* Result card */}
                        {scanState === "done" && result && (
                            <ResultCard result={result} onReset={handleReset} />
                        )}
                    </div>

                    {/* RIGHT — Recent logs */}
                    <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 overflow-hidden">
                        <div className="px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                            <p className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">
                                Today's Logs
                            </p>
                        </div>
                        <div className="flex-1 overflow-auto p-3 flex flex-col gap-1.5">
                            {recentLogs.length === 0 ? (
                                <p className="text-[10px] text-zinc-400 dark:text-zinc-500 italic text-center mt-4">
                                    No logs yet today.
                                </p>
                            ) : (
                                recentLogs.map((log) => (
                                    <RecentLog key={log.id} log={log} />
                                ))
                            )}
                        </div>
                        <div className="px-4 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                            <button
                                onClick={fetchRecent}
                                className="text-[9px] text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors"
                            >
                                ↻ Refresh
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
