import React, { useState, useEffect, useCallback, useRef } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import axios from 'axios';
import { router, usePage } from "@inertiajs/react";
import {
    UserOutlined,
    TeamOutlined,
    SearchOutlined,
    ScanOutlined,
    IdcardOutlined,
    CloseOutlined,
    CheckCircleFilled,
    CloseCircleFilled,
    ClockCircleOutlined,
} from "@ant-design/icons";

import {
    findEmployeeByCode,
} from "@/utils/scannerHelpers";

import { captureFingerprint, matchFingerprint } from "@/utils/secugenClient";

// Shift code mapping - update this with your actual shift codes
const mockShiftCodeMap = {
    // Add your shift code mapping here
    // Example:
    // "1": { shiftcode: "MORNING" },
    // "2": { shiftcode: "AFTERNOON" },
    // "3": { shiftcode: "NIGHT_RD" },
};



// ─── Fingerprint Icon ─────────────────────────────────────────────────────────
function FingerprintIcon({ className = "", style = {} }) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            className={className}
            style={style}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M8.5 12c0-1.93 1.57-3.5 3.5-3.5s3.5 1.57 3.5 3.5c0 2.9-1.4 5.47-3.5 6.5" />
            <path d="M5.5 12c0-3.59 2.91-6.5 6.5-6.5s6.5 2.91 6.5 6.5c0 4.36-2.1 8.22-5.5 10" />
            <path d="M11 12c0-.55.45-1 1-1s1 .45 1 1c0 1.45-.7 2.74-1.5 3.5" />
        </svg>
    );
}

// ─── Log Type Options ─────────────────────────────────────────────────────────
const logTypeOptions = [
    { value: "time_in",      label: "Time In",      color: "text-success" },
    { value: "break_out_1",  label: "Break Out 1",  color: "text-warning" },
    { value: "break_in_1",   label: "Break In 1",   color: "text-info" },
    { value: "lunch_out",    label: "Lunch Out",    color: "text-warning" },
    { value: "lunch_in",     label: "Lunch In",     color: "text-info" },
    { value: "break_out_2",  label: "Break Out 2",  color: "text-warning" },
    { value: "break_in_2",   label: "Break In 2",   color: "text-info" },
    { value: "time_out",     label: "Time Out",     color: "text-error" },
];

