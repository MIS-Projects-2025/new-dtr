import { useState, useEffect, useCallback } from "react";
import { Head, usePage } from "@inertiajs/react";
import axios from "axios";
import { useDigitalPersona, useSecuGen } from "@/hooks/useFingerprint";

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

// Ordered scan stages, used to drive the little progress dots in the header
// (mirrors the 4-dot PIN-entry indicator from the reference design).
const SCAN_STAGES = ["idle", "scanning", "verifying", "done"];

// ── Sub-components ────────────────────────────────────────────────────────────

function FingerprintIcon({ className, style }) {
    return (
        <svg
            className={className}
            style={style}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.2"
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
            cls: "bg-green-500/10 text-green-700 dark:text-green-300 border-green-400/40",
        },
        connecting: {
            label: "🟡 Connecting…",
            cls: "bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-400/40",
        },
        disconnected: {
            label: "🔴 Disconnected",
            cls: "bg-red-500/10 text-red-700 dark:text-red-300 border-red-400/40",
        },
        sdk_missing: {
            label: "⚠ SDK not loaded",
            cls: "bg-red-500/10 text-red-700 dark:text-red-300 border-red-400/40",
        },
    };
    const { label, cls } = map[status] ?? map.connecting;
    return (
        <span
            className={`text-sm font-semibold px-3 py-1.5 rounded-full border ${cls}`}
        >
            {label}
        </span>
    );
}

// Small progress dots that echo the reference design's 4-dot PIN indicator,
// but track fingerprint scan stage (idle → scanning → verifying → done).
function StageDots({ scanState }) {
    const activeIndex = SCAN_STAGES.indexOf(scanState);
    return (
        <div className="flex items-center gap-2" aria-hidden="true">
            {SCAN_STAGES.map((stage, i) => (
                <span
                    key={stage}
                    className={`w-2.5 h-2.5 rounded-full border-2 transition-colors duration-300
                        ${
                            i <= activeIndex
                                ? "bg-emerald-500 border-emerald-500"
                                : "border-zinc-300 dark:border-zinc-600"
                        }`}
                />
            ))}
        </div>
    );
}

function LiveClock() {
    const [clock, setClock] = useState(new Date());
    useEffect(() => {
        const t = setInterval(() => setClock(new Date()), 1000);
        return () => clearInterval(t);
    }, []);
    return (
        <div className="relative z-10 flex flex-col gap-1">
            <p className="text-sm font-medium text-primary-foreground/70">
                {clock.toLocaleDateString("en-US", {
                    weekday: "long",
                    month: "long",
                    day: "numeric",
                })}
            </p>
            <div className="flex items-baseline gap-2">
                <span
                    className="font-bold tabular-nums leading-none"
                    style={{ fontSize: "clamp(2.5rem, 9vh, 6rem)" }}
                >
                    {clock
                        .toLocaleTimeString([], {
                            hour: "2-digit",
                            minute: "2-digit",
                        })
                        .replace(/\s?[AP]M/i, "")}
                </span>
                <span className="text-xl sm:text-2xl font-medium text-primary-foreground/70">
                    {clock
                        .toLocaleTimeString([], {
                            hour: "2-digit",
                            minute: "2-digit",
                        })
                        .match(/[AP]M/i)?.[0]
                        ?.toLowerCase()}
                </span>
            </div>
        </div>
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
            className={`w-full max-w-lg rounded-3xl border-4 shadow-sm p-8 flex flex-col items-center gap-4 text-center transition-all
            ${
                isMatch
                    ? "border-green-400 bg-green-50 dark:bg-green-900/20"
                    : isDuplicate
                      ? "border-amber-400  bg-amber-50  dark:bg-amber-900/20"
                      : "border-red-400    bg-red-50    dark:bg-red-900/20"
            }`}
        >
            <div
                className={`w-20 h-20 rounded-full flex items-center justify-center text-5xl
                ${isMatch ? "bg-green-100 dark:bg-green-800" : isDuplicate ? "bg-amber-100 dark:bg-amber-800" : "bg-red-100 dark:bg-red-800"}`}
            >
                {isMatch ? "✓" : isDuplicate ? "⚠" : "✗"}
            </div>

            <p
                className={`text-2xl font-bold
                ${isMatch ? "text-green-700 dark:text-green-300" : isDuplicate ? "text-amber-700 dark:text-amber-300" : "text-red-700 dark:text-red-300"}`}
            >
                {isMatch
                    ? "Match Found"
                    : isDuplicate
                      ? "Already Logged"
                      : "No Match"}
            </p>

            {result.employee && (
                <div className="flex flex-col gap-1">
                    <p className="text-3xl font-semibold text-zinc-800 dark:text-zinc-100">
                        Hi {result.employee.name.split(" ")[0]}!
                    </p>
                    <p className="text-base text-zinc-500 dark:text-zinc-400">
                        {result.employee.employid} ·{" "}
                        {result.employee.department}
                    </p>
                    {result.employee.finger && (
                        <p className="text-sm text-zinc-400 dark:text-zinc-500 mt-0.5">
                            Matched: {result.employee.finger}
                        </p>
                    )}
                </div>
            )}

            {isMatch && result.log && (
                <div className="flex items-center gap-3">
                    <span className="text-base font-bold px-4 py-1.5 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300">
                        {logLabel}
                    </span>
                    <span className="text-base text-zinc-500 dark:text-zinc-400">
                        {result.log.logged_at}
                    </span>
                </div>
            )}

            {result.score > 0 && (
                <p className="text-sm text-zinc-400 dark:text-zinc-500">
                    Similarity: {result.score}%
                </p>
            )}

            {!isMatch && (
                <p className="text-base text-zinc-500 dark:text-zinc-400 italic">
                    {result.message}
                </p>
            )}

            <button
                onClick={onReset}
                className="mt-2 px-8 py-3 rounded-xl text-lg font-semibold bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:opacity-80 active:scale-95 transition-all"
            >
                Scan Again
            </button>
        </div>
    );
}

