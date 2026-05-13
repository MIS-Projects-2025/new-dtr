import { useState, useEffect, useRef, useCallback } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import axios from "axios";

// ── Constants ────────────────────────────────────────────────────────────────

const FINGER_LABELS = [
    "R. Thumb", "R. Index", "R. Middle", "R. Ring", "R. Little",
    "L. Thumb", "L. Index", "L. Middle", "L. Ring",  "L. Little",
];

const FINGER_FULL = [
    "Right Thumb", "Right Index", "Right Middle", "Right Ring",  "Right Little",
    "Left Thumb",  "Left Index",  "Left Middle",  "Left Ring",   "Left Little",
];

// RIGHT hand: indices 0–4 | LEFT hand: 5–9
// Visual positions for each finger on the hand diagram (percent from left, percent from top)
const RIGHT_POSITIONS = [
    { left: "10%", top: "55%" },  // Thumb
    { left: "28%", top: "28%" },  // Index
    { left: "44%", top: "20%" },  // Middle
    { left: "60%", top: "25%" },  // Ring
    { left: "74%", top: "36%" },  // Little
];
const LEFT_POSITIONS = [
    { left: "90%", top: "55%" },  // Thumb
    { left: "72%", top: "28%" },  // Index
    { left: "56%", top: "20%" },  // Middle
    { left: "40%", top: "25%" },  // Ring
    { left: "26%", top: "36%" },  // Little
];

// ── Fingerprint scanner hook ──────────────────────────────────────────────────
// Adapt this to your device's local service URL.
// Common: Mantra MFS100 → http://localhost:8003
//         SecuGen        → http://localhost:9734
//         DigitalPersona → http://localhost:4649

// ── DigitalPersona U.are.U 4500 Hook ─────────────────────────────────────────
// Requires SDK scripts loaded in app.blade.php (from lib/ folder in the repo).
// DigitalPersona Lite Client must be installed: https://crossmatch.hid.gl/lite-client/

function useDigitalPersona() {
    const clientRef   = useRef(null);
    const deviceRef   = useRef(null);
    const [deviceStatus, setDeviceStatus] = useState("connecting");

    useEffect(() => {
        let cancelled = false;
        let pollTimer = null;

        const tryInit = () => {
            if (cancelled) return;

            // ── 1. Find the SDK namespace ──────────────────────────────────
            const sdk =
                window.Fingerprint      ??   // most common
                window.DigitalPersona   ??   // some builds
                window.FingerprintSdk   ??   // older builds
                null;

            console.log("[DP] window.Fingerprint      →", window.Fingerprint);
            console.log("[DP] window.DigitalPersona   →", window.DigitalPersona);
            console.log("[DP] resolved sdk            →", sdk);
            console.log("[DP] sdk?.WebApi             →", sdk?.WebApi);

            if (!sdk?.WebApi) {
                // Retry every 300 ms (up to ~10 s) while the page is still loading
                pollTimer = setTimeout(tryInit, 300);
                return;
            }

            // ── 2. Instantiate ─────────────────────────────────────────────
            let client;
            try {
                client = new sdk.WebApi();
            } catch (err) {
                console.error("[DP] new WebApi() threw:", err);
                setDeviceStatus("sdk_missing");
                return;
            }

            clientRef.current = client;
            console.log("[DP] WebApi client created:", client);

            client.onDeviceConnected = (e) => {
                console.log("[DP] onDeviceConnected raw event:", e, JSON.stringify(e));
                deviceRef.current =
                    e?.deviceUid ?? e?.deviceID ?? e?.uid ?? "auto";
                setDeviceStatus("ready");
            };

            client.onDeviceDisconnected = (e) => {
                console.log("[DP] onDeviceDisconnected:", e);
                deviceRef.current = null;
                setDeviceStatus("disconnected");
            };

            client.onCommunicationFailed = (e) => {
                console.warn("[DP] onCommunicationFailed:", e);
                setDeviceStatus("disconnected");
            };
        };

        // Start polling once the document is interactive/complete
        if (document.readyState === "complete") {
            tryInit();
        } else {
            window.addEventListener("load", tryInit, { once: true });
        }

        return () => {
            cancelled = true;
            clearTimeout(pollTimer);
            try { clientRef.current?.stopAcquisition(); } catch {}
        };
    }, []);

    const capture = useCallback(() => {
        return new Promise((resolve, reject) => {
            const sdk    =
                window.Fingerprint    ??
                window.DigitalPersona ??
                window.FingerprintSdk ??
                null;
            const client = clientRef.current;

            console.log("[DP] capture() — sdk:", sdk, "client:", client, "deviceRef:", deviceRef.current);

            if (!sdk || !client) {
                return reject(new Error(
                    `SDK not ready. sdk=${!!sdk} client=${!!client}. ` +
                    `Check the console for [DP] logs.`
                ));
            }

            let qualityScore = 80;

            client.onQualityReported = (e) => {
                qualityScore = e.quality === 0 ? 90 : Math.max(10, 80 - e.quality * 10);
            };

            client.onSamplesAcquired = (e) => {
                try {
                    const samples    = JSON.parse(e.samples);
                    let   base64Data = samples[0]?.Data ?? samples[0];

                    // SDK returns URL-safe base64 (- and _).
                    // Convert to standard base64 (+ and /) for the img tag and PHP's base64_decode().
                    base64Data = base64Data
                        .replace(/-/g, "+")
                        .replace(/_/g, "/");

                    client.stopAcquisition().catch(() => {});
                    resolve({
                        template : base64Data,
                        quality  : qualityScore,
                        bitmap   : base64Data,
                        device   : "DigitalPersona U.are.U 4500",
                    });
                } catch (err) {
                    reject(new Error("Failed to parse fingerprint sample: " + err.message));
                }
            };

            client.onErrorOccurred = (e) => {
                console.error("[DP] onErrorOccurred:", e);
                reject(new Error(`Scanner error: ${e?.error ?? JSON.stringify(e)}`));
            };

            const SampleFormat = sdk.SampleFormat ?? sdk.Formats?.SampleFormat;

            // Always use "any device" default — deviceRef is null at call-time since
            // onDeviceConnected fires asynchronously after startAcquisition is called.
            client.startAcquisition(SampleFormat.PngImage)
                .catch(err => {
                    console.error("[DP] startAcquisition rejected:", err);
                    reject(new Error(err?.message ?? "Failed to start acquisition."));
                });
        });
    }, []);

    return { capture, deviceStatus };
}

