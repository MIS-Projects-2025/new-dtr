import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { useContext, useState, useMemo, useEffect, useCallback, useRef } from "react";
import { ThemeContext } from "@/Components/ThemeContext";
import {
    ConfigProvider, theme as antTheme, Button, Card, Typography,
    Row, Col, Statistic, Tooltip, Select, Avatar, Tag, Input, Empty, Spin,
} from "antd";
import {
    TableOutlined, DashboardOutlined, LoginOutlined, LogoutOutlined,
    CoffeeOutlined, MenuOutlined, ClockCircleOutlined, ScheduleOutlined,
    CheckCircleOutlined, CloseCircleOutlined, FieldTimeOutlined,
    CalendarOutlined, FilterOutlined, TeamOutlined, UserOutlined,
    SearchOutlined, ApartmentOutlined, LoadingOutlined,
} from "@ant-design/icons";
import { router } from "@inertiajs/react";
import dayjs from "dayjs";
import weekOfYear from "dayjs/plugin/weekOfYear";
import isSameOrBefore from "dayjs/plugin/isSameOrBefore";
import isSameOrAfter from "dayjs/plugin/isSameOrAfter";
import axios from "axios";

dayjs.extend(weekOfYear);
dayjs.extend(isSameOrBefore);
dayjs.extend(isSameOrAfter);

const { darkAlgorithm, defaultAlgorithm } = antTheme;
const { Title, Text } = Typography;

const AVATAR_COLORS = [
    "#4096ff", "#36cfc9", "#73d13d", "#ffa940",
    "#ff7a45", "#9254de", "#f759ab", "#40a9ff",
    "#5cdbd3", "#95de64", "#ffc53d", "#ff9c6e",
];

const STATUS_CONFIG = {
    Present:    { tagColor: "success",    dot: "#52c41a" },
    Absent:     { tagColor: "error",      dot: "#ff4d4f" },
    Late:       { tagColor: "warning",    dot: "#faad14" },
    "On Leave": { tagColor: "processing", dot: "#1677ff" },
    OB:         { tagColor: "purple",     dot: "#9254de" },
    "Rest Day": { tagColor: "default",    dot: "#8c8c8c" },
    "Half Day": { tagColor: "orange",     dot: "#fa8c16" },
};

// ── Period generators ──────────────────────────────────────────────────────

function generateCutoffOptions(count = 6) {
    const options = [];
    let ref = dayjs();
    for (let i = 0; i < count; i++) {
        const day = ref.date();
        let start, end, label, value;
        if (day >= 7 && day <= 21) {
            start = ref.date(7).startOf("day");
            end   = ref.date(21).endOf("day");
            label = `${ref.format("MMM YYYY")} 1st Cut-off (7-21)`;
            value = `${ref.format("YYYY-MM")}-1`;
            ref   = ref.date(6);
        } else if (day >= 22) {
            start = ref.date(22).startOf("day");
            end   = ref.add(1, "month").date(6).endOf("day");
            label = `${ref.format("MMM YYYY")} 2nd Cut-off (22-${ref.add(1,"month").format("MMM")} 6)`;
            value = `${ref.format("YYYY-MM")}-2`;
            ref   = ref.date(21);
        } else {
            const prev = ref.subtract(1, "month");
            start = prev.date(22).startOf("day");
            end   = ref.date(6).endOf("day");
            label = `${prev.format("MMM YYYY")} 2nd Cut-off (22-${ref.format("MMM")} 6)`;
            value = `${prev.format("YYYY-MM")}-2`;
            ref   = prev.date(21);
        }
        options.push({ value, label, start: start.format("YYYY-MM-DD"), end: end.format("YYYY-MM-DD") });
    }
    return options;
}

function generateMonthOptions(count = 12) {
    return Array.from({ length: count }, (_, i) => {
        const m = dayjs().subtract(i, "month").startOf("month");
        return { value: m.format("YYYY-MM"), label: m.format("MMMM YYYY"), start: m.format("YYYY-MM-DD"), end: m.endOf("month").format("YYYY-MM-DD") };
    });
}

function generateWeekOptions(count = 8) {
    return Array.from({ length: count }, (_, i) => {
        const w = dayjs().subtract(i, "week").startOf("week");
        const e = w.endOf("week");
        return {
            value: `${w.format("YYYY")}-W${String(w.week()).padStart(2,"0")}`,
            label: `Week of ${w.format("MMM D")} - ${e.format("MMM D, YYYY")}`,
            start: w.format("YYYY-MM-DD"),
            end:   e.format("YYYY-MM-DD"),
        };
    });
}

