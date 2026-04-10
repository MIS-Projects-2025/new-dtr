import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { router } from "@inertiajs/react";
import {
    CalendarOutlined,
    MoonOutlined,
    InfoCircleOutlined,
} from "@ant-design/icons";
import { useState, useContext } from "react";
import { ThemeContext } from "@/Components/ThemeContext"; // adjust path if needed
import {
    ConfigProvider,
    Select,
    Button,
    Table,
    Tag,
    Alert,
    Badge,
    Popover,
    Typography,
    Space,
    theme as antTheme,
} from "antd";

const { Text } = Typography;
const { Option } = Select;
const { darkAlgorithm, defaultAlgorithm } = antTheme;

// ── Constants ─────────────────────────────────────────────────────────────────

const MONTHS = [
    { value: "01", label: "January"   },
    { value: "02", label: "February"  },
    { value: "03", label: "March"     },
    { value: "04", label: "April"     },
    { value: "05", label: "May"       },
    { value: "06", label: "June"      },
    { value: "07", label: "July"      },
    { value: "08", label: "August"    },
    { value: "09", label: "September" },
    { value: "10", label: "October"   },
    { value: "11", label: "November"  },
    { value: "12", label: "December"  },
];

const LEGEND = [
    { color: "rgba(25,135,84,0.7)",   label: "Present / On Time"               },
    { color: "rgba(255,193,7,0.7)",   label: "Late / Early Out / Break Issues" },
    { color: "rgba(220,53,69,0.7)",   label: "Absent"                          },
    { color: "rgba(13,110,253,0.7)",  label: "Leave"                           },
    { color: "rgba(111,66,193,0.7)",  label: "Official / Personal Business"    },
    { color: "rgba(108,117,125,0.7)", label: "Rest Day / Holiday"              },
    { color: "rgba(13,202,240,0.7)",  label: "Current Day (Pending)"           },
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

// Lunch tint — different per mode so it's visible on both backgrounds
const LUNCH_TINT_LIGHT = "rgba(255,248,225,0.7)";
const LUNCH_TINT_DARK  = "rgba(255,193,7,0.08)";

// ── Helpers ───────────────────────────────────────────────────────────────────

function getCellColors(row) {
    const sl            = (row.remarks ?? "").toLowerCase();
    const isUnscheduled = row.shift_type === "Unscheduled";

    const filled   = (v) => (v ? C.green   : C.red);
    const neutral  = (v) => (v ? C.green   : C.neutral);
    const colBreak = isUnscheduled ? neutral : filled;

    let ci  = filled(row.time_in);
    let bo1 = colBreak(row.break_out_1);
    let bi1 = colBreak(row.break_in_1);
    let lo  = colBreak(row.lunch_out);
    let li  = colBreak(row.lunch_in);
    let bo2 = colBreak(row.break_out_2);
    let bi2 = colBreak(row.break_in_2);
    let co  = filled(row.time_out);
    let rm  = "";

    const setAll = (c) => { ci = bo1 = bi1 = lo = li = bo2 = bi2 = co = c; };

    if (row.holiday_info) {
        setAll(C.grey);   rm = REMARK_C.grey;
    } else if (row.leave_info) {
        setAll(C.blue);   rm = REMARK_C.blue;
    } else if (row.is_full_ob) {
        setAll(C.purple); rm = REMARK_C.purple;
    } else if (row.remarks === "Rest Day") {
        setAll(C.grey);   rm = REMARK_C.grey;
    } else if (row.remarks === "Absent") {
        setAll(C.red);    rm = REMARK_C.red;
    } else if (row.remarks === "Current Date") {
        if (!row.time_in)      ci  = C.cyan;
        if (!row.break_out_1)  bo1 = C.cyan;
        if (!row.break_in_1)   bi1 = C.cyan;
        if (!row.lunch_out)    lo  = C.cyan;
        if (!row.lunch_in)     li  = C.cyan;
        if (!row.break_out_2)  bo2 = C.cyan;
        if (!row.break_in_2)   bi2 = C.cyan;
        if (!row.time_out)     co  = C.cyan;
        rm = REMARK_C.cyan;
    } else if (row.remarks === "Present") {
        rm = REMARK_C.green;
        if (row.ob_info && !row.is_full_ob) {
            const oc = row.ob_covered ?? {};
            if (oc.check_in)   ci  = C.purple;
            if (oc.break_out1) bo1 = C.purple;
            if (oc.break_in1)  bi1 = C.purple;
            if (oc.lunch_out)  lo  = C.purple;
            if (oc.lunch_in)   li  = C.purple;
            if (oc.break_out2) bo2 = C.purple;
            if (oc.break_in2)  bi2 = C.purple;
            if (oc.check_out)  co  = C.purple;
        }
    } else {
        if (sl.includes("late") && !sl.includes("late break")) ci  = C.yellow;
        if (sl.includes("early out"))                          co  = C.yellow;
        if (sl.includes("no check-in"))                        ci  = C.yellow;
        if (sl.includes("no check-out"))                       co  = C.yellow;
        if (sl.includes("over break 1"))                       bi1 = C.yellow;
        if (sl.includes("over lunch"))                         li  = C.yellow;
        if (sl.includes("over break 2"))                       bi2 = C.yellow;
        if (/late|early out|no check|over break|over lunch/.test(sl)) rm = REMARK_C.yellow;

        if (row.ob_info && !row.is_full_ob) {
            const oc = row.ob_covered ?? {};
            if (oc.check_in)   ci  = C.purple;
            if (oc.break_out1) bo1 = C.purple;
            if (oc.break_in1)  bi1 = C.purple;
            if (oc.lunch_out)  lo  = C.purple;
            if (oc.lunch_in)   li  = C.purple;
            if (oc.break_out2) bo2 = C.purple;
            if (oc.break_in2)  bi2 = C.purple;
            if (oc.check_out)  co  = C.purple;
        }
    }

    return { ci, bo1, bi1, lo, li, bo2, bi2, co, rm };
}

// ── Sub-components ────────────────────────────────────────────────────────────

function TimeCellContent({ actual, expected }) {
    return (
        <div style={{ textAlign: "center", lineHeight: 1.4 }}>
            <div style={{ fontWeight: 600, fontSize: "0.8rem", whiteSpace: "nowrap" }}>
                {actual || "--:--"}
            </div>
            {expected && (
                <div style={{ fontSize: "0.72rem", opacity: 0.55, fontWeight: 400 }}>
                    exp: {expected}
                </div>
            )}
        </div>
    );
}

function RemarksCellContent({ row }) {
    const { remarks, leave_info, holiday_info, ob_info, is_full_ob, is_night, date } = row;

    const nextDay = is_night
        ? new Date(new Date(date).getTime() + 86400000).toLocaleDateString("en-US", {
              month: "short", day: "numeric",
          })
        : null;

    let content;
    if (holiday_info) {
        content = (
            <>
                <div style={{ fontWeight: 600, fontSize: "0.8rem" }}>Holiday</div>
                <div style={{ fontSize: "0.72rem", opacity: 0.55 }}>{holiday_info.name}</div>
            </>
        );
    } else if (leave_info) {
        const isHalf = ["half-day", "half day"].includes((leave_info.duration ?? "").toLowerCase());
        content = (
            <>
                <div style={{ fontWeight: 600, fontSize: "0.8rem" }}>{leave_info.type}</div>
                {isHalf && <div style={{ fontSize: "0.72rem", opacity: 0.55 }}>{leave_info.period}</div>}
            </>
        );
    } else if (is_full_ob && ob_info) {
        content = (
            <>
                <div style={{ fontWeight: 600, fontSize: "0.8rem" }}>{ob_info.type}</div>
                {ob_info.time_from && ob_info.time_to && (
                    <div style={{ fontSize: "0.72rem", opacity: 0.55 }}>
                        {ob_info.time_from} – {ob_info.time_to}
                    </div>
                )}
            </>
        );
    } else {
        content = (
            <>
                <div style={{ fontWeight: 600, fontSize: "0.8rem" }}>{remarks}</div>
                {ob_info && !is_full_ob && (
                    <div style={{ fontSize: "0.72rem", opacity: 0.55 }}>
                        {ob_info.type}: {ob_info.time_from} – {ob_info.time_to}
                    </div>
                )}
            </>
        );
    }

    return (
        <div style={{ textAlign: "center", lineHeight: 1.4 }}>
            {content}
            {is_night && (
                <div style={{ fontSize: "0.72rem", opacity: 0.55 }}>
                    <MoonOutlined style={{ marginRight: 2 }} /> Until {nextDay}
                </div>
            )}
        </div>
    );
}

function LegendContent() {
    return (
        <div style={{ minWidth: 260 }}>
            <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                {LEGEND.map((item) => (
                    <div key={item.label} style={{ display: "flex", alignItems: "center", gap: 10 }}>
                        <div
                            style={{
                                width: 18,
                                height: 18,
                                borderRadius: 4,
                                backgroundColor: item.color,
                                border: "1px solid rgba(128,128,128,0.3)",
                                flexShrink: 0,
                            }}
                        />
                        <Text style={{ fontSize: 13 }}>{item.label}</Text>
                    </div>
                ))}
                <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                    <div
                        style={{
                            width: 18,
                            height: 18,
                            borderRadius: 4,
                            border: "2px solid #FF5722",
                            flexShrink: 0,
                        }}
                    />
                    <Tag color="#FF5722" style={{ fontSize: 12, margin: 0 }}>
                        <MoonOutlined style={{ marginRight: 4 }} /> Night Shift
                    </Tag>
                </div>
            </div>
        </div>
    );
}

// ── Inner page (receives isDark) ──────────────────────────────────────────────

function DailyTimeRecordInner({ tableData, tableFilters, isDark }) {
    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: 5 }, (_, i) => currentYear - i);

    const initMonth = tableFilters.month
        ? tableFilters.month.split("-")[1]
        : String(new Date().getMonth() + 1).padStart(2, "0");
    const initYear = tableFilters.month
        ? tableFilters.month.split("-")[0]
        : String(currentYear);

    const [filterMonth, setFilterMonth] = useState(initMonth);
    const [filterYear, setFilterYear]   = useState(initYear);

    const lunchTint = isDark ? LUNCH_TINT_DARK : LUNCH_TINT_LIGHT;

    const handleFilter = () => {
        router.get(
            route("daily-time-record.index"),
            { month: `${filterYear}-${filterMonth}` },
            { preserveState: true }
        );
    };

    const displayMonth = tableFilters.month
        ? new Date(tableFilters.month + "-01").toLocaleDateString("en-US", {
              month: "long", year: "numeric",
          })
        : "";

    const makeTimeCol = (title, actualKey, expectedKey, bgKey, isLunch = false) => ({
        title,
        key: actualKey,
        align: "center",
        width: 90,
        onHeaderCell: () => ({
            style: isLunch ? { backgroundColor: lunchTint } : {},
        }),
        onCell: (row) => {
            const colors = getCellColors(row);
            const hasBg  = colors[bgKey] && colors[bgKey] !== C.neutral;
            return {
                style: {
                    backgroundColor: hasBg ? colors[bgKey] : isLunch ? lunchTint : undefined,
                    padding: "6px 8px",
                },
            };
        },
        render: (_, row) => (
            <TimeCellContent actual={row[actualKey]} expected={row[expectedKey]} />
        ),
    });

    const columns = [
        {
            title: "Date",
            dataIndex: "date",
            key: "date",
            align: "center",
            width: 95,
            fixed: "left",
            onCell: (row) => ({
                style: {
                    fontWeight: 600,
                    fontSize: "0.8rem",
                    whiteSpace: "nowrap",
                    borderLeft: row.is_night ? "4px solid #FF5722" : undefined,
                    padding: "6px 8px",
                },
            }),
        },
        {
            title: "Day",
            dataIndex: "day",
            key: "day",
            align: "center",
            width: 60,
            onCell: () => ({ style: { fontWeight: 600, fontSize: "0.8rem", padding: "6px 8px" } }),
        },
        {
            title: "Code",
            dataIndex: "code",
            key: "code",
            align: "center",
            width: 70,
            onCell: () => ({ style: { fontWeight: 600, fontSize: "0.8rem", padding: "6px 8px" } }),
        },
        {
            title: "Shift Type",
            dataIndex: "shift_type",
            key: "shift_type",
            align: "center",
            width: 110,
            render: (shiftType, row) =>
                row.is_night ? (
                    <Tag color="#FF5722" style={{ fontWeight: 600 }}>
                        <MoonOutlined style={{ marginRight: 4 }} />
                        {shiftType}
                    </Tag>
                ) : (
                    <span style={{ fontWeight: 600, fontSize: "0.8rem" }}>{shiftType}</span>
                ),
            onCell: () => ({ style: { padding: "6px 8px" } }),
        },
        makeTimeCol("Time In",     "time_in",     "exp_time_in",     "ci"),
        makeTimeCol("Break Out 1", "break_out_1", "exp_break_out_1", "bo1"),
        makeTimeCol("Break In 1",  "break_in_1",  "exp_break_in_1",  "bi1"),
        makeTimeCol("Lunch Out",   "lunch_out",   "exp_lunch_out",   "lo",  true),
        makeTimeCol("Lunch In",    "lunch_in",    "exp_lunch_in",    "li",  true),
        makeTimeCol("Break Out 2", "break_out_2", "exp_break_out_2", "bo2"),
        makeTimeCol("Break In 2",  "break_in_2",  "exp_break_in_2",  "bi2"),
        makeTimeCol("Time Out",    "time_out",    "exp_time_out",    "co"),
        {
            title: "Remarks",
            key: "remarks",
            align: "center",
            width: 140,
            onCell: (row) => {
                const colors = getCellColors(row);
                return { style: { backgroundColor: colors.rm || undefined, padding: "6px 8px" } };
            },
            render: (_, row) => <RemarksCellContent row={row} />,
        },
    ];

    return (
        <div style={{ padding: 16, height: "100%", display: "flex", flexDirection: "column" }}>
            <div
                style={{
                    border: `1px solid ${isDark ? "rgba(255,255,255,0.15)" : "rgba(0,0,0,0.15)"}`,
                    borderRadius: 8,
                    background: "var(--ant-color-bg-container)",
                    display: "flex",
                    flexDirection: "column",
                    flex: 1,
                    overflow: "hidden",
                }}
            >
                {/* ── Header ─────────────────────────────────────────── */}
                <div style={{ padding: "16px 24px", borderBottom: `1px solid ${isDark ? "rgba(255,255,255,0.15)" : "rgba(0,0,0,0.15)"}`, flexShrink: 0 }}>
                    <div style={{ display: "flex", flexWrap: "wrap", alignItems: "center", justifyContent: "space-between", gap: 12 }}>
                        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 600, display: "flex", alignItems: "center", gap: 8, color: "var(--ant-color-primary)" }}>
                            <CalendarOutlined />
                            Daily Time Record
                        </h1>

                        <Space wrap>
                            <Select size="middle" value={filterMonth} onChange={setFilterMonth} style={{ width: 130 }}>
                                {MONTHS.map((m) => <Option key={m.value} value={m.value}>{m.label}</Option>)}
                            </Select>

                            <Select size="middle" value={filterYear} onChange={setFilterYear} style={{ width: 90 }}>
                                {years.map((y) => <Option key={y} value={String(y)}>{y}</Option>)}
                            </Select>

                            <Button type="primary" onClick={handleFilter}>Filter</Button>

                            <Popover title="Color Legend" content={<LegendContent />} trigger="click" placement="bottomRight">
                                <Button icon={<InfoCircleOutlined />}>Legend</Button>
                            </Popover>
                        </Space>
                    </div>
                </div>

                {/* ── Month Banner ────────────────────────────────────── */}
                {displayMonth && (
                    <div style={{ padding: "12px 24px 0", flexShrink: 0 }}>
                        <Alert
                            type="info"
                            showIcon
                            icon={<CalendarOutlined />}
                            message={
                                <Space>
                                    <span><strong>Showing records for:</strong> {displayMonth}</span>
                                    <Badge count={tableData.length} overflowCount={999} style={{ backgroundColor: "var(--ant-color-primary)" }} showZero />
                                </Space>
                            }
                        />
                    </div>
                )}

                {/* ── Table ───────────────────────────────────────────── */}
                <div style={{ flex: 1, overflow: "auto", padding: "12px 24px 16px" }}>
                    <Table
                        columns={columns}
                        dataSource={tableData}
                        rowKey="date"
                        size="small"
                        pagination={false}
                        scroll={{ x: "max-content" }}
                        bordered
                        locale={{
                            emptyText: (
                                <div style={{ padding: "40px 0", textAlign: "center", opacity: 0.4 }}>
                                    <CalendarOutlined style={{ fontSize: 32, marginBottom: 8 }} />
                                    <div style={{ fontSize: 14 }}>
                                        No records found for {displayMonth || "selected period"}.
                                    </div>
                                </div>
                            ),
                        }}
                        onRow={() => ({ style: { fontSize: "0.8rem" } })}
                    />
                </div>
            </div>
        </div>
    );
}

// ── Main Export ───────────────────────────────────────────────────────────────

export default function DailyTimeRecord({ tableData = [], tableFilters = {} }) {
    const { theme } = useContext(ThemeContext);
    const isDark = theme === "dark";

    return (
        <AuthenticatedLayout>
            <ConfigProvider
                theme={{
                    algorithm: isDark ? darkAlgorithm : defaultAlgorithm,
                }}
            >
                <DailyTimeRecordInner
                    tableData={tableData}
                    tableFilters={tableFilters}
                    isDark={isDark}
                />
            </ConfigProvider>
        </AuthenticatedLayout>
    );
}