const LOG_ACCENT = {
    green: "border-l-green-400",
    amber: "border-l-amber-400",
    orange: "border-l-orange-400",
    red: "border-l-red-400",
};

function RecentLogRow({ log }) {
    const logType = LOG_TYPES.find((l) => l.key === log.log_type);
    const label = logType?.label ?? log.log_type;
    const accent = LOG_ACCENT[logType?.color] ?? "border-l-zinc-300";
    return (
        <div
            className={`flex-shrink-0 flex flex-col gap-1.5 pl-3 pr-4 py-3 rounded-r-xl border-l-4 ${accent} bg-zinc-50 dark:bg-zinc-800 border-y border-r border-zinc-100 dark:border-zinc-700`}
        >
            <div className="flex items-center justify-between gap-2">
                <p className="text-base font-semibold text-zinc-700 dark:text-zinc-200 truncate">
                    {log.employee_name}
                </p>
                <span className="text-sm text-zinc-400 dark:text-zinc-500 flex-shrink-0">
                    {log.logged_at
                        ? new Date(log.logged_at).toLocaleTimeString([], {
                              hour: "2-digit",
                              minute: "2-digit",
                          })
                        : ""}
                </span>
            </div>
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs text-zinc-400 dark:text-zinc-500 truncate">
                    {log.department}
                </p>
                <span className="text-xs font-bold px-2 py-0.5 rounded bg-zinc-200 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300 flex-shrink-0">
                    {label}
                </span>
            </div>
        </div>
    );
}

// ── Panel ─────────────────────────────────────────────────────────────────────