// ── Component ──────────────────────────────────────────────────────────────

function EmployeeDashboardInner({ empPosition = null }) {
    const { token } = antTheme.useToken();

    return (
        <div style={{ display: "flex", flexDirection: "column", gap: 12, height: "100%", minHeight: 0, overflow: "hidden" }}>

            {/* Row 1 — 30% of remaining height, full width */}
            <div style={{ flex: "3 0 0", minHeight: 0, overflow: "hidden" }}>
                <Card
                    size="small"
                    title={
                        <span style={{ fontSize: "clamp(13px,1.4vw,16px)", fontWeight: 500, display: "flex", alignItems: "center", gap: 6 }}>
                            <DashboardOutlined />
                            Overview
                            <Text type="secondary" style={{ fontSize: "clamp(11px,1vw,13px)", fontWeight: 400 }}>
                                {dayjs().format("dddd, MMMM D, YYYY")}
                            </Text>
                        </span>
                    }
                    style={{ height: "100%", background: token.colorBgContainer, border: `1px solid ${token.colorBorder}` }}
                    styles={{ body: { padding: "12px 16px", height: "calc(100% - 40px)", overflow: "hidden" } }}
                >
                    <Row gutter={[12, 12]} style={{ height: "100%" }}>
                        <Col span={8} style={{ height: "100%" }}>
                            <Card
                                size="small"
                                hoverable
                                title={
                                    <span style={{ fontSize: 13, fontWeight: 500, display: "flex", alignItems: "center", gap: 6, color: token.colorSuccess }}>
                                        <FieldTimeOutlined />Day Shift
                                    </span>
                                }
                                style={{ height: "100%", background: token.colorBgElevated, border: `1px solid ${token.colorBorder}`, borderTop: `2px solid ${token.colorSuccess}` }}
                                styles={{ body: { height: "calc(100% - 40px)", padding: "8px 12px", display: "flex", flexDirection: "column", gap: 4, overflow: "hidden" } }}
                            >
                                {/* Row 1 — 2 columns (header labels) */}
                                    <div style={{ display: "grid", gridTemplateColumns: "minmax(110px, 1.8fr) repeat(5, 1fr)", gap: 4, flexShrink: 0 }}>
                                        <div style={{ gridColumn: "span 2", padding: "4px 6px", borderRadius: token.borderRadiusSM, background: `${token.colorSuccess}15`, display: "flex", alignItems: "center", justifyContent: "center" }}>
                                            <Text style={{ fontSize: 10, fontWeight: 600, color: token.colorSuccess, textTransform: "uppercase", letterSpacing: 0.5 }}>Head Count</Text>
                                        </div>
                                        <div style={{ gridColumn: "span 4", padding: "4px 6px", borderRadius: token.borderRadiusSM, background: `${token.colorSuccess}15`, display: "flex", alignItems: "center", justifyContent: "center" }}>
                                            <Text style={{ fontSize: 10, fontWeight: 600, color: token.colorSuccess, textTransform: "uppercase", letterSpacing: 0.5 }}>Rest Day</Text>
                                        </div>
                                    </div>
                                    {/* Rows 2–5 — label column + 5 data cells */}
                                    {[
                                        { row: 1, label: null },
                                        { row: 2, label: "Scheduled Shift" },
                                        { row: 3, label: "Unscheduled Shift" },
                                        { row: 4, label: "TOTAL" },
                                    ].map(({ row, label }) => {
                                        const isTotal = label === "TOTAL";
                                        const isHeader = row === 1;
                                        const colHeaders = ["Expected", "Present", "%", "Absent", "%"];
                                        return (
                                            <div key={row} style={{ display: "grid", gridTemplateColumns: "minmax(110px, 1.8fr) repeat(5, 1fr)", gap: 4, flex: 1, minHeight: 0 }}>
                                                <div style={{
                                                    borderRadius: token.borderRadiusSM,
                                                    background: isHeader ? `${token.colorPrimary}08` : isTotal ? `${token.colorPrimary}18` : `${token.colorPrimary}08`,
                                                    border: `1px solid ${isTotal ? token.colorPrimary + "50" : token.colorBorder}`,
                                                    display: "flex", alignItems: "center", paddingLeft: 8, overflow: "hidden",
                                                }}>
                                                    {label && (
                                                        <Text style={{ fontSize: 10, fontWeight: isTotal ? 700 : 500, color: isTotal ? token.colorPrimary : token.colorText, whiteSpace: "nowrap" }}>
                                                            {label}
                                                        </Text>
                                                    )}
                                                </div>
                                                {colHeaders.map((colLabel, idx) => (
                                                    <div key={idx} style={{ borderRadius: token.borderRadiusSM, background: isHeader ? `${token.colorPrimary}15` : token.colorBgContainer, border: `1px solid ${token.colorBorder}`, display: "flex", alignItems: "center", justifyContent: "center", minHeight: 0 }}>
                                                        {isHeader && (
                                                            <Text style={{ fontSize: 10, fontWeight: 600, color: token.colorPrimary, textTransform: "uppercase", letterSpacing: 0.5, whiteSpace: "nowrap" }}>
                                                                {colLabel}
                                                            </Text>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        );
                                    })}
                            </Card>
                        </Col>
                        <Col span={8} style={{ height: "100%" }}>
                            <Card
                                size="small"
                                hoverable
                                title={
                                    <span style={{ fontSize: 13, fontWeight: 500, display: "flex", alignItems: "center", gap: 6, color: token.colorPrimary }}>
                                        <ClockCircleOutlined />Night Shift
                                    </span>
                                }
                                style={{ height: "100%", background: token.colorBgElevated, border: `1px solid ${token.colorBorder}`, borderTop: `2px solid ${token.colorPrimary}` }}
                                styles={{ body: { height: "calc(100% - 40px)", padding: "8px 12px", display: "flex", flexDirection: "column", gap: 4, overflow: "hidden" } }}
                            >
                                {/* Row 1 — 2 columns (header labels) */}
                                    <div style={{ display: "grid", gridTemplateColumns: "minmax(110px, 1.8fr) repeat(5, 1fr)", gap: 4, flexShrink: 0 }}>
                                        <div style={{ gridColumn: "span 2", padding: "4px 6px", borderRadius: token.borderRadiusSM, background: `${token.colorPrimary}15`, display: "flex", alignItems: "center", justifyContent: "center" }}>
                                            <Text style={{ fontSize: 10, fontWeight: 600, color: token.colorPrimary, textTransform: "uppercase", letterSpacing: 0.5 }}>Head Count</Text>
                                        </div>
                                        <div style={{ gridColumn: "span 4", padding: "4px 6px", borderRadius: token.borderRadiusSM, background: `${token.colorPrimary}15`, display: "flex", alignItems: "center", justifyContent: "center" }}>
                                            <Text style={{ fontSize: 10, fontWeight: 600, color: token.colorPrimary, textTransform: "uppercase", letterSpacing: 0.5 }}>Rest Day</Text>
                                        </div>
                                    </div>
                                    {/* Rows 2–5 — label column + 5 data cells */}
                                    {[
                                        { row: 1, label: null },
                                        { row: 2, label: "Scheduled Shift" },
                                        { row: 3, label: "Unscheduled Shift" },
                                        { row: 4, label: "TOTAL" },
                                    ].map(({ row, label }) => {
                                        const isTotal = label === "TOTAL";
                                        const isHeader = row === 1;
                                        const colHeaders = ["Expected", "Present", "%", "Absent", "%"];
                                        return (
                                            <div key={row} style={{ display: "grid", gridTemplateColumns: "minmax(110px, 1.8fr) repeat(5, 1fr)", gap: 4, flex: 1, minHeight: 0 }}>
                                                <div style={{
                                                    borderRadius: token.borderRadiusSM,
                                                    background: isHeader ? `${token.colorSuccess}08` : isTotal ? `${token.colorSuccess}18` : `${token.colorSuccess}08`,
                                                    border: `1px solid ${isTotal ? token.colorSuccess + "50" : token.colorBorder}`,
                                                    display: "flex", alignItems: "center", paddingLeft: 8, overflow: "hidden",
                                                }}>
                                                    {label && (
                                                        <Text style={{ fontSize: 10, fontWeight: isTotal ? 700 : 500, color: isTotal ? token.colorSuccess : token.colorText, whiteSpace: "nowrap" }}>
                                                            {label}
                                                        </Text>
                                                    )}
                                                </div>
                                                {colHeaders.map((colLabel, idx) => (
                                                    <div key={idx} style={{ borderRadius: token.borderRadiusSM, background: isHeader ? `${token.colorSuccess}15` : token.colorBgContainer, border: `1px solid ${token.colorBorder}`, display: "flex", alignItems: "center", justifyContent: "center", minHeight: 0 }}>
                                                        {isHeader && (
                                                            <Text style={{ fontSize: 10, fontWeight: 600, color: token.colorSuccess, textTransform: "uppercase", letterSpacing: 0.5, whiteSpace: "nowrap" }}>
                                                                {colLabel}
                                                            </Text>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        );
                                    })}
                            </Card>
                        </Col>
                        <Col span={8} style={{ height: "100%" }}>
                            <Card
                                size="small"
                                hoverable
                                title={
                                    <span style={{ fontSize: 13, fontWeight: 500, display: "flex", alignItems: "center", gap: 6, color: token.colorTextSecondary }}>
                                        <CalendarOutlined />No Schedule
                                    </span>
                                }
                                style={{ height: "100%", background: token.colorBgElevated, border: `1px solid ${token.colorBorder}`, borderTop: `2px solid ${token.colorTextSecondary}` }}
                                styles={{ body: { height: "calc(100% - 40px)", padding: "8px 12px", display: "flex", flexDirection: "column", gap: 4, overflow: "hidden" } }}
                            >
                                {/* Row 1 — single column header label */}
                                <div style={{ display: "grid", gridTemplateColumns: "1fr", gap: 4, flexShrink: 0 }}>
                                    <div style={{
                                        padding: "4px 6px",
                                        borderRadius: token.borderRadiusSM,
                                        background: `${token.colorTextSecondary}15`,
                                        border: `1px solid ${token.colorBorder}`,
                                        display: "flex",
                                        alignItems: "center",
                                        justifyContent: "center"
                                    }}>
                                        <Text style={{ fontSize: 10, fontWeight: 600, color: token.colorTextSecondary, textTransform: "uppercase", letterSpacing: 0.5 }}>Head Count</Text>
                                    </div>
                                </div>
                                {/* Rows 2–5 — 5 columns each */}
                                {[
                                    { row: 1, label: null },
                                    { row: 2, label: "Day Shift" },
                                    { row: 3, label: "Night Shift" },
                                    { row: 4, label: "TOTAL" },
                                ].map(({ row, label }) => {
                                    const isTotal = label === "TOTAL";
                                    const isHeader = row === 1;
                                    const colHeaders = [null, "Present", "%", "Absent", "%"];
                                    return (
                                        <div key={row} style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 4, flex: 1, minHeight: 0 }}>
                                            {[1, 2, 3, 4, 5].map((col) => (
                                                <div key={col} style={{
                                                    borderRadius: token.borderRadiusSM,
                                                    background: col === 1 && label
                                                        ? isTotal ? token.colorFillSecondary : token.colorFillQuaternary
                                                        : isHeader ? token.colorFillTertiary : token.colorBgContainer,
                                                    border: `1px solid ${col === 1 && isTotal ? token.colorBorderSecondary : token.colorBorder}`,
                                                    display: "flex", alignItems: "center",
                                                    justifyContent: col === 1 && label ? "flex-start" : "center",
                                                    paddingLeft: col === 1 && label ? 8 : 0,
                                                    minHeight: 0, overflow: "hidden",
                                                }}>
                                                    {col === 1 && label && (
                                                        <Text style={{ fontSize: 10, fontWeight: isTotal ? 700 : 500, color: isTotal ? token.colorTextSecondary : token.colorText, whiteSpace: "nowrap" }}>
                                                            {label}
                                                        </Text>
                                                    )}
                                                    {isHeader && colHeaders[col - 1] && (
                                                        <Text style={{ fontSize: 10, fontWeight: 600, color: token.colorTextSecondary, textTransform: "uppercase", letterSpacing: 0.5, whiteSpace: "nowrap" }}>
                                                            {colHeaders[col - 1]}
                                                        </Text>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    );
                                })}
                            </Card>
                        </Col>
                    </Row>
                </Card>
            </div>

            {/* Row 2 — 70% of remaining height, split left 70% / right 30% */}
            <div style={{ flex: "7 0 0", minHeight: 0, display: "flex", gap: 12, overflow: "hidden" }}>

                {/* Left Panel — 70% width */}
                <div style={{ flex: "2 0 0", minWidth: 0, height: "100%", overflow: "hidden" }}>
                    <Card
                        size="small"
                        title={
                            <span style={{ fontSize: "clamp(13px,1.4vw,16px)", fontWeight: 500, display: "flex", alignItems: "center", gap: 6 }}>
                                <TableOutlined />
                                Daily Time Record
                            </span>
                        }
                        style={{ height: "100%", background: token.colorBgContainer, border: `1px solid ${token.colorBorder}` }}
                        styles={{ body: { padding: "12px 16px", height: "calc(100% - 40px)", overflow: "hidden" } }}
                    >
                        {/* Content goes here */}
                    </Card>
                </div>

                {/* Right Panel — 30% width */}
                <div style={{ flex: "1 0 0", minWidth: 0, height: "100%", overflow: "hidden" }}>
                    <Card
                        size="small"
                        title={
                            <span style={{ fontSize: "clamp(13px,1.4vw,16px)", fontWeight: 500, display: "flex", alignItems: "center", gap: 6 }}>
                                <ScheduleOutlined />
                                Attendance Analytics
                            </span>
                        }
                        style={{ height: "100%", background: token.colorBgContainer, border: `1px solid ${token.colorBorder}` }}
                        styles={{ body: { padding: "12px 16px", height: "calc(100% - 40px)", overflow: "hidden" } }}
                    >
                        {/* Content goes here */}
                    </Card>
                </div>
            </div>
        </div>
    );
}



function DashboardInner({ shiftLogs = [], employees = [], empPosition = null }) {
    const { token } = antTheme.useToken();

    const isEmployee = Number(empPosition) === 1;

    const [hovered, setHovered] = useState(false);
    const [attendanceFilter, setAttendanceFilter] = useState("cutoff");
    const [selectedPeriod, setSelectedPeriod]     = useState(null);
    const [employeeSearch, setEmployeeSearch]     = useState("");
    const [counts, setCounts]                     = useState({ present: 0, absent: 0, late: 0, restday: 0 });
    const [countsLoading, setLoading]             = useState(false);
    const abortRef                                = useRef(null);

    const latestLog = shiftLogs.length > 0 ? shiftLogs[0] : null;

    const periodOptions = useMemo(() => {
        if (attendanceFilter === "cutoff")  return generateCutoffOptions(6);
        if (attendanceFilter === "monthly") return generateMonthOptions(12);
        if (attendanceFilter === "weekly")  return generateWeekOptions(8);
        return [];
    }, [attendanceFilter]);

    const activePeriod = useMemo(
        () => periodOptions.find((o) => o.value === selectedPeriod) ?? periodOptions[0],
        [periodOptions, selectedPeriod]
    );

        const fetchCounts = useCallback(async (start, end) => {
        if (abortRef.current) abortRef.current.abort();
        abortRef.current = new AbortController();
        setLoading(true);
        try {
            const res = await fetch(route("dashboard.attendance-count", { start_date: start, end_date: end }), {
                method: "GET",
                headers: {
                    "Accept": "application/json",
                },
                signal: abortRef.current.signal,
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            setCounts({ present: data.present ?? 0, absent: data.absent ?? 0, late: data.late ?? 0, restday: data.restday ?? 0 });
        } catch (err) {
            if (err.name !== "AbortError") {
                console.error("Attendance count error:", err);
                setCounts({ present: 0, absent: 0, late: 0, restday: 0 });
            }
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (activePeriod) fetchCounts(activePeriod.start, activePeriod.end);
    }, [activePeriod, fetchCounts]);

    const handleFilterTypeChange = (val) => { setAttendanceFilter(val); setSelectedPeriod(null); };

    const filterTypeOptions = [
        { value: "cutoff",  label: "Per Cut-off" },
        { value: "monthly", label: "Monthly" },
        { value: "weekly",  label: "Weekly" },
    ];

    const filteredEmployees = useMemo(() => {
        const q = employeeSearch.trim().toLowerCase();
        if (!q) return employees;
        return employees.filter((emp) =>
            [emp.EMPNAME, emp.FIRSTNAME, emp.LASTNAME, emp.EMPLOYID, emp.JOB_TITLE, emp.DEPARTMENT]
                .filter(Boolean).some((f) => f.toLowerCase().includes(q))
        );
    }, [employees, employeeSearch]);

    const logTypes = [
        { key: "timeIn",    label: "Time In",     icon: <LoginOutlined />,  color: token.colorPrimary, value: latestLog?.timeIn    || "--:--", description: "Morning check-in time" },
        { key: "breakOut1", label: "Break Out 1", icon: <LogoutOutlined />, color: token.colorWarning, value: latestLog?.breakOut1 || "--:--", description: "First break start time" },
        { key: "breakIn1",  label: "Break In 1",  icon: <LoginOutlined />,  color: token.colorSuccess, value: latestLog?.breakIn1  || "--:--", description: "First break end time" },
        { key: "lunchOut",  label: "Lunch Out",   icon: <MenuOutlined />,   color: token.colorPrimary, value: latestLog?.lunchOut  || "--:--", description: "Lunch break start time" },
        { key: "lunchIn",   label: "Lunch In",    icon: <CoffeeOutlined />, color: token.colorSuccess, value: latestLog?.lunchIn   || "--:--", description: "Lunch break end time" },
        { key: "breakOut2", label: "Break Out 2", icon: <LogoutOutlined />, color: token.colorWarning, value: latestLog?.breakOut2 || "--:--", description: "Second break start time" },
        { key: "breakIn2",  label: "Break In 2",  icon: <LoginOutlined />,  color: token.colorSuccess, value: latestLog?.breakIn2  || "--:--", description: "Second break end time" },
        { key: "timeOut",   label: "Time Out",    icon: <LogoutOutlined />, color: token.colorError,   value: latestLog?.timeOut   || "--:--", description: "End of day check-out time" },
    ];

    const counterCards = [
        { title: "Present",  value: counts.present,  color: token.colorSuccess, icon: <CheckCircleOutlined /> },
        { title: "Absent",   value: counts.absent,   color: token.colorError,   icon: <CloseCircleOutlined /> },
        { title: "Late",     value: counts.late,     color: token.colorWarning, icon: <FieldTimeOutlined />   },
        { title: "Rest Day", value: counts.restday,  color: token.colorPrimary, icon: <CalendarOutlined />    },
    ];

    const getStatusDotColor = (l) => STATUS_CONFIG[l]?.dot      ?? token.colorTextDisabled;
    const getStatusTagColor = (l) => STATUS_CONFIG[l]?.tagColor  ?? "default";

    return (
        <div style={{ display: "flex", flexDirection: "column", gap: 16, height: "100%", minHeight: 0 }}>

            {/* Header */}
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 8 }}>
                <Title level={4} style={{ margin: 0, color: token.colorPrimary, fontSize: "clamp(16px,2vw,22px)" }}>
                    <DashboardOutlined style={{ marginRight: 8 }} />Dashboard
                </Title>
                {isEmployee && (
                    <Button
                        type="default" icon={<TableOutlined />}
                        onMouseEnter={() => setHovered(true)} onMouseLeave={() => setHovered(false)}
                        style={{ borderColor: token.colorPrimary, color: hovered ? "#fff" : token.colorPrimary, backgroundColor: hovered ? token.colorPrimary : "transparent", transition: "all 0.2s ease", fontSize: "clamp(12px,1.2vw,14px)", height: "auto", padding: "4px 14px" }}
                        onClick={() => router.visit(route("daily-time-record.index"))}
                    >View Full DTR</Button>
                )}
            </div>

            {/* Row 1 */}
            {isEmployee && (
            <Row gutter={[16, 16]}>

                {/* Shift Logs */}
                <Col xs={24} xl={14}>
                    <Card
                        title={<span style={{ fontSize: "clamp(13px,1.4vw,16px)", fontWeight: 500, display: "flex", alignItems: "center", flexWrap: "wrap", gap: 6 }}><ScheduleOutlined />Current Shift Logs<Text type="secondary" style={{ fontSize: "clamp(11px,1vw,13px)", fontWeight: 400 }}>{latestLog ? latestLog.date : "No data recorded yet"}</Text></span>}
                        size="small" style={{ background: token.colorBgContainer, border: `1px solid ${token.colorBorder}` }}
                        styles={{ body: { padding: "12px 16px 16px" } }}
                    >
                        <Row gutter={[10, 10]}>
                            {logTypes.map((lt) => (
                                <Col xs={12} sm={6} key={lt.key}>
                                    <Tooltip title={lt.description}>
                                        <Card size="small" hoverable
                                            style={{ background: token.colorBgElevated, border: `1px solid ${token.colorBorder}`, transition: "all 0.2s ease" }}
                                            styles={{ body: { height: 90, padding: "10px 12px", display: "flex", flexDirection: "column", justifyContent: "space-between" } }}
                                        >
                                            <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                                                <span style={{ color: lt.color, fontSize: 14, lineHeight: 1 }}>{lt.icon}</span>
                                                <Text strong style={{ fontSize: 12, lineHeight: 1.2 }}>{lt.label}</Text>
                                            </div>
                                            <Statistic value={lt.value} valueStyle={{ fontSize: 18, fontWeight: 600, color: lt.value === "--:--" ? token.colorTextDisabled : lt.color, lineHeight: 1 }} />
                                        </Card>
                                    </Tooltip>
                                </Col>
                            ))}
                        </Row>
                    </Card>
                </Col>

                {/* Attendance Counter */}
                <Col xs={24} xl={10}>
                    <Card
                        title={
                            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", flexWrap: "nowrap", gap: 6, overflow: "hidden", minWidth: 0 }}>
                                <span style={{ fontSize: 13, fontWeight: 500, display: "flex", alignItems: "center", gap: 5, whiteSpace: "nowrap", flexShrink: 0 }}>
                                    <ClockCircleOutlined />Attendance Counter
                                </span>
                                <div style={{ display: "flex", gap: 5, flexWrap: "nowrap", alignItems: "center", flexShrink: 0, marginLeft: "auto" }}>
                                    <Select size="small" value={attendanceFilter} onChange={handleFilterTypeChange} style={{ width: 100, fontSize: 12 }} options={filterTypeOptions} popupMatchSelectWidth={false} />
                                    <Select size="small" value={activePeriod?.value} onChange={setSelectedPeriod}
                                        suffixIcon={<FilterOutlined style={{ fontSize: 10 }} />}
                                        style={{ width: 165, fontSize: 12 }}
                                        options={periodOptions.map((o) => ({ value: o.value, label: o.label }))}
                                        popupMatchSelectWidth={false}
                                    />
                                </div>
                            </div>
                        }
                        size="small"
                        style={{ height: "100%", background: token.colorBgContainer, border: `1px solid ${token.colorBorder}` }}
                        styles={{ body: { padding: "12px 16px 16px" } }}
                    >
                        {activePeriod && (
                            <div style={{ marginBottom: 10 }}>
                                <Text type="secondary" style={{ fontSize: 11, display: "flex", alignItems: "center", gap: 4 }}>
                                    <FilterOutlined style={{ fontSize: 10 }} />{activePeriod.label}
                                    {countsLoading && <Spin indicator={<LoadingOutlined style={{ fontSize: 10 }} spin />} style={{ marginLeft: 4 }} />}
                                </Text>
                            </div>
                        )}
                        <Row gutter={[12, 12]}>
                            {counterCards.map((m) => (
                                <Col xs={12} key={m.title}>
                                    <Card size="small" style={{ borderLeft: `3px solid ${m.color}` }}
                                        styles={{ body: { height: 80, padding: "10px 12px", display: "flex", flexDirection: "column", justifyContent: "space-between" } }}
                                    >
                                        <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                                            <span style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", width: 22, height: 22, borderRadius: "50%", background: `${m.color}18`, color: m.color, fontSize: 14, flexShrink: 0 }}>{m.icon}</span>
                                            <Text strong style={{ fontSize: 12, lineHeight: 1.2 }}>{m.title}</Text>
                                        </div>
                                        <Statistic
                                            value={countsLoading ? "—" : m.value}
                                            suffix={!countsLoading && <span style={{ fontSize: 11, color: token.colorTextSecondary }}>days</span>}
                                            precision={0}
                                            valueStyle={{ fontSize: 18, fontWeight: 600, color: m.color, lineHeight: 1 }}
                                        />
                                    </Card>
                                </Col>
                            ))}
                        </Row>
                    </Card>
                </Col>
            </Row>
            )}

            {/* Row 2: Management Attendance Overview */}
            {isEmployee && (
            <Row style={{ flex: 1, minHeight: 0, height: "100%" }}>
                <Col span={24} style={{ display: "flex", flexDirection: "column", minHeight: 0, height: "100%" }}>
                    <Card
                        title={
                            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", flexWrap: "wrap", gap: 8 }}>
                                <span style={{ fontSize: 13, fontWeight: 500, display: "flex", alignItems: "center", gap: 6, whiteSpace: "nowrap", flexShrink: 0 }}>
                                    <TeamOutlined />Management Attendance Overview
                                    <Tag color="blue" style={{ fontSize: 11, lineHeight: "18px", margin: 0 }}>{employees.length} Active</Tag>
                                </span>
                                <Input size="small" placeholder="Search by name, dept, position..."
                                    prefix={<SearchOutlined style={{ color: token.colorTextDisabled, fontSize: 12 }} />}
                                    allowClear value={employeeSearch} onChange={(e) => setEmployeeSearch(e.target.value)}
                                    style={{ flex: "1 1 180px", maxWidth: 240, minWidth: 140, fontSize: 12 }}
                                />
                            </div>
                        }
                        size="small"
                        style={{ background: token.colorBgContainer, border: `1px solid ${token.colorBorder}`, flex: 1, minHeight: 0, display: "flex", flexDirection: "column" }}
                        styles={{ body: { padding: "12px 16px 16px", flex: 1, minHeight: 0, display: "flex", flexDirection: "column" } }}
                    >
                        {filteredEmployees.length === 0 ? (
                            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description={<Text type="secondary" style={{ fontSize: 12 }}>{employeeSearch ? "No employees match your search" : "No active employees found"}</Text>}
                            />
                        ) : (
                            <div style={{ flex: 1, minHeight: 0, overflowY: "auto", overflowX: "hidden" }}>
                                <div style={{ display: "grid", gridTemplateColumns: "repeat(5, minmax(0, 1fr))", gap: 10 }}>
                                    {filteredEmployees.map((emp) => {
                                        const initials    = [emp.FIRSTNAME?.[0], emp.LASTNAME?.[0]].filter(Boolean).join("").toUpperCase() || "?";
                                        const avatarColor = AVATAR_COLORS[(emp.EMPID || 0) % AVATAR_COLORS.length];
                                        const displayName = emp.EMPNAME || `${emp.FIRSTNAME ?? ""} ${emp.LASTNAME ?? ""}`.trim() || "—";
                                        const statusLabel = emp.attendanceStatus ?? "Absent";
                                        const dotColor    = getStatusDotColor(statusLabel);
                                        const tagColor    = getStatusTagColor(statusLabel);

                                        return (
                                            <Card key={emp.EMPID} size="small" hoverable
                                                style={{ border: `1px solid ${token.colorBorder}`, background: token.colorBgElevated, transition: "all 0.2s ease", minWidth: 0, width: "100%" }}
                                                styles={{ body: { padding: "10px 12px", display: "flex", flexDirection: "column", gap: 8 } }}
                                            >
                                                <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                                                    <Avatar size={34} style={{ backgroundColor: avatarColor, fontSize: 12, fontWeight: 600, flexShrink: 0 }}>{initials}</Avatar>
                                                    <div style={{ minWidth: 0, flex: 1 }}>
                                                        <Tooltip title={displayName}>
                                                            <Text strong style={{ fontSize: 11, display: "block", lineHeight: 1.3, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{displayName}</Text>
                                                        </Tooltip>
                                                        <Text type="secondary" style={{ fontSize: 10, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", display: "block" }}>{emp.EMPLOYID || "—"}</Text>
                                                    </div>
                                                </div>
                                                <div style={{ display: "flex", flexDirection: "column", gap: 3 }}>
                                                    {emp.JOB_TITLE && (
                                                        <div style={{ display: "flex", alignItems: "center", gap: 5, minWidth: 0 }}>
                                                            <UserOutlined style={{ fontSize: 10, color: token.colorPrimary, flexShrink: 0 }} />
                                                            <Tooltip title={emp.JOB_TITLE}><Text style={{ fontSize: 10, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", minWidth: 0, flex: 1 }}>{emp.JOB_TITLE}</Text></Tooltip>
                                                        </div>
                                                    )}
                                                    {emp.DEPARTMENT && (
                                                        <div style={{ display: "flex", alignItems: "center", gap: 5, minWidth: 0 }}>
                                                            <ApartmentOutlined style={{ fontSize: 10, color: token.colorWarning, flexShrink: 0 }} />
                                                            <Tooltip title={emp.DEPARTMENT}><Text style={{ fontSize: 10, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", minWidth: 0, flex: 1 }}>{emp.DEPARTMENT}</Text></Tooltip>
                                                        </div>
                                                    )}
                                                </div>
                                                <div style={{ display: "flex", gap: 4, flexWrap: "wrap", alignItems: "center", marginTop: "auto" }}>
                                                    <Tag color={tagColor} style={{ fontSize: 10, lineHeight: "16px", margin: 0, padding: "0 6px", display: "inline-flex", alignItems: "center", gap: 4 }}>
                                                        <span style={{ width: 6, height: 6, borderRadius: "50%", backgroundColor: dotColor, display: "inline-block", flexShrink: 0 }} />{statusLabel}
                                                    </Tag>
                                                    {emp.positionLabel && (
                                                        <Tag color="geekblue" style={{ fontSize: 10, lineHeight: "16px", margin: 0, padding: "0 5px" }}>{emp.positionLabel}</Tag>
                                                    )}
                                                </div>
                                            </Card>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </Card>
                </Col>
            </Row>
            )}
        </div>
    );
}

export default function Dashboard({ shiftLogs = [], employees = [], empPosition = null }) {
    const { theme } = useContext(ThemeContext);
    const isEmployee = Number(empPosition) === 1;
    return (
        <AuthenticatedLayout>
            <ConfigProvider theme={{ algorithm: theme === "dark" ? darkAlgorithm : defaultAlgorithm }}>
                {isEmployee
                    ? <DashboardInner shiftLogs={shiftLogs} employees={employees} empPosition={empPosition} />
                    : <EmployeeDashboardInner empPosition={empPosition} />
                }
            </ConfigProvider>
        </AuthenticatedLayout>
    );
}