// ── Sub-components ────────────────────────────────────────────────────────────

function QualityBar({ quality }) {
    const color = quality >= 70 ? "bg-green-500" : quality >= 40 ? "bg-amber-400" : "bg-red-500";
    return (
        <div className="flex items-center gap-2">
            <div className="flex-1 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                <div
                    className={`h-full rounded-full transition-all duration-500 ${color}`}
                    style={{ width: `${quality}%` }}
                />
            </div>
            <span className="text-[10px] font-semibold text-zinc-600 dark:text-zinc-300 w-8 text-right">
                {quality}%
            </span>
        </div>
    );
}

function HandDiagram({ hand = "right", registeredIndices = [], selectedIndex, onSelect }) {
    const isRight     = hand === "right";
    const baseIndex   = isRight ? 0 : 5;
    const positions   = isRight ? RIGHT_POSITIONS : LEFT_POSITIONS;

    return (
        <div className="relative select-none" style={{ width: "100%", paddingBottom: "75%" }}>
            {/* Palm shape */}
            <svg
                className="absolute inset-0 w-full h-full"
                viewBox="0 0 200 150"
                xmlns="http://www.w3.org/2000/svg"
            >
                {/* Palm */}
                <ellipse cx="100" cy="105" rx="55" ry="38"
                    className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600"
                    strokeWidth="1.5" />
                {/* Fingers - simplified silhouette */}
                {isRight ? (
                    <>
                        <rect x="20"  y="64" width="18" height="48" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                        <rect x="48"  y="28" width="18" height="60" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                        <rect x="72"  y="20" width="18" height="68" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                        <rect x="96"  y="25" width="18" height="63" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                        <rect x="120" y="38" width="18" height="52" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                    </>
                ) : (
                    <>
                        <rect x="162" y="64" width="18" height="48" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                        <rect x="134" y="28" width="18" height="60" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                        <rect x="110" y="20" width="18" height="68" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                        <rect x="86"  y="25" width="18" height="63" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                        <rect x="62"  y="38" width="18" height="52" rx="9"  className="fill-zinc-100 dark:fill-zinc-800 stroke-zinc-300 dark:stroke-zinc-600" strokeWidth="1.5" />
                    </>
                )}
            </svg>

            {/* Clickable finger dots */}
            {positions.map((pos, i) => {
                const globalIdx  = baseIndex + i;
                const isReg      = registeredIndices.includes(globalIdx);
                const isSelected = selectedIndex === globalIdx;

                return (
                    <button
                        key={globalIdx}
                        onClick={() => onSelect(globalIdx)}
                        style={{ left: pos.left, top: pos.top, transform: "translate(-50%,-50%)" }}
                        className={`absolute w-7 h-7 rounded-full border-2 transition-all duration-200 flex items-center justify-center text-[8px] font-bold z-10
                            ${isSelected
                                ? "border-zinc-900 dark:border-white bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 scale-125 shadow-lg"
                                : isReg
                                    ? "border-green-500 bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-400 hover:scale-110"
                                    : "border-zinc-400 dark:border-zinc-500 bg-white dark:bg-zinc-700 text-zinc-400 hover:border-zinc-700 dark:hover:border-zinc-300 hover:scale-110"
                            }`}
                        title={FINGER_FULL[globalIdx]}
                    >
                        {isReg ? "✓" : i + 1}
                    </button>
                );
            })}
        </div>
    );
}