export default function ScanLogPanel() {
    const { app_name } = usePage().props;

    const [scannerType, setScannerType] = useState("digitalpersona");
    const dp = useDigitalPersona();
    const secugen = useSecuGen();
    const { capture, deviceStatus, refreshStatus } =
        scannerType === "secugen" ? secugen : dp;

    const [selectedLogType, setSelectedLogType] = useState("check_in");
    const [scanState, setScanState] = useState("idle");
    const [result, setResult] = useState(null);
    const [errorMsg, setErrorMsg] = useState("");
    const [recentLogs, setRecentLogs] = useState([]);

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

    // Auto-clear a finished result after a while so the kiosk resets itself
    // for the next person without anyone needing to tap "Scan Again".
    useEffect(() => {
        if (scanState !== "done") return;
        const t = setTimeout(() => handleReset(), 6000);
        return () => clearTimeout(t);
    }, [scanState, handleReset]);

    const selectedLog = LOG_TYPES.find((l) => l.key === selectedLogType);

    const stageHeadline = {
        idle: "Touch the sensor to continue",
        scanning: "Scanning… hold still",
        verifying: "Verifying your fingerprint…",
        done: "",
    }[scanState];

    return (
        <>
            <Head title="Scan Logs" />

            <style>{`
                @keyframes scanline {
                    0%, 100% { transform: translateY(0); }
                    50% { transform: translateY(var(--scan-h, 200px)); }
                }
                @keyframes pulse-ring {
                    0%   { transform: scale(0.55); opacity: 0.7; }
                    80%  { opacity: 0; }
                    100% { transform: scale(1.15); opacity: 0; }
                }
                @keyframes orbit {
                    from { transform: rotate(0deg); }
                    to   { transform: rotate(360deg); }
                }
            `}</style>

            <div className="h-full min-h-0 w-full flex overflow-hidden bg-zinc-100 dark:bg-zinc-950">
                {/* ── Left: branding / clock hero panel ──────────────────── */}
                <div className="relative w-[38%] min-w-[320px] flex-shrink-0 flex flex-col justify-between bg-primary text-primary-foreground px-10 py-10 overflow-hidden">
                    {/* cheap decorative pattern (no filter:blur cost) */}
                    <div
                        className="pointer-events-none absolute inset-0 opacity-[0.07]"
                        style={{
                            backgroundImage:
                                "radial-gradient(circle at 1px 1px, currentColor 1px, transparent 0)",
                            backgroundSize: "24px 24px",
                        }}
                    />

                    <LiveClock />

                    <div className="relative z-10 flex flex-col items-center gap-6 my-auto">
                        <div
                            className="relative flex items-center justify-center"
                            style={{
                                width: "clamp(150px, 20vh, 220px)",
                                height: "clamp(150px, 20vh, 220px)",
                            }}
                        >
                            {/* Expanding pulse rings — staggered, transform+opacity only */}
                            <span
                                className="absolute inset-0 rounded-full border border-primary-foreground/40"
                                style={{
                                    animation:
                                        "pulse-ring 2.6s ease-out infinite",
                                }}
                            />
                            <span
                                className="absolute inset-0 rounded-full border border-primary-foreground/40"
                                style={{
                                    animation:
                                        "pulse-ring 2.6s ease-out infinite",
                                    animationDelay: "0.9s",
                                }}
                            />
                            <span
                                className="absolute inset-0 rounded-full border border-primary-foreground/40"
                                style={{
                                    animation:
                                        "pulse-ring 2.6s ease-out infinite",
                                    animationDelay: "1.8s",
                                }}
                            />

                            {/* Orbiting data-point dots */}
                            <div
                                className="absolute inset-0"
                                style={{
                                    animation: "orbit 7s linear infinite",
                                }}
                            >
                                <span className="absolute top-0 left-1/2 -translate-x-1/2 w-2 h-2 rounded-full bg-primary-foreground/70 shadow-[0_0_8px_2px_rgba(255,255,255,0.25)]" />
                            </div>
                            <div
                                className="absolute inset-0"
                                style={{
                                    animation: "orbit 7s linear infinite",
                                    animationDelay: "-2.3s",
                                }}
                            >
                                <span className="absolute top-0 left-1/2 -translate-x-1/2 w-1.5 h-1.5 rounded-full bg-primary-foreground/50" />
                            </div>
                            <div
                                className="absolute inset-0"
                                style={{
                                    animation: "orbit 7s linear infinite",
                                    animationDelay: "-4.6s",
                                }}
                            >
                                <span className="absolute top-0 left-1/2 -translate-x-1/2 w-1.5 h-1.5 rounded-full bg-primary-foreground/35" />
                            </div>

                            {/* Static ring frame + core fingerprint */}
                            <div className="absolute inset-0 rounded-full border border-primary-foreground/15" />
                            <FingerprintIcon
                                className="relative text-primary-foreground/70"
                                style={{ width: "40%", height: "40%" }}
                            />
                        </div>

                        <p className="text-sm font-medium text-primary-foreground/60 text-center max-w-[220px]">
                            Scan your fingerprint to log your attendance
                        </p>
                    </div>

                    <div className="relative z-10 flex flex-col gap-1">
                        <p className="text-base font-semibold text-primary-foreground">
                            Daily Time Record
                        </p>
                        <p className="text-sm text-primary-foreground/70">
                            Telford Service Philippines Incorporation
                        </p>
                    </div>
                </div>

                {/* ── Center: scan interaction panel ─────────────────────── */}
                <div className="flex-1 flex flex-col min-w-0">
                    <div className="flex-1 flex flex-col items-center justify-center gap-8 px-8 py-8 min-h-0 overflow-auto">
                        {scanState === "done" && result ? (
                            <ResultCard result={result} onReset={handleReset} />
                        ) : (
                            <>
                                {/* Headline + stage dots, mirrors "Hi X, enter your PIN" header */}
                                <div className="w-full max-w-lg flex flex-col items-center gap-3 text-center">
                                    <h1 className="text-2xl font-bold text-zinc-800 dark:text-zinc-100">
                                        {stageHeadline}
                                    </h1>
                                    <p className="text-base text-zinc-500 dark:text-zinc-400">
                                        We'll record this as your{" "}
                                        <span className="font-semibold">
                                            {selectedLog?.label}
                                        </span>
                                        .
                                    </p>
                                    <StageDots scanState={scanState} />
                                </div>

                                {/* Log type selector — large touch targets */}
                                <div className="w-full max-w-2xl rounded-3xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
                                    <p className="text-center text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest mb-4">
                                        Select Log Type
                                    </p>
                                    <div className="grid grid-cols-4 gap-3">
                                        {LOG_TYPES.map((lt) => {
                                            const isSelected =
                                                selectedLogType === lt.key;
                                            return (
                                                <button
                                                    key={lt.key}
                                                    onClick={() => {
                                                        setSelectedLogType(
                                                            lt.key,
                                                        );
                                                        handleReset();
                                                    }}
                                                    className={`px-3 py-4 rounded-2xl border-2 text-sm font-semibold transition-all duration-150 active:scale-95
                                                        ${
                                                            isSelected
                                                                ? `${LOG_COLOR_SELECTED[lt.color]} shadow-md`
                                                                : `${LOG_COLOR[lt.color]} hover:shadow-sm`
                                                        }`}
                                                >
                                                    {lt.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>

                                {/* Scan stage — big, centered, unmissable */}
                                <div className="w-full max-w-lg rounded-3xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-5 sm:p-8 flex flex-col items-center gap-5 sm:gap-6">
                                    <div
                                        style={{
                                            width: "clamp(160px, 26vh, 224px)",
                                            height: "clamp(180px, 30vh, 256px)",
                                        }}
                                        className={`relative rounded-3xl border-4 overflow-hidden flex items-center justify-center transition-all duration-300
                                        ${
                                            scanState === "scanning"
                                                ? "border-blue-400 dark:border-blue-500 bg-blue-50 dark:bg-blue-950/30"
                                                : scanState === "verifying"
                                                  ? "border-violet-400 dark:border-violet-500 bg-violet-50 dark:bg-violet-950/30"
                                                  : "border-dashed border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-800/50"
                                        }`}
                                    >
                                        {scanState === "scanning" && (
                                            <div className="absolute inset-0 overflow-hidden">
                                                <div
                                                    className="absolute left-0 right-0 top-0 h-1 bg-blue-400 shadow-[0_0_12px_3px_rgba(96,165,250,0.6)]"
                                                    style={{
                                                        animation:
                                                            "scanline 1.4s ease-in-out infinite",
                                                        "--scan-h":
                                                            "calc(clamp(180px, 30vh, 256px) - 4px)",
                                                    }}
                                                />
                                            </div>
                                        )}
                                        {scanState === "verifying" && (
                                            <div className="absolute inset-0 flex items-center justify-center">
                                                <div className="w-14 h-14 border-4 border-violet-400 border-t-transparent rounded-full animate-spin" />
                                            </div>
                                        )}
                                        <FingerprintIcon
                                            style={{
                                                width: "clamp(64px, 12vh, 112px)",
                                                height: "clamp(64px, 12vh, 112px)",
                                            }}
                                            className={`transition-all duration-300
                                            ${
                                                scanState === "scanning"
                                                    ? "text-blue-300   dark:text-blue-600 animate-pulse"
                                                    : scanState === "verifying"
                                                      ? "text-violet-200 dark:text-violet-700 opacity-30"
                                                      : "text-zinc-300   dark:text-zinc-600"
                                            }`}
                                        />
                                    </div>

                                    {errorMsg && (
                                        <div className="w-full text-base text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3 text-center">
                                            {errorMsg}
                                        </div>
                                    )}

                                    {/* Scanner picker + device status — sits directly above Start Scan */}
                                    <div className="w-full flex flex-col items-center gap-2">
                                        <div className="flex items-center gap-3 flex-wrap justify-center">
                                            <div className="flex items-center gap-1 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800 p-1">
                                                <button
                                                    onClick={() =>
                                                        setScannerType(
                                                            "digitalpersona",
                                                        )
                                                    }
                                                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all
                                                        ${
                                                            scannerType ===
                                                            "digitalpersona"
                                                                ? "bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900"
                                                                : "text-zinc-500 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200"
                                                        }`}
                                                >
                                                    HID DigitalPersona
                                                </button>
                                                <button
                                                    onClick={() =>
                                                        setScannerType(
                                                            "secugen",
                                                        )
                                                    }
                                                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all
                                                        ${
                                                            scannerType ===
                                                            "secugen"
                                                                ? "bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900"
                                                                : "text-zinc-500 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200"
                                                        }`}
                                                >
                                                    SecuGen
                                                </button>
                                            </div>
                                            <DeviceBadge
                                                status={deviceStatus}
                                            />
                                            {scannerType === "secugen" && (
                                                <button
                                                    onClick={() =>
                                                        refreshStatus?.()
                                                    }
                                                    className="text-sm text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 transition-colors px-1"
                                                    title="Re-check SecuGen connection"
                                                >
                                                    ↻
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    <button
                                        onClick={handleScan}
                                        disabled={
                                            scanState !== "idle" ||
                                            deviceStatus !== "ready"
                                        }
                                        className={`w-full px-8 py-4 rounded-2xl text-xl font-bold transition-all duration-200
                                            ${
                                                scanState !== "idle" ||
                                                deviceStatus !== "ready"
                                                    ? "bg-zinc-200 dark:bg-zinc-700 text-zinc-400 cursor-not-allowed"
                                                    : "bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 shadow-md hover:shadow-lg hover:opacity-90 active:scale-95"
                                            }`}
                                    >
                                        {scanState === "scanning"
                                            ? "Scanning…"
                                            : scanState === "verifying"
                                              ? "Verifying…"
                                              : "Start Scan"}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>

                {/* ── Right: today's logs sidebar ────────────────────────── */}
                <div className="w-[300px] flex-shrink-0 flex flex-col border-l border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
                    <div className="h-1 bg-gradient-to-r from-teal-600 to-emerald-500 flex-shrink-0" />
                    <div className="flex items-center justify-between px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                        <p className="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">
                            Today's Logs
                        </p>
                        <button
                            onClick={fetchRecent}
                            className="text-sm text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors"
                            title="Refresh"
                        >
                            ↻
                        </button>
                    </div>
                    <div className="flex-1 overflow-y-auto p-3 flex flex-col gap-2">
                        {recentLogs.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 text-center mt-10">
                                <span className="text-3xl opacity-30">🗒️</span>
                                <p className="text-sm text-zinc-400 dark:text-zinc-500 italic">
                                    No logs yet today.
                                </p>
                            </div>
                        ) : (
                            recentLogs.map((log) => (
                                <RecentLogRow key={log.id} log={log} />
                            ))
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
