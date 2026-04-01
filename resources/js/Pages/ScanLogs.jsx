import { useState } from "react";
import {
    Scan,
    RefreshCw,
    AlertTriangle,
    Clock,
    StopCircle,
    Calendar,
    LogIn,
    LogOut,
    Coffee,
    Lock,
    CheckCircle,
    Zap,
    RotateCcw,
    Wifi,
    WifiOff,
    Loader2,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { Separator } from "@/components/ui/separator";
import { cn } from "@/lib/utils";

/* ─────────────────────────────────────────────────────────────────────────────
   STATIC MOCK DATA (UI only)
───────────────────────────────────────────────────────────────────────────── */
const MOCK_EMPLOYEE = {
    EMPNAME: "Maria Santos",
    EMPLOYID: "EMP-00142",
    DEPARTMENT: "Operations",
    JOB_TITLE: "Senior Analyst",
    PRODLINE: "Production Line A",
    SHIFT: 1,
    IS_REST_DAY: false,
    IS_ON_LEAVE: false,
    SCHEDULE: "08:00 AM – 05:00 PM",
    SHIFT_LABEL: "Normal",
};

const LOG_SEQUENCE = [
    { key: "time_in",     label: "Time In",     step: 1, color: "success", icon: <LogIn  size={13} /> },
    { key: "break_out_1", label: "Break Out 1", step: 2, color: "warning", icon: <Coffee size={13} /> },
    { key: "break_in_1",  label: "Break In 1",  step: 3, color: "info",    icon: <LogIn  size={13} /> },
    { key: "lunch_out",   label: "Lunch Out",   step: 4, color: "warning", icon: <Coffee size={13} /> },
    { key: "lunch_in",    label: "Lunch In",    step: 5, color: "info",    icon: <LogIn  size={13} /> },
    { key: "break_out_2", label: "Break Out 2", step: 6, color: "warning", icon: <Coffee size={13} /> },
    { key: "break_in_2",  label: "Break In 2",  step: 7, color: "info",    icon: <LogIn  size={13} /> },
    { key: "time_out",    label: "Time Out",    step: 8, color: "error",   icon: <LogOut size={13} /> },
];

const MOCK_DONE_LOGS = {
    time_in:     "08:03 AM",
    break_out_1: "10:15 AM",
    break_in_1:  "10:32 AM",
};

const MOCK_HISTORY = [
    {
        date: "Today, Apr 1",
        isToday: true,
        entries: [
            { label: "Time In",     time: "08:03 AM", color: "success" },
            { label: "Break Out 1", time: "10:15 AM", color: "warning" },
            { label: "Break In 1",  time: "10:32 AM", color: "info"    },
        ],
    },
    {
        date: "Mon, Mar 31",
        isToday: false,
        entries: [
            { label: "Time In",  time: "07:58 AM", color: "success" },
            { label: "Lunch Out",time: "12:01 PM", color: "warning" },
            { label: "Lunch In", time: "01:02 PM", color: "info"    },
            { label: "Time Out", time: "05:04 PM", color: "error"   },
        ],
    },
    {
        date: "Sun, Mar 30",
        isToday: false,
        entries: [],
    },
    {
        date: "Sat, Mar 29",
        isToday: false,
        entries: [
            { label: "Time In",  time: "08:11 AM", color: "success" },
            { label: "Time Out", time: "05:00 PM", color: "error"   },
        ],
    },
    {
        date: "Fri, Mar 28",
        isToday: false,
        entries: [
            { label: "Time In",  time: "08:05 AM", color: "success" },
            { label: "Lunch Out",time: "12:03 PM", color: "warning" },
            { label: "Lunch In", time: "01:00 PM", color: "info"    },
            { label: "Time Out", time: "05:02 PM", color: "error"   },
        ],
    },
];

/* ─────────────────────────────────────────────────────────────────────────────
   COLOR TOKENS
───────────────────────────────────────────────────────────────────────────── */
const STEP_COLORS = {
    success: {
        bg: "bg-emerald-500/10",
        border: "border-emerald-500/30",
        text: "text-emerald-400",
        dot: "bg-emerald-400",
        badge: "bg-emerald-500/20 text-emerald-300 border-emerald-500/30",
    },
    error: {
        bg: "bg-red-500/10",
        border: "border-red-500/30",
        text: "text-red-400",
        dot: "bg-red-400",
        badge: "bg-red-500/20 text-red-300 border-red-500/30",
    },
    warning: {
        bg: "bg-amber-500/10",
        border: "border-amber-500/30",
        text: "text-amber-400",
        dot: "bg-amber-400",
        badge: "bg-amber-500/20 text-amber-300 border-amber-500/30",
    },
    info: {
        bg: "bg-sky-500/10",
        border: "border-sky-500/30",
        text: "text-sky-400",
        dot: "bg-sky-400",
        badge: "bg-sky-500/20 text-sky-300 border-sky-500/30",
    },
};

/* ─────────────────────────────────────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────────────────────────────────────── */
const AVATAR_SEEDS = ["#06b6d4", "#8b5cf6", "#f59e0b", "#10b981", "#ef4444", "#ec4899"];
function avatarColor(name = "") {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = name.charCodeAt(i) + ((h << 5) - h);
    return AVATAR_SEEDS[Math.abs(h) % AVATAR_SEEDS.length];
}
function getInitials(name = "") {
    return name.trim().split(/\s+/).slice(0, 2).map(n => n[0]).join("").toUpperCase() || "?";
}

/* ─────────────────────────────────────────────────────────────────────────────
   SUB-COMPONENTS
───────────────────────────────────────────────────────────────────────────── */
function Avatar({ name, size = 56 }) {
    const color = avatarColor(name);
    return (
        <div
            className="rounded-full flex items-center justify-center font-bold tracking-wider shrink-0 select-none"
            style={{
                width: size, height: size,
                fontSize: size * 0.32,
                color,
                background: `linear-gradient(135deg, ${color}28, ${color}10)`,
                border: `2px solid ${color}55`,
                boxShadow: `0 0 0 4px ${color}12`,
                fontFamily: "'Rajdhani', sans-serif",
            }}
        >
            {getInitials(name)}
        </div>
    );
}

function ScanRing({ state, size = 120 }) {
    // state: "idle" | "scanning" | "success" | "error" | "locked"
    const ringColors = {
        idle:     "border-zinc-600",
        scanning: "border-violet-500",
        success:  "border-emerald-500",
        error:    "border-red-500",
        locked:   "border-zinc-500",
    };
    const isActive = state === "scanning";

    return (
        <div className="relative flex items-center justify-center" style={{ width: size, height: size }}>
            {isActive && (
                <>
                    <div className="absolute rounded-full border border-violet-400/30 animate-ping" style={{ inset: -8 }} />
                    <div className="absolute rounded-full border border-violet-400/20 animate-ping" style={{ inset: -16, animationDelay: "0.3s" }} />
                </>
            )}
            <div
                className={cn(
                    "rounded-full border-2 flex items-center justify-center overflow-hidden transition-all duration-500",
                    ringColors[state]
                )}
                style={{ width: size, height: size, background: "rgba(255,255,255,0.03)" }}
            >
                {state === "scanning" && (
                    <Loader2 size={size * 0.35} className="text-violet-400 animate-spin" />
                )}
                {state === "idle" && (
                    <Scan size={size * 0.4} className="text-zinc-500" />
                )}
                {state === "success" && (
                    <CheckCircle size={size * 0.4} className="text-emerald-400" />
                )}
                {state === "error" && (
                    <AlertTriangle size={size * 0.4} className="text-red-400" />
                )}
                {state === "locked" && (
                    <Lock size={size * 0.4} className="text-zinc-400" />
                )}
            </div>
        </div>
    );
}

function DevicePill({ status }) {
    const cfg = {
        ok:              { color: "text-emerald-400", dot: "bg-emerald-400", label: "HU20-A",    pulse: true  },
        offline:         { color: "text-red-400",     dot: "bg-red-400",     label: "Offline",   pulse: false },
        "no-device":     { color: "text-amber-400",   dot: "bg-amber-400",   label: "No Reader", pulse: false },
        checking:        { color: "text-sky-400",     dot: "bg-sky-400",     label: "Checking",  pulse: true  },
    };
    const c = cfg[status] ?? cfg.offline;
    return (
        <button className="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/5 border border-white/10 hover:bg-white/10 transition-colors text-xs">
            <span className={cn("w-1.5 h-1.5 rounded-full", c.dot, c.pulse && "animate-pulse")} />
            <span className={c.color}>{c.label}</span>
            <RefreshCw size={9} className="text-zinc-500" />
        </button>
    );
}

/* ── Punch selector grid (compact inside scanner) ── */
function PunchGrid({ doneMap, sequence }) {
    return (
        <div className="grid grid-cols-4 gap-1.5 p-4">
            {sequence.map((meta) => {
                const isDone = !!doneMap[meta.key];
                const c = STEP_COLORS[meta.color];
                return (
                    <button
                        key={meta.key}
                        disabled={isDone}
                        className={cn(
                            "relative flex flex-col items-center gap-1 rounded-xl px-2 py-2.5 border text-center transition-all",
                            isDone
                                ? cn(c.bg, c.border, "cursor-default")
                                : "bg-white/5 border-white/10 hover:bg-white/10 hover:border-white/20 active:scale-95"
                        )}
                    >
                        <span className="text-[9px] text-zinc-500 font-mono leading-none">{meta.step}</span>
                        <span className={cn("text-sm", isDone ? c.text : "text-zinc-400")}>{meta.icon}</span>
                        <span className={cn("text-[9px] font-semibold leading-tight", isDone ? c.text : "text-zinc-300")}>{meta.label}</span>
                        {isDone ? (
                            <span className={cn("text-[9px] font-mono font-bold", c.text)}>{doneMap[meta.key]}</span>
                        ) : (
                            <span className="text-[8px] text-zinc-600 font-mono">TAP</span>
                        )}
                        {isDone && <CheckCircle size={9} className={cn("absolute top-1.5 right-1.5", c.text)} />}
                    </button>
                );
            })}
        </div>
    );
}

/* ── Punch selector list (right panel, after match) ── */
function PunchSelector({ employee }) {
    const c = avatarColor(employee.EMPNAME);
    return (
        <div className="flex flex-col gap-4 animate-in fade-in slide-in-from-bottom-2 duration-300">
            {/* Verified badge */}
            <div className="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 flex items-center gap-3">
                <Avatar name={employee.EMPNAME} size={52} />
                <div className="flex-1 min-w-0">
                    <p className="text-lg font-bold text-white truncate" style={{ fontFamily: "'Rajdhani', sans-serif", letterSpacing: "0.03em" }}>
                        {employee.EMPNAME}
                    </p>
                    <div className="flex flex-wrap gap-1.5 mt-1">
                        <Badge variant="outline" className="text-[10px] font-mono text-zinc-400 border-zinc-600">{employee.EMPLOYID}</Badge>
                        <Badge variant="outline" className="text-[10px] text-zinc-400 border-zinc-600">{employee.DEPARTMENT}</Badge>
                        <Badge variant="outline" className="text-[10px] text-emerald-400 border-emerald-500/30 bg-emerald-500/10">Q 87 · Excellent</Badge>
                    </div>
                </div>
                <div className="text-right shrink-0">
                    <span className="inline-block px-2.5 py-1 rounded-full bg-emerald-500 text-white text-[10px] font-bold tracking-wider">✓ VERIFIED</span>
                    <p className="text-[10px] text-zinc-500 font-mono mt-1.5">10:32 AM</p>
                </div>
            </div>

            {/* Punch list */}
            <Card className="border-zinc-800 bg-zinc-900/60">
                <CardHeader className="pb-2 pt-4 px-4">
                    <p className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Select Punch Type</p>
                </CardHeader>
                <CardContent className="px-4 pb-4">
                    <div className="grid grid-cols-2 gap-2">
                        {LOG_SEQUENCE.map((meta) => {
                            const isDone = !!MOCK_DONE_LOGS[meta.key];
                            const c = STEP_COLORS[meta.color];
                            return (
                                <button
                                    key={meta.key}
                                    disabled={isDone}
                                    className={cn(
                                        "flex items-center gap-2.5 rounded-xl border px-3 py-2.5 text-left transition-all",
                                        isDone
                                            ? cn(c.bg, c.border, "cursor-default")
                                            : "bg-white/4 border-zinc-800 hover:bg-white/8 hover:border-zinc-700 active:scale-[0.98]"
                                    )}
                                >
                                    <span className={cn(
                                        "w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold shrink-0",
                                        isDone ? cn(c.text, "bg-white/10") : "bg-zinc-800 text-zinc-400"
                                    )}>{meta.step}</span>
                                    <span className={cn("text-sm", isDone ? c.text : "text-zinc-400")}>{meta.icon}</span>
                                    <span className={cn("text-xs font-semibold flex-1", isDone ? c.text : "text-zinc-300")}>{meta.label}</span>
                                    {isDone ? (
                                        <div className="flex items-center gap-1">
                                            <span className={cn("text-[10px] font-mono", c.text)}>{MOCK_DONE_LOGS[meta.key]}</span>
                                            <CheckCircle size={12} className={c.text} />
                                        </div>
                                    ) : (
                                        <span className="text-[9px] font-mono text-zinc-600">TAP</span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

/* ── History panel day row ── */
function HistoryDayRow({ day }) {
    return (
        <div className={cn(
            "rounded-xl border p-3 mb-2",
            day.isToday ? "border-violet-500/30 bg-violet-500/5" : "border-zinc-800 bg-zinc-900/30"
        )}>
            <div className="flex items-center gap-2 mb-2">
                {day.isToday && <span className="w-1.5 h-1.5 rounded-full bg-violet-400 animate-pulse shrink-0" />}
                <span className={cn(
                    "text-xs font-semibold",
                    day.isToday ? "text-violet-300" : "text-zinc-400"
                )}>{day.date}</span>
                {day.entries.length === 0 && (
                    <span className="ml-auto text-[10px] text-zinc-600">no logs</span>
                )}
            </div>
            {day.entries.length > 0 && (
                <div className={cn(
                    "rounded-lg p-2 flex flex-col gap-1.5",
                    day.isToday ? "bg-violet-500/5 border border-violet-500/15" : "bg-zinc-900/50 border border-zinc-800"
                )}>
                    {day.entries.map((entry, i) => {
                        const c = STEP_COLORS[entry.color];
                        return (
                            <div key={i} className="flex items-center gap-2">
                                <span className={cn("w-1.5 h-1.5 rounded-full shrink-0", c.dot)} />
                                <span className={cn("text-[11px] font-semibold flex-1", c.text)}>{entry.label}</span>
                                <span className={cn("text-[10px] font-mono font-semibold", c.text)}>{entry.time}</span>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/* ─────────────────────────────────────────────────────────────────────────────
   MAIN PAGE DEMO STATES: idle | scanning | matched | saved
───────────────────────────────────────────────────────────────────────────── */
export default function ScanLogs() {
    const [demoState, setDemoState] = useState("idle");
    // idle → scanning → matched → saved → idle

    const cycleState = () => {
        setDemoState(s => ({ idle: "scanning", scanning: "matched", matched: "saved", saved: "idle" }[s]));
    };

    const scanRingState =
        demoState === "idle"     ? "idle"
        : demoState === "scanning" ? "scanning"
        : demoState === "matched"  ? "success"
        : "success";

    const deviceStatus = "ok";

    const doneCount = Object.keys(MOCK_DONE_LOGS).length;
    const progress  = Math.round((doneCount / LOG_SEQUENCE.length) * 100);
    const accentColor = avatarColor(MOCK_EMPLOYEE.EMPNAME);

    return (
        <div className="min-h-screen bg-zinc-950 text-white" style={{ fontFamily: "'Inter', sans-serif" }}>

            {/* ── Google font import via style tag ── */}
            <style>{`
                @import url('https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap');
                .att-mono { font-family: 'JetBrains Mono', monospace; }
                .att-rajdhani { font-family: 'Rajdhani', sans-serif; }
                .scan-sweep {
                    background: linear-gradient(90deg, transparent, rgba(139,92,246,0.6), transparent);
                    animation: sweep 1.4s linear infinite;
                    height: 2px; position: absolute; left: 0; right: 0;
                }
                @keyframes sweep { 0% { top: 10% } 100% { top: 90% } }
            `}</style>

            {/* ── Toast demo ── */}
            <div className="fixed top-4 right-4 z-50 flex flex-col gap-2 pointer-events-none">
                {demoState === "saved" && (
                    <div className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-500/20 border border-emerald-500/30 shadow-xl text-sm text-emerald-300 animate-in fade-in slide-in-from-top-2 duration-300">
                        <CheckCircle size={14} /> ✓ Maria Santos — Break In 1
                    </div>
                )}
            </div>

            <div className="flex h-screen overflow-hidden">

                {/* ═══════════════════════════════════════
                    LEFT COLUMN
                ═══════════════════════════════════════ */}
                <div className="flex flex-col flex-1 min-w-0 overflow-hidden border-r border-zinc-800/60">

                    {/* ── Top bar ── */}
                    <div className="flex items-center justify-between px-5 py-3 border-b border-zinc-800/60 bg-zinc-950/80 backdrop-blur-sm shrink-0">
                        <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-lg bg-violet-500/15 border border-violet-500/30 flex items-center justify-center">
                                <Zap size={15} className="text-violet-400" />
                            </div>
                            <div>
                                <p className="text-xs font-bold tracking-[0.15em] text-white att-rajdhani leading-none">ATTENDANCE</p>
                                <p className="text-[9px] tracking-[0.1em] text-zinc-500 leading-none mt-0.5">TIME LOGGING SYSTEM</p>
                            </div>
                            <div className="w-px h-8 bg-zinc-800 mx-1" />
                            <div className="flex items-center gap-1.5 text-[11px] text-zinc-400">
                                <Calendar size={11} className="text-zinc-500 shrink-0" />
                                Tuesday, April 1, 2026
                            </div>
                        </div>

                        {/* Demo state toggle */}
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] text-zinc-500">Demo:</span>
                            <Button onClick={cycleState} size="sm" variant="outline"
                                className="text-xs h-7 px-3 border-zinc-700 hover:border-violet-500/50 hover:text-violet-300 transition-colors">
                                {demoState === "idle" ? "Tap to Scan" : demoState === "scanning" ? "Scanning…" : demoState === "matched" ? "Matched" : "Saved"} →
                            </Button>
                            <div className="flex items-center gap-1.5 bg-zinc-900 border border-zinc-800 rounded-full px-3 py-1">
                                <span className="text-lg font-bold att-rajdhani text-white leading-none">24</span>
                                <div>
                                    <p className="text-[8px] font-semibold text-zinc-400 leading-none">scans</p>
                                    <p className="text-[8px] text-zinc-600 leading-none">today</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex-1 overflow-y-auto p-4 flex flex-col gap-4 min-h-0">

                        {/* ── Employee hero card (visible after match) ── */}
                        {(demoState === "matched" || demoState === "saved") && (
                            <Card className="border-zinc-800 bg-zinc-900/60 overflow-hidden animate-in fade-in slide-in-from-bottom-2 duration-300">
                                {/* Accent top bar */}
                                <div style={{ height: 3, background: `linear-gradient(90deg, transparent, ${accentColor}, transparent)` }} />
                                <CardContent className="p-0">
                                    {/* Band */}
                                    <div
                                        className="flex flex-col items-center gap-3 px-5 py-5 relative"
                                        style={{ background: `linear-gradient(160deg, ${accentColor}12 0%, transparent 60%)` }}
                                    >
                                        {demoState === "saved" && (
                                            <div className="absolute top-3 right-3">
                                                <Badge className="bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[10px]">Saved</Badge>
                                            </div>
                                        )}
                                        <Avatar name={MOCK_EMPLOYEE.EMPNAME} size={72} />
                                        <div className="text-center">
                                            <p className="text-2xl font-bold text-white att-rajdhani">{MOCK_EMPLOYEE.EMPNAME}</p>
                                            <p className="text-xs text-zinc-400 mt-0.5">{MOCK_EMPLOYEE.JOB_TITLE}</p>
                                        </div>
                                        <div className="flex items-center gap-2 flex-wrap justify-center">
                                            <Badge variant="outline" className="text-[10px] font-mono text-zinc-400 border-zinc-700">{MOCK_EMPLOYEE.EMPLOYID}</Badge>
                                            {demoState === "saved" && (
                                                <Badge variant="outline" className="text-[10px] font-mono text-emerald-400 border-emerald-500/30">10:32 AM</Badge>
                                            )}
                                        </div>
                                    </div>

                                    <Separator className="bg-zinc-800" />

                                    {/* Info grid */}
                                    <div className="grid grid-cols-2 divide-x divide-y divide-zinc-800/60">
                                        {[
                                            { label: "Department", value: MOCK_EMPLOYEE.DEPARTMENT },
                                            { label: "Prod. Line", value: MOCK_EMPLOYEE.PRODLINE   },
                                            { label: "Shift",      value: MOCK_EMPLOYEE.SHIFT_LABEL },
                                            { label: "Schedule",   value: MOCK_EMPLOYEE.SCHEDULE, mono: true },
                                        ].map(({ label, value, mono }) => (
                                            <div key={label} className="px-4 py-3">
                                                <p className="text-[9px] uppercase tracking-widest text-zinc-600 mb-0.5">{label}</p>
                                                <p className={cn("text-xs font-semibold text-zinc-200", mono && "att-mono text-[11px]")}>
                                                    {value || "—"}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* ── Scanner card ── */}
                        <Card className="border-zinc-800 bg-zinc-900/40 flex-1 flex flex-col min-h-0">
                            <CardContent className="p-0 flex flex-col flex-1 min-h-0">

                                {/* Scanner area */}
                                <div className="relative p-5 flex flex-col items-center gap-4">
                                    <ScanRing state={scanRingState} size={120} />

                                    {/* Status text */}
                                    <div className="text-center">
                                        <p className={cn(
                                            "text-sm font-semibold",
                                            demoState === "scanning" ? "text-violet-300"
                                            : demoState === "matched" || demoState === "saved" ? "text-emerald-400"
                                            : "text-zinc-400"
                                        )}>
                                            {demoState === "idle"     ? "Place finger on reader"
                                            : demoState === "scanning" ? "Reading fingerprint…"
                                            : demoState === "matched"  ? "Matching identity…"
                                            : "Punch saved successfully"}
                                        </p>
                                        {demoState === "scanning" && (
                                            <div className="flex items-center justify-center gap-2 mt-2">
                                                <div className="h-1 w-24 rounded-full bg-zinc-800 overflow-hidden">
                                                    <div className="h-full w-1/2 bg-violet-500 rounded-full animate-pulse" />
                                                </div>
                                                <Badge variant="outline" className="text-[10px] font-mono text-violet-400 border-violet-500/30">87</Badge>
                                            </div>
                                        )}
                                    </div>

                                    {/* Controls */}
                                    <div className="flex items-center gap-2">
                                        <DevicePill status={deviceStatus} />
                                        <Button size="sm" variant="outline"
                                            className="h-7 text-xs gap-1 border-zinc-700 hover:border-zinc-600">
                                            <Scan size={11} /> Re-Scan
                                        </Button>
                                    </div>

                                    {/* Punch grid modal overlay (matched state) */}
                                    {demoState === "matched" && (
                                        <div className="w-full rounded-2xl border border-zinc-700 bg-zinc-900/90 backdrop-blur-sm animate-in fade-in slide-in-from-bottom-2 duration-300">
                                            <p className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 px-4 pt-4">Select Punch Type</p>
                                            <PunchGrid doneMap={MOCK_DONE_LOGS} sequence={LOG_SEQUENCE} />
                                        </div>
                                    )}
                                </div>

                                <Separator className="bg-zinc-800" />

                                {/* ── Today's shift log strip ── */}
                                {(demoState === "matched" || demoState === "saved") && (
                                    <div className="px-4 py-4 animate-in fade-in duration-300">
                                        <div className="flex items-center justify-between mb-3">
                                            <p className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Today's Shift Logs</p>
                                            <Badge className="bg-amber-500/15 text-amber-300 border border-amber-500/25 text-[10px]">3 / 8</Badge>
                                        </div>

                                        <div className="grid gap-1" style={{ gridTemplateColumns: `repeat(${LOG_SEQUENCE.length}, 1fr)` }}>
                                            {LOG_SEQUENCE.map((s) => {
                                                const isDone = !!MOCK_DONE_LOGS[s.key];
                                                const c = STEP_COLORS[s.color];
                                                const isLatest = demoState === "saved" && s.key === "break_in_1";
                                                return (
                                                    <div
                                                        key={s.key}
                                                        className={cn(
                                                            "flex flex-col items-center rounded-lg border px-0.5 py-2 gap-0.5 text-center transition-all",
                                                            isDone ? cn(c.bg, c.border) : "bg-zinc-900/60 border-zinc-800/60",
                                                            isLatest && "ring-1 ring-offset-1 ring-offset-zinc-900"
                                                        )}
                                                        style={isLatest ? { "--tw-ring-color": "#10b981" } : {}}
                                                    >
                                                        <span className={cn("text-[7px] font-mono", isDone ? c.text + " opacity-70" : "text-zinc-600")}>{s.step}</span>
                                                        <span className={cn("text-sm", isDone ? c.text : "text-zinc-600")} style={{ fontSize: 12 }}>{s.icon}</span>
                                                        <span className={cn("text-[7px] font-semibold leading-tight", isDone ? c.text : "text-zinc-500")}>{s.label}</span>
                                                        <span className={cn("text-[7px] font-mono font-bold", isDone ? c.text : "text-zinc-700")}>
                                                            {isDone ? MOCK_DONE_LOGS[s.key] : "—"}
                                                        </span>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        <div className="mt-3">
                                            <div className="flex justify-between mb-1">
                                                <span className="text-[10px] text-zinc-500">{doneCount} of {LOG_SEQUENCE.length} punches</span>
                                                <span className="text-[10px] att-mono text-zinc-500 font-semibold">{progress}%</span>
                                            </div>
                                            <Progress value={progress} className="h-1.5 bg-zinc-800" />
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                    </div>
                </div>

                {/* ═══════════════════════════════════════
                    RIGHT COLUMN — History
                ═══════════════════════════════════════ */}
                <div className="w-72 shrink-0 flex flex-col overflow-hidden border-l border-zinc-800/60 bg-zinc-950/50">

                    <div className="flex items-center gap-2.5 px-4 py-3 border-b border-zinc-800/60 shrink-0">
                        <div className="w-6 h-6 rounded-md bg-amber-500/15 border border-amber-500/25 flex items-center justify-center">
                            <Clock size={12} className="text-amber-400" />
                        </div>
                        <span className="text-xs font-semibold text-zinc-300 tracking-wide">Log Time History</span>
                    </div>

                    {demoState === "idle" && demoState === "scanning" ? (
                        /* Empty state */
                        <div className="flex-1 flex flex-col items-center justify-center gap-3 p-6">
                            <div className="w-12 h-12 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center">
                                <Clock size={20} className="text-zinc-600" />
                            </div>
                            <p className="text-xs text-zinc-500 text-center leading-relaxed">
                                Scan a finger<br />to view history
                            </p>
                        </div>
                    ) : (
                        <>
                            {/* Matched employee header */}
                            {(demoState === "matched" || demoState === "saved") && (
                                <div className="px-3 pt-3 pb-2 border-b border-zinc-800/60 shrink-0 animate-in fade-in duration-300">
                                    <div className="flex items-center gap-2.5 p-2.5 rounded-xl bg-zinc-900/60 border border-zinc-800">
                                        <Avatar name={MOCK_EMPLOYEE.EMPNAME} size={34} />
                                        <div className="min-w-0">
                                            <p className="text-xs font-bold text-white truncate att-rajdhani">{MOCK_EMPLOYEE.EMPNAME}</p>
                                            <p className="text-[10px] text-zinc-500 att-mono">{MOCK_EMPLOYEE.EMPLOYID}</p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* 5-day log */}
                            <div className="flex-1 overflow-y-auto p-3 min-h-0">
                                <p className="text-[9px] font-semibold uppercase tracking-widest text-zinc-600 mb-2 flex items-center gap-1.5">
                                    <span className="w-1 h-1 rounded-full bg-violet-500" />
                                    5-Day Attendance Log
                                </p>
                                {(demoState === "idle" || demoState === "scanning") ? (
                                    <div className="flex flex-col items-center justify-center py-12 gap-3">
                                        <Clock size={22} className="text-zinc-700" />
                                        <p className="text-xs text-zinc-600 text-center">Scan a finger to view history</p>
                                    </div>
                                ) : (
                                    MOCK_HISTORY.map((day) => (
                                        <HistoryDayRow key={day.date} day={day} />
                                    ))
                                )}
                            </div>
                        </>
                    )}
                </div>

            </div>
        </div>
    );
}