function ScannerArea({ state, bitmap, quality, onScan }) {
    return (
        <div className="flex flex-col items-center gap-3">
            {/* Scanner window */}
            <div className={`relative w-32 h-40 rounded-xl border-2 overflow-hidden flex items-center justify-center transition-all duration-300
                ${state === "scanning"
                    ? "border-blue-400 dark:border-blue-500 bg-blue-50 dark:bg-blue-950/30"
                    : state === "success"
                        ? "border-green-400 dark:border-green-500 bg-green-50 dark:bg-green-950/30"
                        : state === "error"
                            ? "border-red-400 dark:border-red-500 bg-red-50 dark:bg-red-950/30"
                            : "border-dashed border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-800/50"
                }`}
            >
                {/* Scan line animation */}
                {state === "scanning" && (
                    <div className="absolute inset-0 overflow-hidden">
                        <div
                            className="absolute left-0 right-0 h-0.5 bg-blue-400 dark:bg-blue-400 shadow-[0_0_8px_2px_rgba(96,165,250,0.6)]"
                            style={{ animation: "scanline 1.4s ease-in-out infinite" }}
                        />
                    </div>
                )}

                {/* Fingerprint preview bitmap */}
                {bitmap && state === "success" ? (
                    <img
                        src={`data:image/png;base64,${bitmap}`}
                        alt="fingerprint"
                        className="w-full h-full object-cover opacity-80"
                    />
                ) : (
                    <FingerprintIcon
                        className={`w-14 h-14 transition-all duration-300
                            ${state === "scanning" ? "text-blue-300 dark:text-blue-600 animate-pulse"
                            : state === "success"  ? "text-green-400 dark:text-green-500"
                            : state === "error"    ? "text-red-300 dark:text-red-600"
                            : "text-zinc-300 dark:text-zinc-600"}`}
                    />
                )}
            </div>

            {/* Status text */}
            <p className={`text-[10px] font-medium text-center leading-tight
                ${state === "scanning" ? "text-blue-600 dark:text-blue-400"
                : state === "success"  ? "text-green-600 dark:text-green-400"
                : state === "error"    ? "text-red-600 dark:text-red-400"
                : "text-zinc-400 dark:text-zinc-500"}`}
            >
                {state === "idle"     && "Place finger on scanner"}
                {state === "scanning" && "Scanning… hold still"}
                {state === "success"  && "Capture successful"}
                {state === "error"    && "Scan failed — try again"}
            </p>

            {/* Quality bar */}
            {state === "success" && quality > 0 && (
                <div className="w-full">
                    <p className="text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Quality</p>
                    <QualityBar quality={quality} />
                </div>
            )}

            {/* Scan button */}
            <button
                onClick={onScan}
                disabled={state === "scanning"}
                className={`mt-1 px-4 py-1.5 rounded-lg text-[11px] font-semibold transition-all duration-200
                    ${state === "scanning"
                        ? "bg-zinc-200 dark:bg-zinc-700 text-zinc-400 cursor-not-allowed"
                        : "bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:opacity-80 active:scale-95"
                    }`}
            >
                {state === "scanning" ? "Scanning…" : state === "success" ? "Rescan" : "Start Scan"}
            </button>
        </div>
    );
}