// ─── Status config ─────────────────────────────────────────────────────────────
const STATUS_CONFIG = {
    check_in: {
        bg: "rgba(16,185,129,0.07)",
        border: "#10b981",
        label: "Present",
        dotColor: "#10b981",
        badgeBg: "#d1fae5",
        badgeColor: "#065f46",
        badgeBorder: "#6ee7b7",
        headerBg: "linear-gradient(135deg, rgba(16,185,129,0.12) 0%, rgba(16,185,129,0.04) 100%)",
    },
    check_out: {
        bg: "rgba(59,130,246,0.07)",
        border: "#3b82f6",
        label: "Checked Out",
        dotColor: "#3b82f6",
        badgeBg: "#dbeafe",
        badgeColor: "#1e3a8a",
        badgeBorder: "#93c5fd",
        headerBg: "linear-gradient(135deg, rgba(59,130,246,0.12) 0%, rgba(59,130,246,0.04) 100%)",
    },
    break_out: {
        bg: "rgba(245,158,11,0.07)",
        border: "#f59e0b",
        label: "On Break",
        dotColor: "#f59e0b",
        badgeBg: "#fef3c7",
        badgeColor: "#78350f",
        badgeBorder: "#fcd34d",
        headerBg: "linear-gradient(135deg, rgba(245,158,11,0.12) 0%, rgba(245,158,11,0.04) 100%)",
    },
    break_in: {
        bg: "rgba(20,184,166,0.07)",
        border: "#14b8a6",
        label: "Back fr. Break",
        dotColor: "#14b8a6",
        badgeBg: "#ccfbf1",
        badgeColor: "#134e4a",
        badgeBorder: "#5eead4",
        headerBg: "linear-gradient(135deg, rgba(20,184,166,0.12) 0%, rgba(20,184,166,0.04) 100%)",
    },
    absent: {
        bg: "rgba(239,68,68,0.07)",
        border: "#ef4444",
        label: "Absent",
        dotColor: "#ef4444",
        badgeBg: "#fee2e2",
        badgeColor: "#991b1b",
        badgeBorder: "#fca5a5",
        headerBg: "linear-gradient(135deg, rgba(239,68,68,0.12) 0%, rgba(239,68,68,0.04) 100%)",
    },
    rest_day: {
        bg: "rgba(107,114,128,0.04)",
        border: "#9ca3af",
        label: "Rest Day",
        dotColor: "#9ca3af",
        badgeBg: "#f3f4f6",
        badgeColor: "#374151",
        badgeBorder: "#d1d5db",
        headerBg: "linear-gradient(135deg, rgba(107,114,128,0.08) 0%, rgba(107,114,128,0.02) 100%)",
    },
    no_schedule: {
        bg: "transparent",
        border: "var(--fallback-bc, oklch(var(--bc)/0.2))",
        label: "No Schedule",
        dotColor: "#9ca3af",
        badgeBg: "#f3f4f6",
        badgeColor: "#374151",
        badgeBorder: "#d1d5db",
        headerBg: "transparent",
    },
    holiday_regular: {
        bg: "rgba(168,85,247,0.07)",
        border: "#a855f7",
        label: "Regular Holiday",
        dotColor: "#a855f7",
        badgeBg: "#f3e8ff",
        badgeColor: "#6b21a8",
        badgeBorder: "#d8b4fe",
        headerBg: "linear-gradient(135deg, rgba(168,85,247,0.12) 0%, rgba(168,85,247,0.04) 100%)",
    },
    holiday_special: {
        bg: "rgba(236,72,153,0.07)",
        border: "#ec4899",
        label: "Special Holiday",
        dotColor: "#ec4899",
        badgeBg: "#fce7f3",
        badgeColor: "#831843",
        badgeBorder: "#f9a8d4",
        headerBg: "linear-gradient(135deg, rgba(236,72,153,0.12) 0%, rgba(236,72,153,0.04) 100%)",
    },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────
const todayStr = () => new Date().toLocaleDateString("en-CA");

function isLogFromToday(logTime) {
    if (!logTime) return false;
    const d = new Date(logTime.replace(" ", "T"));
    const now = new Date();
    return (
        d.getFullYear() === now.getFullYear() &&
        d.getMonth() === now.getMonth() &&
        d.getDate() === now.getDate()
    );
}

function fmt12h(t) {
    if (!t) return null;
    try {
        const [h, m] = t.split(":");
        const hour = parseInt(h, 10);
        return `${hour % 12 || 12}:${m} ${hour >= 12 ? "PM" : "AM"}`;
    } catch { return t; }
}

function getEmployeeTimeWindows(emp, shiftCodeMap) {
    const todayShift = getEmployeeTodayShift(emp, shiftCodeMap);
    if (!todayShift?.shiftId) return null;
    const shiftInfo = shiftCodeMap[String(todayShift.shiftId)];
    if (!shiftInfo?.time_windows) return null;
    try {
        let tw = shiftInfo.time_windows;
        if (typeof tw === "string") tw = JSON.parse(tw);
        if (Array.isArray(tw)) return tw;
    } catch { return null; }
    return null;
}

function getEmployeeTodayShift(emp, shiftCodeMap) {
    const today = todayStr();
    const records = emp.scheduler_records ?? [];
    for (const record of records) {
        const payrollStart = record.PAYROLL_DATE_START ?? record.payroll_date_start;
        const schedule = record.SCHEDULE ?? record.schedule;
        if (!payrollStart || !schedule) continue;
        const base = new Date(payrollStart + "T00:00:00");
        for (const [dayNo, shiftId] of Object.entries(schedule)) {
            const d = new Date(base);
            d.setDate(base.getDate() + parseInt(dayNo) - 1);
            const dateStr = d.toLocaleDateString("en-CA");
            if (dateStr === today) {
                const shiftInfo = shiftCodeMap?.[String(shiftId)] ?? null;
                return { shiftInfo, shiftId };
            }
        }
    }
    return null;
}

function getEmployeeShiftType(emp) {
    // Returns: { isShifting: bool, shiftType: int|null }
    const todaySchedule = emp.today_schedule ?? null;

    if (todaySchedule) {
        return {
            isShifting: todaySchedule.is_shifting ?? false,
            shiftType:  todaySchedule.shift_type  ?? null,
        };
    }

    // ── No active schedule — use fallback resolved by backend ─────────────
    // fallback_shift_type: from most recent schedule record, or employee masterlist SHIFT column
    const fallback = emp.fallback_shift_type ?? null;
    if (fallback !== null) {
        return {
            isShifting: parseInt(fallback) === 2,
            shiftType:  parseInt(fallback),
        };
    }

    return { isShifting: false, shiftType: null };
}

function getEmployeeTodayLogs(emp) {
    // Use pre-computed today_logs from backend if available
    if (emp.today_logs) return emp.today_logs;

    // Fallback to legacy fields
    return {
        time_in:     emp.today_checkin_time  ?? null,
        break_out_1: null,
        break_in_1:  null,
        lunch_out:   null,
        lunch_in:    null,
        break_out_2: null,
        break_in_2:  null,
        time_out:    emp.today_checkout_time ?? null,
    };
}

function getEmployeeStatus(emp, shiftCodeMap, todayHoliday = null) {
    // ── 1. Check actual logs FIRST — regardless of schedule ──────────────
    const logs = emp.today_logs ?? null;
    if (logs) {
        if (logs.time_out)    return "check_out";
        if (logs.break_in_2)  return "break_in";
        if (logs.break_out_2) return "break_out";
        if (logs.lunch_in)    return "break_in";
        if (logs.lunch_out)   return "break_out";
        if (logs.break_in_1)  return "break_in";
        if (logs.break_out_1) return "break_out";
        if (logs.time_in)     return "check_in";
    }

    // ── 2. Legacy log fallback ────────────────────────────────────────────
    if (emp.latest_log_type && isLogFromToday(emp.latest_log_time)) {
        return emp.latest_log_type;
    }

    // ── 3. No logs — check schedule for rest day ──────────────────────────
    const todayShift = getEmployeeTodayShift(emp, shiftCodeMap);
    const shiftCode  = todayShift?.shiftInfo?.shiftcode?.toUpperCase() ?? "";
    const isRestDay  = shiftCode.includes("RD");

    if (isRestDay) return "rest_day";

    // ── 4. No logs, not rest day — check holiday ──────────────────────────
    if (todayHoliday) {
        return todayHoliday.is_regular ? "holiday_regular" : "holiday_special";
    }

    // ── 5. Final fallback ─────────────────────────────────────────────────
    if (!todayShift) return "no_schedule";

    return "absent";
}

// ─── Actual Log Times Badge ────────────────────────────────────────────────────
function ActualLogBadge({ emp, status, todayHoliday }) {
    const checkInTime  = emp.today_logs?.time_in  ?? emp.today_checkin_time  ?? null;
    const checkOutTime = emp.today_logs?.time_out ?? emp.today_checkout_time ?? null;

    // ── Holiday with no logs — show holiday name instead ──────────────────
    if (
        todayHoliday &&
        !checkInTime &&
        !checkOutTime &&
        (status === "holiday_regular" || status === "holiday_special")
    ) {
        const isRegular = todayHoliday.is_regular;
        return (
            <div style={{
                display: "flex", alignItems: "center", justifyContent: "center",
                gap: 5, padding: "3px 6px", width: "100%", borderRadius: 6,
                fontSize: 9, fontWeight: 600,
                background: isRegular ? "rgba(168,85,247,0.08)" : "rgba(236,72,153,0.08)",
                border: `1px solid ${isRegular ? "#d8b4fe" : "#f9a8d4"}`,
                color: isRegular ? "#6b21a8" : "#831843",
            }}>
                <span style={{ fontSize: 10 }}>🎉</span>
                <span style={{
                    overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap",
                    maxWidth: "90%",
                }} title={todayHoliday.name}>
                    {todayHoliday.name}
                </span>
            </div>
        );
    }

    if (!checkInTime && !checkOutTime) return null;

    const fmt = (t) => {
        if (!t) return null;
        try {
            const time = t.includes(" ") ? t.split(" ")[1] : t;
            const [h, m] = time.split(":");
            const hour = parseInt(h, 10);
            const ampm = hour >= 12 ? "PM" : "AM";
            return `${hour % 12 || 12}:${m} ${ampm}`;
        } catch { return t; }
    };

    return (
        <div style={{
            display: "flex", alignItems: "center", justifyContent: "center",
            gap: 6, padding: "3px 6px", background: "rgba(99,102,241,0.08)",
            border: "1px solid rgba(99,102,241,0.2)", borderRadius: 6,
            fontSize: 9, fontWeight: 600, color: "#4f46e5", width: "100%",
        }}>
            <ClockCircleOutlined style={{ fontSize: 9 }} />
            {checkInTime && <span title="Time-in">{fmt(checkInTime)}</span>}
            {checkInTime && checkOutTime && <span style={{ opacity: 0.4 }}>→</span>}
            {checkOutTime && <span title="Time-out">{fmt(checkOutTime)}</span>}
            {checkInTime && !checkOutTime && <span style={{ opacity: 0.35 }}>→ ?</span>}
        </div>
    );
}

// ─── Employee Card ─────────────────────────────────────────────────────────────
const EmployeeCard = React.memo(function EmployeeCard({ emp, status, onOpenFP, todayHoliday }) {
    const cfg = STATUS_CONFIG[status] ?? STATUS_CONFIG.no_schedule;
    const initials = emp.EMPNAME?.split(" ").slice(0, 2).map((n) => n[0]).join("").toUpperCase() ?? "?";

    return (
        <div
            style={{
                border: `1.5px solid ${cfg.border}`,
                background: cfg.bg,
                borderRadius: 10,
                overflow: "hidden",
                transition: "all 0.2s ease",
                display: "flex",
                flexDirection: "column",
            }}
            className="hover:shadow-md hover:-translate-y-0.5 transition-all duration-200"
        >
            {/* Status header strip */}
            {cfg.label && (
                <div style={{
                    background: cfg.headerBg,
                    borderBottom: `1px solid ${cfg.border}20`,
                    padding: "3px 8px",
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "space-between",
                }}>
                    <span style={{
                        fontSize: 9, fontWeight: 700, letterSpacing: "0.05em",
                        textTransform: "uppercase", color: cfg.badgeColor,
                    }}>
                        {cfg.label}
                    </span>
                    <span style={{
                        width: 6, height: 6, borderRadius: "50%",
                        background: cfg.dotColor, boxShadow: `0 0 4px ${cfg.dotColor}80`,
                    }} />
                </div>
            )}

            <div style={{
                padding: "10px 10px 8px", display: "flex",
                flexDirection: "column", alignItems: "center", gap: 6, flex: 1,
            }}>
                {/* Avatar */}
                <div style={{ position: "relative", flexShrink: 0 }}>
                    <div style={{
                        width: 44, height: 44, borderRadius: "50%",
                        background: cfg.badgeBg ?? "rgba(99,102,241,0.1)",
                        border: `2px solid ${cfg.border}`,
                        display: "flex", alignItems: "center", justifyContent: "center",
                    }}>
                        <span style={{ fontSize: 13, fontWeight: 700, color: cfg.badgeColor ?? "#6366f1" }}>
                            {initials}
                        </span>
                    </div>
                </div>

                {/* Name & details */}
                <div style={{ width: "100%", textAlign: "center" }}>
                    <p className="text-base-content" style={{
                        fontSize: 11, fontWeight: 600, lineHeight: 1.3, marginBottom: 1,
                        overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap",
                    }} title={emp.EMPNAME}>
                        {emp.EMPNAME}
                    </p>
                    <p className="text-base-content" style={{
                        fontSize: 9, opacity: 0.45,
                        overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap",
                    }} title={emp.JOB_TITLE}>
                        {emp.JOB_TITLE}
                    </p>
                    <p className="text-base-content" style={{ fontSize: 9, opacity: 0.28, fontFamily: "monospace" }}>
                        {emp.EMPLOYID}
                    </p>
                </div>

                {/* Department pill */}
                <span style={{
                    display: "inline-flex", alignItems: "center",
                    padding: "2px 8px", borderRadius: 999, fontSize: 9, fontWeight: 600,
                    background: cfg.badgeBg ?? "#dbeafe",
                    color: cfg.badgeColor ?? "#1e3a8a",
                    border: `1px solid ${cfg.badgeBorder ?? "#93c5fd"}`,
                    maxWidth: "100%", overflow: "hidden",
                    textOverflow: "ellipsis", whiteSpace: "nowrap",
                }} title={emp.DEPARTMENT}>
                    {emp.DEPARTMENT}
                </span>

                {/* Actual check-in / check-out times */}
                <ActualLogBadge emp={emp} status={status} todayHoliday={todayHoliday} />

                {/* Fingerprint button — full width, single action */}
                <button
                    onClick={onOpenFP}
                    className="btn btn-xs btn-outline w-full gap-1 min-h-0 h-7"
                    style={{ fontSize: 10 }}
                    title="Scan Fingerprint"
                >
                    <FingerprintIcon style={{ width: 11, height: 11 }} />
                    Fingerprint
                </button>
            </div>
        </div>
    );
}, (prev, next) =>
    prev.emp.EMPLOYID === next.emp.EMPLOYID &&
    prev.emp.latest_log_type === next.emp.latest_log_type &&
    prev.emp.latest_log_time === next.emp.latest_log_time &&
    prev.emp.today_checkin_time === next.emp.today_checkin_time &&
    prev.emp.today_checkout_time === next.emp.today_checkout_time &&
    JSON.stringify(prev.emp.today_logs) === JSON.stringify(next.emp.today_logs) &&
    prev.status === next.status
);

// ─── Main Page ────────────────────────────────────────────────────────────────
export default function ScanLogs({ initialEmployees = [], shiftCodesMap = {}, todayHoliday = null }) {
    const [allEmployees, setAllEmployees] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const shiftCodeMap = shiftCodesMap ?? {};
    const [search, setSearch] = useState("");
    const [filteredEmployees, setFilteredEmployees] = useState([]);
    const [currentPage, setCurrentPage] = useState(initialEmployees?.current_page || 1);
    const lastPage = initialEmployees?.last_page || 1;

    const goToPage = (page) => {
    router.get(window.location.pathname, { page }, {
        preserveState: true,
        preserveScroll: true,
    });
};

useEffect(() => {
    setCurrentPage(initialEmployees?.current_page || 1);
}, [initialEmployees]);

    // Transform and set employees when initialEmployees changes
    useEffect(() => {
        const employeesData = initialEmployees?.data ?? [];

        const formatted = employeesData.map(emp => ({
            ...emp,
            scheduler_records: emp.scheduler_records || [],
            latest_log_type: null,
            latest_log_time: null,
            today_checkin_time: null,
            today_checkout_time: null,
        }));

        setAllEmployees(formatted);
        setFilteredEmployees(formatted);
        setLoading(false);
    }, [initialEmployees]);


    // Helper function to get actual date from payroll start and day number
    const getDateForScheduleDay = (payrollStart, dayNumber) => {
        if (!payrollStart || !dayNumber) return null;
        try {
            const startDate = new Date(payrollStart + "T00:00:00");
            const targetDate = new Date(startDate);
            targetDate.setDate(startDate.getDate() + parseInt(dayNumber) - 1);
            return targetDate.toLocaleDateString('en-CA'); // Returns YYYY-MM-DD
        } catch (error) {
            console.error('Error calculating date:', error);
            return null;
        }
    };

    // Helper function to format date for display
    const formatDateForDisplay = (dateString) => {
        if (!dateString) return 'N/A';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric' 
            });
        } catch {
            return dateString;
        }
    };

    // Helper function to format time windows for display
    const formatTimeWindows = (timeWindows) => {
        if (!timeWindows) return 'No time windows set';
        try {
            if (typeof timeWindows === 'string') {
                timeWindows = JSON.parse(timeWindows);
            }
            if (Array.isArray(timeWindows) && timeWindows.length > 0) {
                return timeWindows.map(tw => {
                    if (tw.time_in && tw.time_out) {
                        return `${tw.time_in} - ${tw.time_out}`;
                    }
                    return JSON.stringify(tw);
                }).join(', ');
            }
            return JSON.stringify(timeWindows);
        } catch {
            return 'Invalid time windows format';
        }
    };

    // Add this useEffect right after your existing useEffect that processes employees
    useEffect(() => {
    if (!allEmployees.length) return;

    const today = todayStr();

    const parseTimeWindows = (raw) => {
        try {
            let tw = raw;
            if (typeof tw === 'string') tw = JSON.parse(tw);
            if (typeof tw === 'string') tw = JSON.parse(tw);
            if (typeof tw === 'object' && !Array.isArray(tw)) {
                return Object.entries(tw).map(([k, v]) => `${k}:${v}`).join(', ');
            }
            return JSON.stringify(tw);
        } catch {
            return String(raw);
        }
    };

    const getActualDate = (payrollStart, dayNo) => {
        try {
            const base = new Date(payrollStart + 'T00:00:00');
            base.setDate(base.getDate() + parseInt(dayNo) - 1);
            return base.toLocaleDateString('en-CA');
        } catch {
            return '—';
        }
    };

    console.group(`📅 Employee Schedule Summary — ${today}`);

    allEmployees.forEach((emp) => {
        const records = emp.all_schedules ?? [];
        if (!records.length) {
            console.log(`⚪ ${emp.EMPNAME} (${emp.EMPLOYID}) — No schedule records`);
            return;
        }

        console.group(`👤 ${emp.EMPNAME} (${emp.EMPLOYID}) — ${emp.DEPARTMENT}`);

        const currentRecord = records.find((record) => {
            const payrollStart = record.PAYROLL_DATE_START ?? record.payroll_date_start;
            const payrollEnd   = record.PAYROLL_DATE_END   ?? record.payroll_date_end;
            if (!payrollStart || !payrollEnd) return false;
            return today >= payrollStart && today <= payrollEnd;
        });

        if (!currentRecord) {
            console.log(`⚪ No active payroll period found for today (${today})`);
            console.groupEnd();
            return;
        }

        const records_to_show = [currentRecord];

        records_to_show.forEach((record) => {
            const payrollStart = record.PAYROLL_DATE_START ?? record.payroll_date_start;
            const payrollEnd   = record.PAYROLL_DATE_END   ?? record.payroll_date_end;
            const schedule     = record.SCHEDULE           ?? record.schedule;

            console.group(`📆 Active Payroll Period: ${payrollStart} → ${payrollEnd}`);

            if (schedule && typeof schedule === 'object') {
                const shiftType = (record.SHIFT ?? record.shift);
                const shiftTypeLabel = shiftType == 1 ? 'Normal Shift' : 'Shifting';

                const rows = Object.entries(schedule).map(([dayNo, shiftId]) => {
                    const actualDate = getActualDate(payrollStart, dayNo);
                    const shiftData  = shiftCodeMap[shiftId] ?? null;
                    const shiftCode  = shiftData?.shiftcode ?? '—';
                    const isRestDay  = shiftCode.toUpperCase().includes('RD');
                    const isToday    = actualDate === today;

                    return {
                        'Day #':        parseInt(dayNo),
                        'Date':         actualDate + (isToday ? ' ◀ TODAY' : ''),
                        'Shift ID':     shiftId,
                        'Shift Code':   shiftCode,
                        'Shift Type':   shiftTypeLabel,
                        'Time Windows': shiftData?.time_windows
                            ? parseTimeWindows(shiftData.time_windows)
                            : '—',
                        'Status':       isRestDay ? '🔴 Rest Day' : '✅ Working',
                    };
                }).sort((a, b) => a['Day #'] - b['Day #']);

                console.table(rows);
            } else {
                console.log('No schedule data found for this period.');
            }

            console.groupEnd();
        });

        console.groupEnd();
    });

    const withSchedule    = allEmployees.filter(e => (e.all_schedules ?? []).length > 0).length;
    const withoutSchedule = allEmployees.length - withSchedule;
    console.log(`📊 Total: ${allEmployees.length} | With schedule: ${withSchedule} | No schedule: ${withoutSchedule}`);
    console.groupEnd();

}, [allEmployees]);

    // ── Fingerprint Modal ─────────────────────────────────────────────────────
    const [isFPModalOpen, setIsFPModalOpen] = useState(false);
    const [fpLogType, setFpLogType] = useState("time_in");
    const [fpState, setFpState] = useState("idle");
    const [fpEmployee, setFpEmployee] = useState(null);
    const [fpPreselected, setFpPreselected] = useState(null);
    const [fpError, setFpError] = useState(null);

    const fpLogTypeRef = useRef(fpLogType);
    useEffect(() => { fpLogTypeRef.current = fpLogType; }, [fpLogType]);

    const fpPreselectedRef = useRef(fpPreselected);
    useEffect(() => { fpPreselectedRef.current = fpPreselected; }, [fpPreselected]);

    const allEmployeesRef = useRef(allEmployees ?? []);
    useEffect(() => { allEmployeesRef.current = allEmployees; }, [allEmployees]);

    // ── Fingerprint: scan → match → save → refresh ────────────────────────────
    const handleFPScan = useCallback(async () => {
    setFpState("scanning");
    setFpEmployee(null);
    setFpError(null);

    try {
        // Step 1: Capture from local SecuGen device
        console.log("[ScanLogs] Step 1: Capturing fingerprint…");
        const captured = await captureFingerprint();
        console.log("[ScanLogs] Capture OK — quality:", captured.quality);

        // Step 2: Send to server for 1:N matching
        console.log("[ScanLogs] Step 2: Server-side matching…");
        const matchRes = await axios.post(route("fingerprint.match"), {
            template: captured.template,
            quality:  captured.quality,
        });

        if (!matchRes.data.success) {
            setFpState("notfound");
            setFpError(matchRes.data.message ?? "No match found.");
            return;
        }

        const { employid, employee, score } = matchRes.data;
        console.log("[ScanLogs] Match — employid:", employid, "score:", score);

        // Step 3: Verify preselected employee if applicable
        const pre = fpPreselectedRef.current;
        if (pre && String(pre.EMPLOYID) !== String(employid)) {
            const matchedName = employee?.EMPNAME ?? employid;
            setFpState("mismatch");
            setFpError(`Fingerprint belongs to ${matchedName}, not ${pre.EMPNAME}.`);
            return;
        }

        // Step 4: Find employee in loaded list (for card update)
        const emp = allEmployeesRef.current.find(
            (e) => String(e.EMPLOYID) === String(employid)
        ) ?? { ...employee, EMPNAME: employee?.EMPNAME, EMPLOYID: employid };

        setFpEmployee(emp);
        setFpState("found");

    } catch (e) {
        console.error("[ScanLogs] handleFPScan error:", e.message);
        setFpError(e.response?.data?.message ?? e.message ?? "Scan failed");
        setFpState("error");
    }
}, []);

    // Auto-save on match
    useEffect(() => {
        if (fpState !== "found" || !fpEmployee) return;
        setFpState("saving");

        const logType  = fpLogTypeRef.current;
        const employid = String(fpEmployee.EMPLOYID);

        console.log("[ScanLogs] Saving log — employid:", employid, "log_type:", logType);

        axios.post(route("attendance-log.store"), {
            employid,
            log_type: logType,
        })
        .then((res) => {
            console.log("[ScanLogs] Save response:", res.data);

            if (!res.data.success) throw new Error(res.data.message ?? "Save failed");

            const loggedAt = res.data.logged_at;
            const savedLogType = res.data.log_type;

            const logKeyMap = {
                time_in:     "time_in",
                time_out:    "time_out",
                break_out_1: "break_out_1",
                break_in_1:  "break_in_1",
                break_out_2: "break_out_2",
                break_in_2:  "break_in_2",
                lunch_out:   "lunch_out",
                lunch_in:    "lunch_in",
            };

            const todayKey = logKeyMap[savedLogType];
            const timePart = loggedAt?.split(" ")[1]?.slice(0, 5) ?? null;

            console.log("[ScanLogs] Updating card — key:", todayKey, "time:", timePart);

            // WITH THIS:
setAllEmployees((prev) =>
    prev.map((e) => {
        if (String(e.EMPLOYID) !== employid) return e;
        const updatedLogs = { ...(e.today_logs ?? {}) };
        if (todayKey) {
            if (todayKey === "time_out" || !updatedLogs[todayKey]) {
                updatedLogs[todayKey] = timePart;
            }
        }
        return { ...e, today_logs: updatedLogs };
    })
);

const updateLogs = (emp) => {
    if (!emp) return emp;
    const updatedLogs = { ...(emp.today_logs ?? {}) };
    if (todayKey) {
        if (todayKey === "time_out" || !updatedLogs[todayKey]) {
            updatedLogs[todayKey] = timePart;
        }
    }
    return { ...emp, today_logs: updatedLogs };
};

setFpEmployee((prev) => updateLogs(prev));

// Also sync fpPreselected — this is the one displayEmp falls back to
// after fpEmployee is cleared on reset, causing stale today_logs
setFpPreselected((prev) => {
    if (!prev || String(prev.EMPLOYID) !== employid) return prev;
    return updateLogs(prev);
});

            setFpState("saved");
        })
        .catch((e) => {
            const msg = e.response?.data?.message ?? e.message ?? "Save failed";
            console.error("[ScanLogs] Save FAILED:", msg, e.response?.data);
            setFpError(msg);
            setFpState("error");
        });
    }, [fpState, fpEmployee]);

    // Auto-reset after saved
    useEffect(() => {
        if (fpState !== "saved") return;
        const t = setTimeout(() => {
            setFpEmployee(null);
            setFpError(null);
            setFpState("idle");
        }, 2000);
        return () => clearTimeout(t);
    }, [fpState]);

    // Auto-start scan when modal opens
    useEffect(() => {
        if (!isFPModalOpen || fpState !== "idle") return;
        const t = setTimeout(() => handleFPScan(), 400);
        return () => clearTimeout(t);
    }, [isFPModalOpen, fpState, handleFPScan]);

    const openFPModal = () => {
        setIsFPModalOpen(true);
        setFpPreselected(null);
        setFpState("idle");
        setFpEmployee(null);
        setFpError(null);
        setFpLogType("time_in");
    };
    
    const openFPModalForEmployee = (emp) => {
        setIsFPModalOpen(true);
        setFpPreselected(emp);
        setFpState("idle");
        setFpEmployee(null);
        setFpError(null);
        setFpLogType("time_in");
    };
    
    const closeFPModal = () => {
        setIsFPModalOpen(false);
        setFpPreselected(null);
        setFpState("idle");
        setFpEmployee(null);
        setFpError(null);
    };

    // ── Search filter ─────────────────────────────────────────────────────────
    const searchTimeout = useRef(null);

    useEffect(() => {
        clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            if (!search.trim()) { 
                setFilteredEmployees(allEmployees); 
                return; 
            }
            const s = search.toLowerCase().trim();
            setFilteredEmployees(
                allEmployees.filter(
                    (e) =>
                        e.EMPNAME?.toLowerCase().includes(s) ||
                        e.EMPLOYID?.toLowerCase().includes(s) ||
                        e.DEPARTMENT?.toLowerCase().includes(s) ||
                        e.JOB_TITLE?.toLowerCase().includes(s),
                ),
            );
        }, 150);
        return () => clearTimeout(searchTimeout.current);
    }, [search, allEmployees]);

    // ── Status summary counts ─────────────────────────────────────────────────
