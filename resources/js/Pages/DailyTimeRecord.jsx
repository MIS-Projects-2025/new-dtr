import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { router } from "@inertiajs/react";
import {
    CalendarOutlined,
    TableOutlined,
    MoonOutlined,
} from "@ant-design/icons";
import { useState, useContext, useMemo } from "react";
import { ThemeContext } from "@/Components/ThemeContext";
import dayjs from "dayjs";
import {
    ConfigProvider,
    Select,
    Button,
    Table,
    Typography,
    Space,
    Radio,
    Calendar,
    theme as antTheme,
} from "antd";

const { Option } = Select;
const { darkAlgorithm, defaultAlgorithm } = antTheme;

// ── Constants ────────────────────────────────────────────────────────────────

const MONTHS = [
    { value: "01", label: "January" }, { value: "02", label: "February" },
    { value: "03", label: "March" }, { value: "04", label: "April" },
    { value: "05", label: "May" }, { value: "06", label: "June" },
    { value: "07", label: "July" }, { value: "08", label: "August" },
    { value: "09", label: "September" }, { value: "10", label: "October" },
    { value: "11", label: "November" }, { value: "12", label: "December" },
];

const C = {
    green:   "rgba(25,135,84,0.6)",
    red:     "rgba(220,53,69,0.6)",
    yellow:  "rgba(255,193,7,0.6)",
    blue:    "rgba(13,110,253,0.6)",
    purple:  "rgba(111,66,193,0.6)",
    grey:    "rgba(108,117,125,0.6)",
    cyan:    "rgba(13,202,240,0.6)",
    neutral: "rgba(200,200,200,0.15)",
};

const REMARK_C = {
    green:  "rgba(25,135,84,0.7)",
    red:    "rgba(220,53,69,0.7)",
    yellow: "rgba(255,193,7,0.7)",
    blue:   "rgba(13,110,253,0.7)",
    purple: "rgba(111,66,193,0.7)",
    grey:   "rgba(108,117,125,0.7)",
    cyan:   "rgba(13,202,240,0.7)",
};

const LUNCH_TINT_LIGHT = "rgba(255,248,225,0.7)";
const LUNCH_TINT_DARK  = "rgba(255,193,7,0.08)";

// ── Shared Logic (Syncs Table & Calendar) ───────────────────────────────────

function getCellColors(row) {
    const sl = (row.remarks ?? "").toLowerCase();
    const isUnscheduled = row.shift_type === "Unscheduled";
    const filled = (v) => (v ? C.green : C.red);
    const neutral = (v) => (v ? C.green : C.neutral);
    const colBreak = isUnscheduled ? neutral : filled;

    let ci = filled(row.time_in);
    let bo1 = colBreak(row.break_out_1);
    let bi1 = colBreak(row.break_in_1);
    let lo = colBreak(row.lunch_out);
    let li = colBreak(row.lunch_in);
    let bo2 = colBreak(row.break_out_2);
    let bi2 = colBreak(row.break_in_2);
    let co = filled(row.time_out);
    let rm = "";

    const setAll = (c) => { ci = bo1 = bi1 = lo = li = bo2 = bi2 = co = c; };

    if (row.holiday_info) { setAll(C.grey); rm = REMARK_C.grey; }
    else if (row.leave_info) { setAll(C.blue); rm = REMARK_C.blue; }
    else if (row.is_full_ob) { setAll(C.purple); rm = REMARK_C.purple; }
    else if (row.remarks === "Rest Day") { setAll(C.grey); rm = REMARK_C.grey; }
    else if (row.remarks === "Absent") { setAll(C.red); rm = REMARK_C.red; }
    else if (row.remarks === "Current Date") {
        if (!row.time_in) ci = C.cyan;
        if (!row.break_out_1) bo1 = C.cyan;
        if (!row.break_in_1) bi1 = C.cyan;
        if (!row.lunch_out) lo = C.cyan;
        if (!row.lunch_in) li = C.cyan;
        if (!row.break_out_2) bo2 = C.cyan;
        if (!row.break_in_2) bi2 = C.cyan;
        if (!row.time_out) co = C.cyan;
        rm = REMARK_C.cyan;
    } else {
        if (sl.includes("late") && !sl.includes("late break")) ci = C.yellow;
        if (sl.includes("early out")) co = C.yellow;
        if (sl.includes("over break 1")) bi1 = C.yellow;
        if (sl.includes("over lunch")) li = C.yellow;
        if (sl.includes("over break 2")) bi2 = C.yellow;
        rm = /late|early out|no check|over break|over lunch/.test(sl) ? REMARK_C.yellow : REMARK_C.green;
    }
    return { ci, bo1, bi1, lo, li, bo2, bi2, co, rm };
}

// ── Table UI Components ─────────────────────────────────────────────────────