// ── FingerprintIcon SVG ───────────────────────────────────────────────────────
function FingerprintIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
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

// ── Registration Modal ────────────────────────────────────────────────────────

function RegistrationModal({ employee, existingRegistrations, appName, capture, deviceStatus, onClose, onSaved }) {
    const [selectedFinger, setSelectedFinger]   = useState(null);
    const [scanState,       setScanState]        = useState("idle");   // idle | scanning | success | error
    const [capturedData,    setCapturedData]     = useState(null);
    const [saving,          setSaving]           = useState(false);
    const [errorMsg,        setErrorMsg]         = useState("");
    const [localRegs,       setLocalRegs]        = useState(existingRegistrations ?? []);
    const [detailMap,       setDetailMap]        = useState({});
    const [loadingDetails,  setLoadingDetails]   = useState(true);

    // Load per-finger detail (quality, created_at) from server
    useEffect(() => {
        setLoadingDetails(true);
        axios.get(`/${appName}/register-fingerprint/${employee.EMPLOYID}/registrations`)
            .then(res => setDetailMap(res.data))
            .catch(() => {})
            .finally(() => setLoadingDetails(false));
    }, [employee.EMPLOYID]);

    const registeredIndices = localRegs.map(r => r.finger_index);

    const handleScan = useCallback(async () => {
            if (selectedFinger === null) {
                setErrorMsg("Please select a finger first.");
                return;
            }
            setErrorMsg("");
            setScanState("scanning");
            setCapturedData(null);

            try {
                const result = await capture();
                setCapturedData(result);
                setScanState("success");
            } catch (err) {
                setScanState("error");
                setErrorMsg(err.message ?? "Unknown scanner error.");
            }
        }, [selectedFinger, capture]);

    const handleSave = useCallback(async () => {
        if (!capturedData || scanState !== "success") return;
        setSaving(true);
        setErrorMsg("");

        try {
            const res = await axios.post(`/${appName}/register-fingerprint`, {
                employid:      employee.EMPLOYID,
                template_data: capturedData.template,
                device_type:   capturedData.device,
                finger_index:  selectedFinger,
                quality:       capturedData.quality,
            });

            if (res.data.success) {
                const newReg = { finger_index: selectedFinger, quality: capturedData.quality, device_type: capturedData.device };
                setLocalRegs(prev => {
                    const filtered = prev.filter(r => r.finger_index !== selectedFinger);
                    return [...filtered, newReg];
                });
                setDetailMap(prev => ({
                    ...prev,
                    [selectedFinger]: {
                        finger_index: selectedFinger,
                        quality:      capturedData.quality,
                        device_type:  capturedData.device,
                        created_at:   "Just now",
                    },
                }));
                setScanState("idle");
                setCapturedData(null);
                onSaved(employee.EMPLOYID, [...localRegs.filter(r => r.finger_index !== selectedFinger), newReg]);
            }
        } catch (err) {
            setErrorMsg(err.response?.data?.message ?? "Failed to save.");
        } finally {
            setSaving(false);
        }
    }, [capturedData, scanState, selectedFinger, employee.EMPLOYID, appName, localRegs, onSaved]);

    const handleDelete = useCallback(async (fingerIndex) => {
        if (!confirm(`Remove fingerprint for ${FINGER_FULL[fingerIndex]}?`)) return;

        try {
            await axios.delete(`/${appName}/register-fingerprint/${employee.EMPLOYID}/${fingerIndex}`);
            const updated = localRegs.filter(r => r.finger_index !== fingerIndex);
            setLocalRegs(updated);
            setDetailMap(prev => { const n = { ...prev }; delete n[fingerIndex]; return n; });
            onSaved(employee.EMPLOYID, updated);
            if (selectedFinger === fingerIndex) { setScanState("idle"); setCapturedData(null); }
        } catch {
            setErrorMsg("Failed to remove fingerprint.");
        }
    }, [localRegs, selectedFinger, employee.EMPLOYID, appName, onSaved]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            {/* Backdrop */}
            <div
                className="absolute inset-0 bg-black/50 backdrop-blur-sm"
                onClick={onClose}
            />

            {/* Panel */}
            <div className="relative z-10 w-full max-w-2xl bg-white dark:bg-zinc-900 rounded-2xl shadow-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden flex flex-col max-h-[90vh]">

                {/* Header */}
                <div className="flex items-center gap-3 px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                    <div className="w-9 h-9 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center flex-shrink-0">
                        <FingerprintIcon className="w-5 h-5 text-zinc-500 dark:text-zinc-400" />
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold text-zinc-800 dark:text-zinc-100 truncate">{employee.EMPNAME}</p>
                        <p className="text-[10px] text-zinc-400 dark:text-zinc-500">{employee.EMPLOYID} · {employee.DEPARTMENT}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="text-[10px] font-medium px-2 py-0.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                            {registeredIndices.length}/10 registered
                        </span>
                        <button
                            onClick={onClose}
                            className="w-7 h-7 rounded-lg flex items-center justify-center text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-auto p-5">
                    <div className="grid grid-cols-2 gap-5">

                        {/* LEFT: Hand diagrams + finger list */}
                        <div className="flex flex-col gap-4">
                            <p className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">Select Finger</p>

                            {/* Right hand */}
                            <div>
                                <p className="text-[9px] text-zinc-400 dark:text-zinc-500 mb-1 uppercase tracking-wider">Right Hand</p>
                                <HandDiagram
                                    hand="right"
                                    registeredIndices={registeredIndices}
                                    selectedIndex={selectedFinger}
                                    onSelect={(idx) => {
                                        setSelectedFinger(idx);
                                        setScanState("idle");
                                        setCapturedData(null);
                                        setErrorMsg("");
                                    }}
                                />
                            </div>

                            {/* Left hand */}
                            <div>
                                <p className="text-[9px] text-zinc-400 dark:text-zinc-500 mb-1 uppercase tracking-wider">Left Hand</p>
                                <HandDiagram
                                    hand="left"
                                    registeredIndices={registeredIndices}
                                    selectedIndex={selectedFinger}
                                    onSelect={(idx) => {
                                        setSelectedFinger(idx);
                                        setScanState("idle");
                                        setCapturedData(null);
                                        setErrorMsg("");
                                    }}
                                />
                            </div>

                            {/* Selected finger label */}
                            <div className="text-center">
                                {selectedFinger !== null ? (
                                    <p className="text-xs font-medium text-zinc-700 dark:text-zinc-200">
                                        {FINGER_FULL[selectedFinger]}
                                        {registeredIndices.includes(selectedFinger) && (
                                            <span className="ml-2 text-[9px] font-semibold text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/30 px-1.5 py-0.5 rounded">Registered</span>
                                        )}
                                    </p>
                                ) : (
                                    <p className="text-[10px] text-zinc-400 dark:text-zinc-500">Click a finger to select</p>
                                )}
                            </div>
                        </div>

                        {/* RIGHT: Scanner + registered list */}
                        <div className="flex flex-col gap-4">

                            {/* Scanner area */}
                            <div>
                                <p className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest mb-3">Scanner</p>
                                <ScannerArea
                                    state={selectedFinger !== null ? scanState : "idle"}
                                    bitmap={capturedData?.bitmap}
                                    quality={capturedData?.quality ?? 0}
                                    onScan={handleScan}
                                />
                            </div>

                            {/* Error */}
                            {errorMsg && (
                                <div className="text-[10px] text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2">
                                    {errorMsg}
                                </div>
                            )}

                            {/* Save button */}
                            {scanState === "success" && capturedData && (
                                <button
                                    onClick={handleSave}
                                    disabled={saving}
                                    className="w-full py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-[11px] font-semibold transition-all disabled:opacity-60 active:scale-[0.98]"
                                >
                                    {saving ? "Saving…" : "Save Fingerprint"}
                                </button>
                            )}

                            {/* Registered fingers list */}
                            <div>
                                <p className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest mb-2">Registered Fingers</p>
                                {loadingDetails ? (
                                    <p className="text-[10px] text-zinc-400 italic">Loading…</p>
                                ) : registeredIndices.length === 0 ? (
                                    <p className="text-[10px] text-zinc-400 dark:text-zinc-500 italic">No fingerprints registered yet.</p>
                                ) : (
                                    <div className="flex flex-col gap-1 max-h-40 overflow-auto">
                                        {registeredIndices.sort((a,b)=>a-b).map(fi => {
                                            const detail = detailMap[fi];
                                            return (
                                                <div key={fi} className="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg bg-zinc-50 dark:bg-zinc-800 border border-zinc-100 dark:border-zinc-700 group">
                                                    <div className="flex items-center gap-2 min-w-0">
                                                        <span className="w-4 h-4 rounded-full bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400 text-[8px] flex items-center justify-center font-bold flex-shrink-0">✓</span>
                                                        <div className="min-w-0">
                                                            <p className="text-[10px] font-medium text-zinc-700 dark:text-zinc-200 truncate">{FINGER_FULL[fi]}</p>
                                                            {detail && (
                                                                <p className="text-[8px] text-zinc-400 dark:text-zinc-500">
                                                                    Q:{detail.quality}% · {detail.created_at}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <button
                                                        onClick={() => handleDelete(fi)}
                                                        className="text-[9px] text-red-400 hover:text-red-600 dark:hover:text-red-300 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0 px-1"
                                                        title="Remove"
                                                    >
                                                        ✕
                                                    </button>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── Employee Card ─────────────────────────────────────────────────────────────

function EmployeeCard({ employee, registrations = [], onClick }) {
    const regCount = registrations.length;
    const statusColor = regCount === 0
        ? "bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border-red-200 dark:border-red-800"
        : regCount < 5
            ? "bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-800"
            : "bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 border-green-200 dark:border-green-800";

    return (
        <button
            onClick={onClick}
            className="text-left w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-3.5 hover:border-zinc-400 dark:hover:border-zinc-500 hover:shadow-md transition-all duration-200 group active:scale-[0.98]"
        >
            {/* Top row: name + status badge */}
            <div className="flex items-start justify-between gap-2 mb-2">
                <div className="min-w-0">
                    <p className="text-[11px] font-semibold text-zinc-800 dark:text-zinc-100 truncate leading-tight">
                        {employee.EMPNAME}
                    </p>
                    <p className="text-[9px] text-zinc-400 dark:text-zinc-500 mt-0.5">{employee.EMPLOYID}</p>
                </div>
                <span className={`flex-shrink-0 text-[8px] font-bold px-1.5 py-0.5 rounded-full border ${statusColor}`}>
                    {regCount}/10
                </span>
            </div>

            {/* Department / job */}
            <p className="text-[9px] text-zinc-500 dark:text-zinc-400 truncate mb-3">
                {employee.DEPARTMENT}{employee.JOB_TITLE ? ` · ${employee.JOB_TITLE}` : ""}
            </p>

            {/* Finger dots */}
            <div className="flex gap-0.5 flex-wrap">
                {Array.from({ length: 10 }).map((_, i) => {
                    const isReg = registrations.some(r => r.finger_index === i);
                    return (
                        <span
                            key={i}
                            title={FINGER_FULL[i]}
                            className={`w-3.5 h-3.5 rounded-full transition-colors
                                ${isReg
                                    ? "bg-green-400 dark:bg-green-500"
                                    : "bg-zinc-200 dark:bg-zinc-700"
                                }`}
                        />
                    );
                })}
            </div>

            {/* Scan hint */}
            <p className="text-[8px] text-zinc-400 dark:text-zinc-600 mt-2 group-hover:text-zinc-600 dark:group-hover:text-zinc-400 transition-colors">
                Click to register fingerprint →
            </p>
        </button>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function RegisterFingerprint({ employees = [], registrations = {} }) {
    const { app_name } = usePage().props;
    const { capture, deviceStatus } = useDigitalPersona();

    const [search,      setSearch]      = useState("");
    const [deptFilter,  setDeptFilter]  = useState("");
    const [statusFilter,setStatusFilter]= useState(""); // all | registered | partial | none
    const [modalEmp,    setModalEmp]    = useState(null);
    const [regMap,      setRegMap]      = useState(registrations);

    const departments = [...new Set(employees.map(e => e.DEPARTMENT).filter(Boolean))].sort();

    const filtered = employees.filter(emp => {
        const q  = search.toLowerCase();
        const ok = !q || emp.EMPNAME.toLowerCase().includes(q) || emp.EMPLOYID.toLowerCase().includes(q);
        const dOk = !deptFilter || emp.DEPARTMENT === deptFilter;
        const regs = (regMap[emp.EMPLOYID] ?? []).length;
        const sOk  = !statusFilter
            || (statusFilter === "registered" && regs === 10)
            || (statusFilter === "partial"    && regs > 0 && regs < 10)
            || (statusFilter === "none"       && regs === 0);
        return ok && dOk && sOk;
    });

    const handleSaved = useCallback((employId, updatedRegs) => {
        setRegMap(prev => ({ ...prev, [employId]: updatedRegs }));
    }, []);

    const totalRegs  = employees.reduce((s, e) => s + ((regMap[e.EMPLOYID] ?? []).length > 0 ? 1 : 0), 0);
    const totalNone  = employees.reduce((s, e) => s + ((regMap[e.EMPLOYID] ?? []).length === 0 ? 1 : 0), 0);

    return (
        <AuthenticatedLayout>
            <Head title="Register Fingerprint" />

            {/* Scan-line keyframe */}
            <style>{`
                @keyframes scanline {
                    0%   { top: 0%; }
                    50%  { top: 100%; }
                    100% { top: 0%; }
                }
            `}</style>

            <div className="h-full flex flex-col overflow-hidden">

                {/* Page header */}
                <div className="px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4 flex-shrink-0">
                    <div>
                        <h1 className="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Fingerprint Registration</h1>
                        <p className="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">
                            {totalRegs} of {employees.length} employees have at least one finger registered · {totalNone} unregistered
                        </p>
                    </div>

                    {/* Filters */}
                    <div className="flex items-center gap-2 overflow-x-auto flex-nowrap min-w-0">

                        {/* Device status badge */}
                        <span className={`flex-shrink-0 text-[9px] font-semibold px-2 py-1 rounded-full border
                            ${deviceStatus === "ready"
                                ? "bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 border-green-200 dark:border-green-800"
                                : deviceStatus === "sdk_missing"
                                    ? "bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border-red-200 dark:border-red-800"
                                    : "bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-800"
                            }`}
                        >
                            {deviceStatus === "ready"        && "🟢 Scanner Ready"}
                            {deviceStatus === "connecting"   && "🟡 Waiting for device…"}
                            {deviceStatus === "disconnected" && "🔴 Device disconnected"}
                            {deviceStatus === "sdk_missing"  && "⚠ SDK not loaded"}
                        </span>
                        {/* Search */}
                        <div className="relative flex-shrink-0">
                            <svg className="absolute left-2 top-1/2 -translate-y-1/2 w-3 h-3 text-zinc-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
                            </svg>
                            <input
                                type="text"
                                placeholder="Search employee…"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                className="text-[10px] rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 pl-6 pr-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400 w-40"
                            />
                        </div>

                        {/* Department */}
                        <select
                            value={deptFilter}
                            onChange={e => setDeptFilter(e.target.value)}
                            className="flex-shrink-0 text-[10px] rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        >
                            <option value="">All Departments</option>
                            {departments.map(d => <option key={d} value={d}>{d}</option>)}
                        </select>

                        {/* Status */}
                        <select
                            value={statusFilter}
                            onChange={e => setStatusFilter(e.target.value)}
                            className="flex-shrink-0 text-[10px] rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        >
                            <option value="">All Status</option>
                            <option value="registered">Fully Registered (10/10)</option>
                            <option value="partial">Partially Registered</option>
                            <option value="none">Not Registered</option>
                        </select>
                    </div>
                </div>

                {/* Results count */}
                <div className="px-5 py-2 border-b border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                    <p className="text-[9px] text-zinc-400 dark:text-zinc-500">
                        Showing {filtered.length} of {employees.length} employees
                    </p>
                </div>

                {/* Cards grid */}
                <div className="flex-1 overflow-auto p-5">
                    {filtered.length === 0 ? (
                        <div className="flex items-center justify-center h-32 text-[11px] text-zinc-400 dark:text-zinc-500">
                            No employees found.
                        </div>
                    ) : (
                        <div
                            className="grid gap-3"
                            style={{ gridTemplateColumns: "repeat(auto-fill, minmax(190px, 1fr))" }}
                        >
                            {filtered.map(emp => (
                                <EmployeeCard
                                    key={emp.EMPLOYID}
                                    employee={emp}
                                    registrations={regMap[emp.EMPLOYID] ?? []}
                                    onClick={() => setModalEmp(emp)}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Registration modal */}
            {modalEmp && (
                <RegistrationModal
                    employee={modalEmp}
                    existingRegistrations={regMap[modalEmp.EMPLOYID] ?? []}
                    appName={app_name}
                    capture={capture}
                    deviceStatus={deviceStatus}
                    onClose={() => setModalEmp(null)}
                    onSaved={handleSaved}
                />
            )}
        </AuthenticatedLayout>
    );
}