const { statusCounts, employeeStatuses } = React.useMemo(() => {
        const counts = {
            check_in: 0, check_out: 0, break_out: 0, break_in: 0,
            absent: 0, rest_day: 0, no_schedule: 0,
            holiday_regular: 0, holiday_special: 0,
        };
        const statuses = {};
        allEmployees.forEach((emp) => {
            const s = getEmployeeStatus(emp, shiftCodeMap, todayHoliday);
            statuses[emp.EMPLOYID] = s;
            counts[s] = (counts[s] ?? 0) + 1;
        });
        return { statusCounts: counts, employeeStatuses: statuses };
    }, [allEmployees, shiftCodeMap, todayHoliday]);

    // Wrap the main content in AuthenticatedLayout
    return (
        <AuthenticatedLayout>
            <div className="p-4 h-full flex flex-col">
                <div className="border border-base-300 rounded-lg bg-base-100 shadow-sm flex flex-col flex-1 overflow-hidden">

                    {/* ── Header ─────────────────────────────────────────────── */}
                    <div className="flex-shrink-0 px-6 py-4 border-b border-base-300">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                            <h1 className="text-2xl font-bold text-base-content flex items-center gap-2">
                                <ScanOutlined className="text-primary" />
                                Scan Logs
                            </h1>
                            <div className="flex gap-2 flex-wrap">
                                <button
                                    className="btn btn-primary btn-sm gap-2"
                                    onClick={openFPModal}
                                >
                                    <FingerprintIcon style={{ width: 16, height: 16 }} />
                                    Scan Fingerprint
                                </button>
                            </div>
                        </div>

                        {/* Status summary pills */}
                        <div className="flex flex-wrap gap-1.5 mb-3">
                            {/* Holiday banner */}
                        {todayHoliday && (
                            <div style={{
                                width: "100%",
                                display: "flex",
                                alignItems: "center",
                                gap: 8,
                                padding: "6px 12px",
                                borderRadius: 8,
                                marginBottom: 4,
                                background: todayHoliday.is_regular
                                    ? "rgba(168,85,247,0.08)"
                                    : "rgba(236,72,153,0.08)",
                                border: `1px solid ${todayHoliday.is_regular ? "#d8b4fe" : "#f9a8d4"}`,
                                fontSize: 11,
                                fontWeight: 600,
                                color: todayHoliday.is_regular ? "#6b21a8" : "#831843",
                            }}>
                                <span>🎉</span>
                                <span>{todayHoliday.name}</span>
                                <span style={{
                                    marginLeft: "auto",
                                    fontSize: 9,
                                    fontWeight: 700,
                                    textTransform: "uppercase",
                                    letterSpacing: "0.05em",
                                    opacity: 0.6,
                                }}>
                                    {todayHoliday.type} Holiday
                                </span>
                            </div>
                        )}

                        {[
                            { key: "check_in",       label: "Present" },
                            { key: "check_out",      label: "Checked Out" },
                            { key: "break_out",      label: "On Break" },
                            { key: "break_in",       label: "Back fr. Break" },
                            { key: "absent",         label: "Absent" },
                            { key: "rest_day",       label: "Rest Day" },
                            { key: "no_schedule",    label: "No Schedule" },
                            { key: "holiday_regular", label: "Regular Holiday" },
                            { key: "holiday_special", label: "Special Holiday" },
                        ].map(({ key, label }) => {
                                const cfg = STATUS_CONFIG[key];
                                const count = statusCounts[key] ?? 0;
                                if (count === 0) return null;
                                return (
                                    <span key={key} style={{
                                        display: "inline-flex", alignItems: "center", gap: 4,
                                        padding: "3px 8px", borderRadius: 999, fontSize: 10, fontWeight: 600,
                                        background: cfg.badgeBg ?? "#f3f4f6",
                                        color: cfg.badgeColor ?? "#374151",
                                        border: `1px solid ${cfg.badgeBorder ?? "#d1d5db"}`,
                                    }}>
                                        <span style={{
                                            width: 6, height: 6, borderRadius: "50%",
                                            background: cfg.dotColor ?? "#9ca3af", flexShrink: 0,
                                        }} />
                                        {label}: {count}
                                    </span>
                                );
                            })}
                        </div>

                        {/* Search bar */}
                        <div className="relative flex-1 w-full max-w-md">
                            <div className="flex items-center gap-2 border border-base-300 rounded-lg px-3 py-2 bg-base-100">
                                <SearchOutlined className="text-base-content opacity-50 text-sm" />
                                <input
                                    type="text"
                                    className="flex-1 bg-transparent text-base-content text-sm focus:outline-none"
                                    placeholder="Search by name, ID, department..."
                                    value={search}
                                    onChange={(e) => {
                                        const value = e.target.value;
                                        setSearch(value);

                                        router.get(window.location.pathname, {
                                            search: value,
                                            page: 1,
                                        }, {
                                            preserveState: true,
                                            preserveScroll: true,
                                        });
                                    }}
                                    data-scanner="ignore"
                                />
                                {search && (
                                    <button onClick={() => setSearch("")} className="btn btn-ghost btn-xs px-1" type="button">
                                        Clear
                                    </button>
                                )}
                            </div>
                            {search && (
                                <div className="absolute top-full mt-1 text-xs text-base-content opacity-50">
                                    {filteredEmployees.length} result{filteredEmployees.length !== 1 ? "s" : ""} for "{search}"
                                </div>
                            )}
                        </div>
                    </div>

                    {/* ── Cards Grid ─────────────────────────────────────────── */}
                    <div className="flex-1 min-h-0 overflow-y-auto px-6 py-5">
                        {loading ? (
                            <div className="flex flex-col items-center justify-center py-20">
                                <div className="loading loading-spinner loading-lg text-primary"></div>
                                <p className="mt-4 text-base-content opacity-60">Loading employees...</p>
                            </div>
                        ) : error ? (
                            <div className="flex flex-col items-center justify-center py-20 text-error gap-3">
                                <CloseCircleFilled style={{ fontSize: 48 }} />
                                <p className="text-sm font-medium">Error loading employees</p>
                                <p className="text-xs opacity-60">{error}</p>
                                <button onClick={() => window.location.reload()} className="btn btn-xs btn-primary mt-2">
                                    Retry
                                </button>
                            </div>
                        ) : (initialEmployees?.data ?? []).length > 0 ? (
                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-3">
                                {(initialEmployees?.data ?? []).map((emp) => (
                                    <EmployeeCard
                                        key={emp.EMPID ?? emp.EMPLOYID}
                                        emp={emp}
                                        status={employeeStatuses[emp.EMPLOYID]}
                                        onOpenFP={() => openFPModalForEmployee(emp)}
                                        todayHoliday={todayHoliday}
                                    />
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-20 text-base-content opacity-30 gap-3">
                                <TeamOutlined style={{ fontSize: 48 }} />
                                <p className="text-sm font-medium">
                                    No employees found
                                </p>
                                {search && (
                                    <button onClick={() => setSearch("")} className="btn btn-xs btn-ghost opacity-60">
                                        Clear search
                                    </button>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Count footer */}
                    {filteredEmployees.length > 0 && (
                        <div className="flex-shrink-0 border-t border-base-300 px-4 py-2 bg-base-100">
                            <p className="text-xs opacity-40 text-center">
                                {initialEmployees?.total
                                    ? `${initialEmployees.total} employees`
                                    : "0 employees"}
                            </p>
                        </div>
                    )}

                    {lastPage > 1 && (
                        <div className="flex justify-center items-center gap-2 py-3">
                            <button
                                className="btn btn-xs"
                                disabled={currentPage === 1}
                                onClick={() => goToPage(currentPage - 1)}
                            >
                                Prev
                            </button>

                            <span className="text-xs opacity-60">
                                Page {currentPage} of {lastPage}
                            </span>

                            <button
                                className="btn btn-xs"
                                disabled={currentPage === lastPage}
                                onClick={() => goToPage(currentPage + 1)}
                            >
                                Next
                            </button>
                        </div>
                    )}
                </div>

                {/* Fingerprint Modal */}
                {isFPModalOpen && (
                    <div className="modal modal-open">
                        <div className="modal-box max-w-2xl">
                            <div className="flex items-center justify-between mb-6">
                                <h3 className="font-bold text-2xl flex items-center gap-2">
                                    <FingerprintIcon className="text-primary" style={{ width: 24, height: 24 }} />
                                    Scan Fingerprint
                                </h3>
                                <button className="btn btn-sm btn-circle btn-ghost" onClick={closeFPModal}>
                                    <CloseOutlined />
                                </button>
                            </div>

{(() => {
    const displayEmp  = fpEmployee ?? fpPreselected;
    const timeWindows = displayEmp ? getEmployeeTimeWindows(displayEmp, shiftCodeMap) : null;
    const todayLogs   = displayEmp ? getEmployeeTodayLogs(displayEmp) : null;
    const { isShifting } = displayEmp ? getEmployeeShiftType(displayEmp) : { isShifting: false };
    return (
        <>
            <LogTypeSelector
                value={fpLogType}
                onChange={(v) => setFpLogType(v)}
                timeWindows={timeWindows}
                todayLogs={todayLogs}
                isShifting={isShifting}
            />
            <LogTimeSummary
                fpLogType={fpLogType}
                timeWindows={timeWindows}
                todayLogs={todayLogs}
                selectedIndex={logTypeOptions.findIndex(o => o.value === fpLogType)}
                isShifting={isShifting}
            />
        </>
    );
})()}

                            <div className="flex gap-4 items-stretch">
                                {/* Left — identified / preselected employee card */}
                                <div className="flex-1 min-h-0">
                                    <div className="card bg-base-100 shadow-lg border border-base-300 h-full">
                                        <div className="card-body p-6">
                                            {fpEmployee || fpPreselected ? (() => {
                                                const displayEmp = fpEmployee ?? fpPreselected;
                                                const isPreview = !fpEmployee && !!fpPreselected;
                                                return (
                                                    <>
                                                        <div className="flex items-center justify-between mb-4">
                                                            <div className="flex items-center gap-3">
                                                                <div className={`w-12 h-12 rounded-full border flex items-center justify-center ${isPreview ? "bg-base-200 border-base-300" : "bg-primary/20 border-primary"}`}>
                                                                    <UserOutlined className={`text-xl ${isPreview ? "opacity-40" : "text-primary"}`} />
                                                                </div>
                                                                <div>
                                                                    <h2 className={`text-base font-bold ${isPreview ? "text-base-content opacity-50" : "text-base-content"}`}>
                                                                        {displayEmp.EMPNAME}
                                                                    </h2>
                                                                    <p className="text-xs opacity-50 font-mono">{displayEmp.EMPLOYID}</p>
                                                                </div>
                                                            </div>
                                                            <div className={`w-8 h-8 rounded-lg flex items-center justify-center ${isPreview ? "bg-base-200" : "bg-primary/10"}`}>
                                                                <IdcardOutlined className={isPreview ? "opacity-30" : "text-primary"} />
                                                            </div>
                                                        </div>
                                                        <div className="bg-base-200 rounded-lg px-3 py-2 mb-4">
                                                            <p className={`text-sm font-medium text-center ${isPreview ? "opacity-40" : "text-base-content"}`}>
                                                                {displayEmp.JOB_TITLE}
                                                            </p>
                                                        </div>
                                                        <div className="space-y-2">
                                                            <div className="flex items-center justify-between py-2 border-b border-base-300">
                                                                <span className="text-xs opacity-70 font-medium">DEPT</span>
                                                                <span className={`text-sm font-medium text-right ${isPreview ? "opacity-40" : "text-base-content"}`}>
                                                                    {displayEmp.DEPARTMENT}
                                                                </span>
                                                            </div>
                                                            <div className="flex items-center justify-between py-2 border-b border-base-300">
                                                                <span className="text-xs opacity-70 font-medium">LINE</span>
                                                                <span className={`text-sm font-medium text-right ${isPreview ? "opacity-40" : "text-base-content"}`}>
                                                                    {displayEmp.PRODLINE || <span className="opacity-50">N/A</span>}
                                                                </span>
                                                            </div>
                                                        </div>
                                                        {isPreview && (
                                                            <p className="text-[10px] text-base-content opacity-30 text-center mt-3">
                                                                Place finger on device to Log Attendance
                                                            </p>
                                                        )}
                                                    </>
                                                );
                                            })() : (
                                                /* Skeleton */
                                                <>
                                                    <div className="flex items-center justify-between mb-4">
                                                        <div className="flex items-center gap-3">
                                                            <div className="w-12 h-12 rounded-full bg-base-300 border border-base-300 flex items-center justify-center">
                                                                <UserOutlined className="text-xl opacity-30" />
                                                            </div>
                                                            <div>
                                                                <div className="h-4 w-32 bg-base-300 rounded mb-2" />
                                                                <div className="h-3 w-20 bg-base-300 rounded" />
                                                            </div>
                                                        </div>
                                                        <div className="w-8 h-8 rounded-lg bg-base-300 flex items-center justify-center">
                                                            <IdcardOutlined className="opacity-30" />
                                                        </div>
                                                    </div>
                                                    <div className="bg-base-200 rounded-lg px-3 py-2 mb-4">
                                                        <div className="h-4 w-24 bg-base-300 rounded mx-auto" />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <div className="flex items-center justify-between py-2 border-b border-base-300">
                                                            <span className="text-xs opacity-50 font-medium">DEPT</span>
                                                            <div className="h-3 w-28 bg-base-300 rounded" />
                                                        </div>
                                                        <div className="flex items-center justify-between py-2 border-b border-base-300">
                                                            <span className="text-xs opacity-50 font-medium">LINE</span>
                                                            <div className="h-3 w-24 bg-base-300 rounded" />
                                                        </div>
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Right — fingerprint scanner */}
                                <div className="flex-1 card bg-base-100 shadow-lg border border-base-300">
                                    <div className="card-body p-6 flex flex-col items-center justify-center">
                                        <div className="relative">
                                            <div className="w-48 h-48 bg-base-200 rounded-lg flex items-center justify-center border-4 border-base-300 relative overflow-hidden">
                                                {fpState === "scanning" && (
                                                    <>
                                                        <FingerprintIcon className="text-primary opacity-20" style={{ width: 72, height: 72 }} />
                                                        <div className="absolute left-0 right-0 h-1 bg-gradient-to-r from-transparent via-blue-500 to-transparent"
                                                            style={{ animation: "scan 2s ease-in-out infinite", boxShadow: "0 0 10px rgba(59,130,246,0.8)" }} />
                                                    </>
                                                )}
                                                {fpState === "saving" && (
                                                    <>
                                                        <FingerprintIcon className="text-success opacity-30" style={{ width: 72, height: 72 }} />
                                                        <div className="absolute left-0 right-0 h-1 bg-gradient-to-r from-transparent via-green-500 to-transparent"
                                                            style={{ animation: "scan 1s ease-in-out infinite", boxShadow: "0 0 10px rgba(16,185,129,0.8)" }} />
                                                    </>
                                                )}
                                                {fpState === "saved" && (
                                                    <CheckCircleFilled style={{ fontSize: 56, color: "#10b981" }} />
                                                )}
                                                {(fpState === "error" || fpState === "notfound" || fpState === "mismatch") && (
                                                    <CloseCircleFilled style={{ fontSize: 56, color: "#ef4444" }} />
                                                )}
                                                {fpState === "idle" && (
                                                    <FingerprintIcon className="text-base-content opacity-20" style={{ width: 72, height: 72 }} />
                                                )}
                                            </div>
                                            <ScanCorners />
                                        </div>

                                        {/* Status text */}
                                        <div className="mt-6 text-center">
                                            {fpState === "idle" && (
                                                <>
                                                    <p className="font-medium opacity-50">Initialising…</p>
                                                    <p className="text-sm opacity-40 mt-1">Please wait</p>
                                                </>
                                            )}
                                            {fpState === "scanning" && (
                                                <>
                                                    <p className="font-medium">Scanning…</p>
                                                    <p className="text-sm opacity-60">
                                                        {fpPreselected ? `Verifying ${fpPreselected.EMPNAME}` : "Place finger on device"}
                                                    </p>
                                                </>
                                            )}
                                            {fpState === "saving" && (
                                                <>
                                                    <p className="text-info font-medium text-lg">💾 Saving…</p>
                                                    <p className="text-sm opacity-60">Recording scan log</p>
                                                </>
                                            )}
                                            {fpState === "saved" && (
                                                <>
                                                    <p className="text-success font-medium text-lg">✓ Scan Successful!</p>
                                                    <p className="text-sm opacity-60">Logged for {fpEmployee?.EMPNAME}</p>
                                                </>
                                            )}
                                            {fpState === "notfound" && (
                                                <>
                                                    <p className="text-error font-medium">Employee Not Found</p>
                                                    <p className="text-sm opacity-60">No fingerprint match</p>
                                                </>
                                            )}
                                            {fpState === "mismatch" && (
                                                <>
                                                    <p className="text-error font-medium">Wrong Person</p>
                                                    <p className="text-sm text-error opacity-70 mt-1 leading-snug">{fpError}</p>
                                                </>
                                            )}
                                            {fpState === "error" && (
                                                <>
                                                    <p className="text-error font-medium">Scan Failed</p>
                                                    <p className="text-sm text-error opacity-70 mt-1 leading-snug">{fpError}</p>
                                                </>
                                            )}
                                        </div>

                                        {/* Manual retry on failure */}
                                        {(fpState === "error" || fpState === "notfound" || fpState === "mismatch") && (
                                            <button onClick={handleFPScan} className="btn btn-primary btn-sm gap-1 mt-4">
                                                <FingerprintIcon style={{ width: 14, height: 14 }} />
                                                Retry Scan
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <ScanAnimation />
                        </div>
                        <div className="modal-backdrop" onClick={closeFPModal} />
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

// ─── Shared sub-components ────────────────────────────────────────────────────
// Each frontend log type maps directly to its key in today_logs
const LOG_TYPE_TO_ACTUAL = {
    time_in:     "time_in",
    break_out_1: "break_out_1",
    break_in_1:  "break_in_1",
    lunch_out:   "lunch_out",
    lunch_in:    "lunch_in",
    break_out_2: "break_out_2",
    break_in_2:  "break_in_2",
    time_out:    "time_out",
};

// Slots that are not applicable for shifting employees
const SHIFTING_DISABLED_SLOTS = ["break_out_1", "break_in_1"];

function LogTypeSelector({ value, onChange, timeWindows = null, todayLogs = null, isShifting = false }) {
    return (
        <div className="mb-6">
            <label className="block text-sm font-medium text-base-content mb-3">
                Select Log Type:{" "}
                <span className="font-bold text-primary">
                    {logTypeOptions.find((o) => o.value === value)?.label ?? value}
                </span>
                {isShifting && (
                    <span className="ml-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-warning/10 text-warning border border-warning/30 align-middle">
                        Shifting Schedule
                    </span>
                )}
            </label>
            <div className="grid grid-cols-4 gap-2">
                {logTypeOptions.map((opt, index) => {
                    const rawTime     = timeWindows?.[index] ?? null;
                    const displayTime = fmt12h(rawTime);
                    const isSelected  = value === opt.value;
                    const isDisabled  = isShifting && SHIFTING_DISABLED_SLOTS.includes(opt.value);

                    const actualKey  = LOG_TYPE_TO_ACTUAL[opt.value];
                    const actualRaw  = todayLogs?.[actualKey] ?? null;
                    const actualTime = actualRaw ? fmt12h(
                        actualRaw.includes(" ") ? actualRaw.split(" ")[1] : actualRaw
                    ) : null;

                    return (
                        <button
                            key={opt.value}
                            type="button"
                            onClick={() => !isDisabled && onChange(opt.value)}
                            disabled={isDisabled}
                            title={isDisabled ? "Not applicable for shifting schedule" : undefined}
                            className={`btn btn-sm flex flex-col items-center justify-center gap-0.5 h-auto py-2 ${
                                isDisabled
                                    ? "btn-disabled opacity-30 cursor-not-allowed"
                                    : isSelected
                                        ? "btn-primary"
                                        : "btn-outline"
                            }`}
                        >
                            <span className={`text-xs font-semibold leading-tight ${
                                isDisabled
                                    ? "text-base-content opacity-40 line-through"
                                    : isSelected
                                        ? "text-white"
                                        : opt.color
                            }`}>
                                {opt.label}
                            </span>

                            {isDisabled ? (
                                <span className="text-[9px] opacity-30 leading-tight">N/A</span>
                            ) : (
                                <>
                                    {/* Expected time from shift time window */}
                                    {displayTime ? (
                                        <span className={`text-[10px] font-mono leading-tight ${
                                            isSelected ? "text-white/70" : "text-base-content opacity-40"
                                        }`}>
                                            {displayTime}
                                        </span>
                                    ) : (
                                        <span className="text-[10px] opacity-20 leading-tight">—</span>
                                    )}

                                    {/* Actual logged time badge */}
                                    {actualTime && (
                                        <span className={`text-[9px] font-mono px-1 rounded leading-tight mt-0.5 ${
                                            isSelected
                                                ? "bg-white/20 text-white"
                                                : "bg-success/10 text-success border border-success/30"
                                        }`}>
                                            ✓ {actualTime}
                                        </span>
                                    )}
                                </>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

// ─── Log Time Summary Bar ─────────────────────────────────────────────────────
function LogTimeSummary({ fpLogType, timeWindows, todayLogs, selectedIndex, isShifting = false }) {
    const isDisabledSlot = isShifting && SHIFTING_DISABLED_SLOTS.includes(fpLogType);

    if (isDisabledSlot) {
        return (
            <div className="mb-4 rounded-lg border border-warning/30 bg-warning/5 px-4 py-3 flex items-center gap-3">
                <span className="text-warning text-lg">⚠️</span>
                <div>
                    <p className="text-sm font-semibold text-warning">
                        {logTypeOptions.find(o => o.value === fpLogType)?.label} is not applicable
                    </p>
                    <p className="text-xs opacity-60 mt-0.5">
                        This log type is disabled for employees on a shifting schedule.
                    </p>
                </div>
            </div>
        );
    }
    const expectedRaw = timeWindows?.[selectedIndex] ?? null;
    const expectedTime = fmt12h(expectedRaw);

    const actualKey = LOG_TYPE_TO_ACTUAL[fpLogType];
    const actualRaw = todayLogs?.[actualKey] ?? null;
    const actualTime = actualRaw
        ? fmt12h(actualRaw.includes(" ") ? actualRaw.split(" ")[1] : actualRaw)
        : null;

    const selectedLabel = logTypeOptions.find(o => o.value === fpLogType)?.label ?? fpLogType;

    return (
        <div className="mb-4 rounded-lg border border-base-300 bg-base-200/50 px-4 py-3 flex items-center gap-6 flex-wrap">
            {/* Selected log type */}
            <div className="flex flex-col gap-0.5 min-w-[80px]">
                <span className="text-[10px] font-semibold opacity-40 uppercase tracking-wide">Log Type</span>
                <span className="text-sm font-bold text-primary">{selectedLabel}</span>
            </div>

            <div className="w-px h-8 bg-base-300 hidden sm:block" />

            {/* Expected time */}
            <div className="flex flex-col gap-0.5 min-w-[80px]">
                <span className="text-[10px] font-semibold opacity-40 uppercase tracking-wide">Expected Time</span>
                {expectedTime ? (
                    <span className="text-sm font-mono font-semibold text-base-content">
                        {expectedTime}
                    </span>
                ) : (
                    <span className="text-sm opacity-25">—</span>
                )}
            </div>

            <div className="w-px h-8 bg-base-300 hidden sm:block" />

            {/* Actual logged time */}
            <div className="flex flex-col gap-0.5 min-w-[80px]">
                <span className="text-[10px] font-semibold opacity-40 uppercase tracking-wide">Actual Log Time</span>
                {actualTime ? (
                    <span className="text-sm font-mono font-semibold text-success flex items-center gap-1">
                        <CheckCircleFilled style={{ fontSize: 11 }} />
                        {actualTime}
                    </span>
                ) : (
                    <span className="text-sm opacity-25 flex items-center gap-1">
                        <ClockCircleOutlined style={{ fontSize: 11 }} />
                        Not yet logged
                    </span>
                )}
            </div>

            {/* Variance indicator */}
            {expectedTime && actualTime && (() => {
                const toMins = (t) => {
                    const match = t.match(/(\d+):(\d+)\s*(AM|PM)/i);
                    if (!match) return null;
                    let h = parseInt(match[1]);
                    const m = parseInt(match[2]);
                    const ampm = match[3].toUpperCase();
                    if (ampm === "PM" && h !== 12) h += 12;
                    if (ampm === "AM" && h === 12) h = 0;
                    return h * 60 + m;
                };
                const expMins = toMins(expectedTime);
                const actMins = toMins(actualTime);
                if (expMins === null || actMins === null) return null;
                const diff = actMins - expMins;
                const absDiff = Math.abs(diff);
                const hrs = Math.floor(absDiff / 60);
                const mins = absDiff % 60;
                const label = hrs > 0 ? `${hrs}h ${mins}m` : `${mins}m`;
                const isLate = diff > 0;
                const isEarly = diff < 0;
                if (diff === 0) return (
                    <span className="ml-auto text-[10px] font-semibold text-success bg-success/10 border border-success/30 px-2 py-1 rounded-full">
                        On time
                    </span>
                );
                return (
                    <span className={`ml-auto text-[10px] font-semibold px-2 py-1 rounded-full border ${
                        isLate
                            ? "text-error bg-error/10 border-error/30"
                            : "text-info bg-info/10 border-info/30"
                    }`}>
                        {isLate ? `${label} late` : `${label} early`}
                    </span>
                );
            })()}
        </div>
    );
}

function ScanCorners() {
    return (
        <>
            <div className="absolute top-0 left-0 w-8 h-8 border-l-4 border-t-4 border-primary rounded-tl-lg" />
            <div className="absolute top-0 right-0 w-8 h-8 border-r-4 border-t-4 border-primary rounded-tr-lg" />
            <div className="absolute bottom-0 left-0 w-8 h-8 border-l-4 border-b-4 border-primary rounded-bl-lg" />
            <div className="absolute bottom-0 right-0 w-8 h-8 border-r-4 border-b-4 border-primary rounded-br-lg" />
        </>
    );
}

function ScanAnimation() {
    return (
        <style>{`
            @keyframes scan {
                0%   { top: 0;    opacity: 0; }
                50%  { opacity: 1; }
                100% { top: 100%; opacity: 0; }
            }
        `}</style>
    );
}