function TimeCellContent({ actual, expected }) {
    return (
        <div style={{ textAlign: "center", lineHeight: 1.4 }}>
            <div style={{ fontWeight: 600, fontSize: "0.8rem", whiteSpace: "nowrap" }}>
                {actual || "--:--"}
            </div>
            {expected && <div style={{ fontSize: "0.72rem", opacity: 0.55 }}>exp: {expected}</div>}
        </div>
    );
}

function RemarksCellContent({ row }) {
    return (
        <div style={{ textAlign: "center", lineHeight: 1.2 }}>
            <div style={{ fontWeight: 600, fontSize: "0.8rem" }}>{row.remarks}</div>
            {row.is_night && <div style={{ fontSize: "0.7rem", opacity: 0.6 }}><MoonOutlined /> Night</div>}
        </div>
    );
}

// ── Inner UI Component ───────────────────────────────────────────────────────

function DailyTimeRecordInner({ tableData, tableFilters, isDark }) {
    const [viewMode, setViewMode] = useState("table");
    const { token } = antTheme.useToken();
    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: 5 }, (_, i) => currentYear - i);

    const initMonth = tableFilters.month ? tableFilters.month.split("-")[1] : String(new Date().getMonth() + 1).padStart(2, "0");
    const initYear = tableFilters.month ? tableFilters.month.split("-")[0] : String(currentYear);

    const [filterMonth, setFilterMonth] = useState(initMonth);
    const [filterYear, setFilterYear] = useState(initYear);
    const lunchTint = isDark ? LUNCH_TINT_DARK : LUNCH_TINT_LIGHT;

    const dataMap = useMemo(() => {
        const map = {};
        tableData.forEach(item => { map[item.date] = item; });
        return map;
    }, [tableData]);

    const handleFilter = () => {
        router.get(route("daily-time-record.index"), { month: `${filterYear}-${filterMonth}` }, { preserveState: true });
    };

    // ── CALENDAR RENDERER (Updated Sequence & Layout) ───────────────────────

    const dateCellRender = (value) => {
        const dateStr = value.format("YYYY-MM-DD");
        const row = dataMap[dateStr];
        if (!row) return null;

        const colors = getCellColors(row);
        
        const renderTimeBox = (label, time, color) => (
            <div style={{
                fontSize: '10px',
                padding: '2px 4px',
                borderRadius: '4px',
                backgroundColor: color,
                display: 'flex',
                justifyContent: 'space-between',
                color: isDark ? '#fff' : '#000',
                border: color === C.neutral ? `1px solid ${token.colorBorderSecondary}` : 'none',
                minHeight: '20px',
                alignItems: 'center'
            }}>
                <span style={{ opacity: 0.6, fontSize: '8px', fontWeight: 600, marginRight: '4px' }}>{label}</span>
                <span style={{ fontWeight: 700 }}>{time || '--:--'}</span>
            </div>
        );

        return (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', padding: '1px' }}>
                <div style={{ 
                    backgroundColor: colors.rm, fontSize: '10px', fontWeight: 'bold', 
                    textAlign: 'center', borderRadius: '4px', padding: '3px 0',
                    color: isDark ? '#fff' : 'rgba(0,0,0,0.85)', boxShadow: '0 1px 1px rgba(0,0,0,0.1)'
                }}>
                    {row.remarks} {row.is_night && <MoonOutlined style={{ fontSize: 9, color: '#ffa940' }} />}
                </div>

                {/* 4-Row Sequence Grid */}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '3px' }}>
                    {renderTimeBox('IN', row.time_in, colors.ci)}
                    {renderTimeBox('B1-O', row.break_out_1, colors.bo1)}
                    
                    {renderTimeBox('B1-I', row.break_in_1, colors.bi1)}
                    {renderTimeBox('L-O', row.lunch_out, colors.lo)}
                    
                    {renderTimeBox('L-I', row.lunch_in, colors.li)}
                    {renderTimeBox('B2-O', row.break_out_2, colors.bo2)}
                    
                    {renderTimeBox('B2-I', row.break_in_2, colors.bi2)}
                    {renderTimeBox('OUT', row.time_out, colors.co)}
                </div>
                
                {/* Full Holiday/Leave Info (Wrapping allowed) */}
                {(row.holiday_info || row.leave_info) && (
                    <div style={{ 
                        fontSize: '9px', 
                        lineHeight: '1.2', 
                        fontWeight: 500,
                        color: token.colorPrimary, 
                        marginTop: '2px', 
                        textAlign: 'center',
                        wordBreak: 'break-word',
                        whiteSpace: 'normal'
                    }}>
                        {row.holiday_info?.name || row.leave_info?.type}
                    </div>
                )}
            </div>
        );
    };

    // ── TABLE COLUMNS ────────────────────────────────────────────────────────

    const makeTimeCol = (title, actualKey, expectedKey, bgKey, isLunch = false) => ({
        title, key: actualKey, align: "center", width: 90,
        onHeaderCell: () => ({ style: isLunch ? { backgroundColor: lunchTint } : {} }),
        onCell: (row) => {
            const colors = getCellColors(row);
            const hasBg = colors[bgKey] && colors[bgKey] !== C.neutral;
            return {
                style: { backgroundColor: hasBg ? colors[bgKey] : isLunch ? lunchTint : undefined, padding: "6px 8px" },
            };
        },
        render: (_, row) => <TimeCellContent actual={row[actualKey]} expected={row[expectedKey]} />,
    });

    const columns = [
        { title: "Date", dataIndex: "date", key: "date", width: 100, fixed: "left", onCell: (r) => ({ style: { fontWeight: 600, borderLeft: r.is_night ? "4px solid #FF5722" : undefined } }) },
        { title: "Day", dataIndex: "day", key: "day", width: 60, align: "center" },
        { title: "Shift", dataIndex: "shift_type", key: "shift_type", width: 110, align: "center" },
        makeTimeCol("In", "time_in", "exp_time_in", "ci"),
        makeTimeCol("B1 Out", "break_out_1", "exp_break_out_1", "bo1"),
        makeTimeCol("B1 In", "break_in_1", "exp_break_in_1", "bi1"),
        makeTimeCol("Lunch O", "lunch_out", "exp_lunch_out", "lo", true),
        makeTimeCol("Lunch I", "lunch_in", "exp_lunch_in", "li", true),
        makeTimeCol("B2 Out", "break_out_2", "exp_break_out_2", "bo2"),
        makeTimeCol("B2 In", "break_in_2", "exp_break_in_2", "bi2"),
        makeTimeCol("Out", "time_out", "exp_time_out", "co"),
        { title: "Remarks", key: "remarks", width: 140, align: "center", onCell: (r) => ({ style: { backgroundColor: getCellColors(r).rm } }), render: (_, r) => <RemarksCellContent row={r} /> },
    ];

    return (
        <div style={{ padding: 16, height: "100%", display: "flex", flexDirection: "column" }}>
            <style>
                {`
                    .ant-picker-calendar-full .ant-picker-calendar-date {
                        height: 200px !important; 
                        padding: 6px !important;
                    }
                    .ant-picker-calendar-full .ant-picker-calendar-date-content {
                        height: auto !important;
                        overflow: visible !important;
                    }
                `}
            </style>

            <div style={{ border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 8, background: token.colorBgContainer, flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
                <div style={{ padding: "16px 24px", borderBottom: `1px solid ${token.colorBorderSecondary}` }}>
                    <div style={{ display: "flex", flexWrap: "wrap", alignItems: "center", justifyContent: "space-between", gap: 12 }}>
                        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 600, color: token.colorPrimary }}>
                            <CalendarOutlined /> Daily Time Record
                        </h1>
                        <Space wrap>
                            <Radio.Group value={viewMode} onChange={(e) => setViewMode(e.target.value)} buttonStyle="solid">
                                <Radio.Button value="table"><TableOutlined /> Table</Radio.Button>
                                <Radio.Button value="calendar"><CalendarOutlined /> Calendar</Radio.Button>
                            </Radio.Group>
                            <Select value={filterMonth} onChange={setFilterMonth} style={{ width: 120 }}>
                                {MONTHS.map((m) => <Option key={m.value} value={m.value}>{m.label}</Option>)}
                            </Select>
                            <Select value={filterYear} onChange={setFilterYear} style={{ width: 90 }}>
                                {years.map((y) => <Option key={y} value={String(y)}>{y}</Option>)}
                            </Select>
                            <Button type="primary" onClick={handleFilter}>Filter</Button>
                        </Space>
                    </div>
                </div>

                <div style={{ flex: 1, overflow: "auto", padding: viewMode === 'calendar' ? 0 : '0 24px 16px' }}>
                    {viewMode === "table" ? (
                        <Table columns={columns} dataSource={tableData} rowKey="date" pagination={false} bordered size="small" scroll={{ x: 'max-content' }} />
                    ) : (
                        <Calendar cellRender={dateCellRender} headerRender={() => null} value={dayjs(`${filterYear}-${filterMonth}-01`)} />
                    )}
                </div>
            </div>
        </div>
    );
}

export default function DailyTimeRecord({ tableData = [], tableFilters = {} }) {
    const { theme } = useContext(ThemeContext);
    const isDark = theme === "dark";

    return (
        <AuthenticatedLayout>
            <ConfigProvider theme={{ algorithm: isDark ? darkAlgorithm : defaultAlgorithm }}>
                <DailyTimeRecordInner tableData={tableData} tableFilters={tableFilters} isDark={isDark} />
            </ConfigProvider>
        </AuthenticatedLayout>
    );
}