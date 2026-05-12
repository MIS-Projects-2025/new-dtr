import { useState, useEffect, useRef, Fragment } from "react";
import { usePage } from "@inertiajs/react"; 
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from "recharts";

const COL_HEADERS = ["Expected", "Present", "%", "Absent", "%"];

const ROW_LABELS = ["", "Scheduled Shift", "Unscheduled Shift", "Total"];

const ShiftCard = ({ title, cols = 7, headerCells, firstColSpan = 2, restCols = 5, rows = 5, rowLabels = ROW_LABELS, colHeaders = COL_HEADERS, rowData = {} }) => (
    <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 overflow-hidden min-h-0">
        <div className="px-3 py-1.5 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
            <h3 className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">
                {title}
            </h3>
        </div>
        <div
            className="flex-1 grid gap-0.5 p-1 min-h-0"
            style={{
                gridTemplateColumns: `repeat(${cols}, 1fr)`,
                gridTemplateRows: `repeat(${rows}, 1fr)`,
            }}
        >
            {headerCells.map(([span, label], i) => (
                <div
                    key={i}
                    className="flex items-center justify-center rounded bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 text-[9px] text-zinc-500 dark:text-zinc-400 overflow-hidden"
                    style={{ gridColumn: `span ${span}`, minHeight: "22px" }}
                >
                    {label}
                </div>
            ))}
            {Array.from({ length: 4 }).map((_, rowIndex) => (
                <Fragment key={`${title}-r${rowIndex}`}>
                    <div
                        key={`${title}-r${rowIndex}-c0`}
                        className="flex items-center justify-center rounded bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 text-[9px] text-zinc-500 dark:text-zinc-400 overflow-hidden px-1"
                        style={{ gridColumn: `span ${firstColSpan}`, minHeight: "22px" }}
                    >
                        {rowLabels[rowIndex] ?? ""}
                    </div>
                    {Array.from({ length: restCols }).map((_, colIndex) => {
                        let cellValue = "";
                        if (rowIndex === 0) {
                            cellValue = colHeaders[colIndex] ?? "";
                        } else if (rowIndex === 1) {
                            if (colIndex === 0) cellValue = rowData.scheduled_expected ?? "";
                            if (colIndex === 1) cellValue = rowData.scheduled_present  ?? "";
                            if (colIndex === 2) cellValue = rowData.scheduled_present_pct != null
                                ? `${rowData.scheduled_present_pct}%`
                                : "";
                            if (colIndex === 3) cellValue = rowData.scheduled_absent   ?? "";
                            if (colIndex === 4) cellValue = rowData.scheduled_absent_pct != null
                                ? `${rowData.scheduled_absent_pct}%`
                                : "";
                        } else if (rowIndex === 2) {
                            if (colIndex === 0) cellValue = rowData.unscheduled_expected ?? "";
                            if (colIndex === 1) cellValue = rowData.unscheduled_present  ?? "";
                            if (colIndex === 2) cellValue = rowData.unscheduled_present_pct != null
                                ? `${rowData.unscheduled_present_pct}%`
                                : "";
                        } else if (rowIndex === 3) {
                            if (colIndex === 0) cellValue = rowData.total_expected ?? "";
                            if (colIndex === 1) cellValue = rowData.total_present  ?? "";
                            if (colIndex === 2) cellValue = rowData.total_present_pct != null
                                ? `${rowData.total_present_pct}%`
                                : "";
                            if (colIndex === 3) cellValue = rowData.total_absent   ?? "";
                            if (colIndex === 4) cellValue = rowData.total_absent_pct != null
                                ? `${rowData.total_absent_pct}%`
                                : "";
                        }
                        return (
                            <div
                                key={`${title}-r${rowIndex}-c${colIndex + 1}`}
                                className="flex items-center justify-center rounded bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 text-[9px] text-zinc-500 dark:text-zinc-400 overflow-hidden px-0.5"
                                style={{ minHeight: "22px" }}
                            >
                                {cellValue}
                            </div>
                        );
                    })}
                </Fragment>
            ))}
        </div>
    </div>
);

// Unscheduled Employee List component
const UnscheduledEmployeeList = ({ employees = [], loading = false, holiday = null }) => {
    const PER_PAGE = 7;
    const [page, setPage] = useState(1);

    // Reset to page 1 whenever the employee list changes (date/filter change)
    useEffect(() => { setPage(1); }, [employees]);

    const lastPage   = Math.max(1, Math.ceil(employees.length / PER_PAGE));
    const pageSlice  = employees.slice((page - 1) * PER_PAGE, page * PER_PAGE);

    return (
        <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden h-full min-h-0">
            <div className="px-3 py-2 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between gap-2 flex-shrink-0">
                <p className="text-[10px] font-medium text-zinc-400 uppercase tracking-widest whitespace-nowrap">
                    Unscheduled Employee List ({employees.length})
                </p>
                {holiday && (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-semibold bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400 whitespace-nowrap">
                        🎉 {holiday.name}
                    </span>
                )}
            </div>
            <div className="flex-1 overflow-auto min-h-0">
                {loading ? (
                    <div className="flex items-center justify-center h-full text-zinc-400 dark:text-zinc-500 text-[10px]">
                        Loading...
                    </div>
                ) : employees.length === 0 ? (
                    <div className="flex items-center justify-center h-full text-zinc-400 dark:text-zinc-500 text-[10px]">
                        All employees have schedules for this date ✓
                    </div>
                ) : (
                    <table className="w-full text-left" style={{ fontSize: "clamp(8px, 0.8vw, 10px)" }}>
                        <colgroup>
                            <col style={{ width: "32%" }} />
                            <col style={{ width: "17%" }} />
                            <col style={{ width: "17%" }} />
                            <col style={{ width: "20%" }} />
                            <col style={{ width: "14%" }} />
                        </colgroup>
                        <thead className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                            <tr>
                                <th className="px-3 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Employee</th>
                                <th className="px-3 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Time In</th>
                                <th className="px-3 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Time Out</th>
                                <th className="px-3 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Shift Type</th>
                                <th className="px-3 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Remarks</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                            {pageSlice.map((emp, idx) => (
                                <tr key={idx} className="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <td className="px-3 py-1.5 truncate font-medium">
                                        {emp.EMPNAME}
                                        <div className="text-[8px] text-zinc-400">{emp.EMPLOYID}</div>
                                    </td>
                                    <td className="px-3 py-1.5 font-mono text-xs">
                                        {emp.TIME_IN !== '--:--' ? (
                                            <span className="text-green-600 dark:text-green-400">{emp.TIME_IN}</span>
                                        ) : (
                                            <span className="text-zinc-400">--:--</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-1.5 font-mono text-xs">
                                        {emp.TIME_OUT !== '--:--' ? (
                                            <span className="text-blue-600 dark:text-blue-400">{emp.TIME_OUT}</span>
                                        ) : (
                                            <span className="text-zinc-400">--:--</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-1.5">
                                        {emp.SHIFT_TYPE === 'Night Shift' ? (
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-semibold bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400">
                                                🌙 Night Shift
                                            </span>
                                        ) : emp.SHIFT_TYPE === 'Day Shift' ? (
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-semibold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400">
                                                ☀️ Day Shift
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-semibold bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400">
                                                ❓ Unknown
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-1.5">
                                        {emp.REMARKS === 'Present' ? (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400 whitespace-nowrap">
                                                Present
                                            </span>
                                        ) : emp.REMARKS === 'Holiday' ? (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400 whitespace-nowrap">
                                                🎉 Holiday
                                            </span>
                                        ) : emp.REMARKS === 'Absent' ? (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400 whitespace-nowrap">
                                                Absent
                                            </span>
                                        ) : (
                                            <span className="text-zinc-400">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Pagination footer — mirrors DTR style */}
            <div className="flex items-center justify-between px-3 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                <span className="text-[9px] text-zinc-400">
                    {employees.length} record(s) &nbsp;·&nbsp; Page {page} of {lastPage}
                </span>
                <div className="flex items-center gap-1">
                    <button
                        onClick={() => setPage(p => Math.max(1, p - 1))}
                        disabled={page <= 1}
                        className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        Prev
                    </button>
                    <button
                        onClick={() => setPage(p => Math.min(lastPage, p + 1))}
                        disabled={page >= lastPage}
                        className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    );
};

const DoughnutChart = ({ stats, loading }) => {
    const data = [
        { name: 'Present',                 value: stats?.present                   ?? 0, color: '#22c55e' },
        { name: 'Absent',                  value: stats?.absent                    ?? 0, color: '#ef4444' },
        { name: 'Rest Day',                value: stats?.rest_day                  ?? 0, color: '#a1a1aa' },
        { name: 'Unsched. Present (Day)',  value: stats?.unscheduled_present_day   ?? 0, color: '#a855f7' },
        { name: 'Unsched. Present (Night)',value: stats?.unscheduled_present_night ?? 0, color: '#6366f1' },
        { name: 'Unsched. Absent',         value: stats?.unscheduled_absent        ?? 0, color: '#f59e0b' },
    ].filter(d => d.value > 0);

    if (loading) {
        return (
            <div className="flex items-center justify-center w-full h-full text-[9px] text-zinc-400">
                Loading...
            </div>
        );
    }

    if (data.length === 0) {
        return (
            <div className="flex items-center justify-center w-full h-full text-[9px] text-zinc-400">
                No data
            </div>
        );
    }

    const total = data.reduce((sum, d) => sum + d.value, 0);

    const CustomTooltip = ({ active, payload }) => {
        if (!active || !payload?.length) return null;
        const { name, value } = payload[0].payload;
        const pct = total > 0 ? ((value / total) * 100).toFixed(2) : 0;
        return (
            <div className="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2.5 py-1.5 shadow-sm text-[10px]">
                <p className="font-medium text-zinc-700 dark:text-zinc-200">{name}</p>
                <p className="text-zinc-500 dark:text-zinc-400">{pct}%</p>
            </div>
        );
    };

    return (
        <div className="relative w-full h-full flex items-center justify-center" style={{ maxWidth: '160px', maxHeight: '160px', margin: '0 auto' }}>
            <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                    <Pie
                        data={data}
                        cx="50%"
                        cy="50%"
                        innerRadius="55%"
                        outerRadius="80%"
                        paddingAngle={2}
                        dataKey="value"
                        strokeWidth={0}
                    >
                        {data.map((entry, index) => (
                            <Cell key={`cell-${index}`} fill={entry.color} />
                        ))}
                    </Pie>
                    <Tooltip content={<CustomTooltip />} />
                </PieChart>
            </ResponsiveContainer>

            {/* Center total */}
            <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                <span className="text-[16px] font-semibold text-zinc-800 dark:text-zinc-100 leading-none">{total}</span>
                <span className="text-[8px] text-zinc-400 dark:text-zinc-500 mt-0.5">total</span>
            </div>
        </div>
    );
};

// Attendance Analytics component
const AttendanceAnalytics = ({ analyticsStats = null, analyticsLoading = false, selectedDate = '', onParamsChange }) => {
    const [viewMode, setViewMode] = useState('Daily');
    const [dailyDate, setDailyDate] = useState(() => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    });
    const [selectedMonth, setSelectedMonth] = useState(() => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    });
    const [selectedCutoff, setSelectedCutoff] = useState(() => {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        return `first-${y}-${m}`;
    });
    const [analyticsParams, setAnalyticsParams] = useState({
        mode: 'Daily',
        date: new Date().toISOString().split('T')[0],
        cutoff: '',
        month: `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}`,
    });

    useEffect(() => {
        setDailyDate(selectedDate);
        setAnalyticsParams(prev => ({ ...prev, date: selectedDate }));
    }, []);

    useEffect(() => {
        onParamsChange(analyticsParams);
    }, [analyticsParams]);

    const handleViewModeChange = (mode) => {
        setViewMode(mode);
        setAnalyticsParams(prev => ({
            ...prev,
            mode,
            date:   mode === 'Daily'       ? dailyDate      : prev.date,
            cutoff: mode === 'Per Cut Off' ? selectedCutoff : prev.cutoff,
            month:  mode === 'Monthly'     ? selectedMonth  : prev.month,
        }));
    };

    const handleDailyDateChange = (date) => {
        setDailyDate(date);
        setAnalyticsParams(prev => ({ ...prev, date }));
    };

    const handleMonthChange = (month) => {
        setSelectedMonth(month);
        setAnalyticsParams(prev => ({ ...prev, month }));
    };

    const handleCutoffChange = (cutoff) => {
        setSelectedCutoff(cutoff);
        setAnalyticsParams(prev => ({ ...prev, cutoff }));
    };

    const stats = [
        {
            label: 'Present',
            value: analyticsStats?.present ?? 0,
            color: 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400',
            dot: 'bg-green-500',
        },
        {
            label: 'Absent',
            value: analyticsStats?.absent ?? 0,
            color: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400',
            dot: 'bg-red-500',
        },
        {
            label: 'Rest Day',
            value: analyticsStats?.rest_day ?? 0,
            color: 'bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-300',
            dot: 'bg-zinc-400',
        },
        {
            label: 'Unsched. Present (Day)',
            value: analyticsStats?.unscheduled_present_day ?? 0,
            color: 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400',
            dot: 'bg-purple-500',
        },
        {
            label: 'Unsched. Present (Night)',
            value: analyticsStats?.unscheduled_present_night ?? 0,
            color: 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400',
            dot: 'bg-indigo-500',
        },
        {
            label: 'Unscheduled Absent',
            value: analyticsStats?.unscheduled_absent ?? 0,
            color: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
            dot: 'bg-amber-500',
        },
    ];

    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth();

    const cutoffOptions = (() => {
        const options = [];
        const start = new Date(2026, 0, 1);
        const current = new Date(year, month, 1);
        const d = new Date(start);
        while (d <= current) {
            const y = d.getFullYear();
            const m = d.getMonth();
            const shortLabel = d.toLocaleString('default', { month: 'short', year: 'numeric' });
            const nextMonth = new Date(y, m + 1, 1).toLocaleString('default', { month: 'short', year: 'numeric' });
            options.push({
                label: `7th – 21st ${shortLabel}`,
                value: `first-${y}-${String(m + 1).padStart(2, '0')}`,
            });
            options.push({
                label: `22nd – 6th ${nextMonth}`,
                value: `second-${y}-${String(m + 1).padStart(2, '0')}`,
            });
            d.setMonth(d.getMonth() + 1);
        }
        return options.reverse();
    })();

    const monthOptions = (() => {
        const options = [];
        const start = new Date(2026, 0, 1);
        const current = new Date(year, month, 1);
        const d = new Date(current);
        while (d >= start) {
            options.push({
                value: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`,
                label: d.toLocaleString('default', { month: 'long', year: 'numeric' }),
            });
            d.setMonth(d.getMonth() - 1);
        }
        return options;
    })();

    const addDays = (dateStr, n) => {
        const d = new Date(dateStr);
        d.setDate(d.getDate() + n);
        return d.toISOString().split('T')[0];
    };
    const getPeriodRange = () => {
        if (viewMode === 'Per Cut Off' && selectedCutoff) {
            const parts = selectedCutoff.split('-');
            const period = parts[0], y = +parts[1], mo = +parts[2];
            if (period === 'first') {
                return {
                    start: `${y}-${String(mo).padStart(2, '0')}-07`,
                    end:   `${y}-${String(mo).padStart(2, '0')}-21`,
                };
            } else {
                const nm = mo === 12 ? 1 : mo + 1;
                const ny = mo === 12 ? y + 1 : y;
                return {
                    start: `${y}-${String(mo).padStart(2, '0')}-22`,
                    end:   `${ny}-${String(nm).padStart(2, '0')}-06`,
                };
            }
        }
        if (viewMode === 'Monthly' && selectedMonth) {
            const [y, m] = selectedMonth.split('-').map(Number);
            const lastDay = new Date(y, m, 0).getDate();
            return {
                start: `${y}-${String(m).padStart(2, '0')}-01`,
                end:   `${y}-${String(m).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`,
            };
        }
        return null;
    };
    const fmtDate  = (str) => { const [y,m,d] = str.split('-'); return new Date(+y,+m-1,+d).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); };

    const periodRange = getPeriodRange();
    const today       = new Date().toISOString().split('T')[0];
    const periodDays  = periodRange
        ? (() => { const days = []; let cur = periodRange.start; while (cur <= periodRange.end) { days.push(cur); cur = addDays(cur, 1); } return days; })()
        : [];
    const countedDays = periodDays.filter(d => d <= today);
    const totalDays   = periodDays.length;
    const counted     = countedDays.length;
    const pct         = totalDays > 0 ? Math.round((counted / totalDays) * 100) : 0;
    const isComplete  = periodRange && periodRange.end < today;
    const isFuture    = periodRange && periodRange.start > today;

    return (
        <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden h-full min-h-0">

            <div className="px-4 py-2 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between gap-2 flex-shrink-0">
                <p className="text-xs font-semibold text-zinc-700 dark:text-zinc-200 whitespace-nowrap">
                    Attendance Analytics
                </p>
                {/* ── filters: no wrap, scroll if needed ── */}
                <div className="flex items-center gap-1.5 overflow-x-auto flex-nowrap min-w-0">
                    <div className="flex items-center rounded-md border border-zinc-200 dark:border-zinc-700 overflow-hidden flex-shrink-0">
                        {['Daily', 'Per Cut Off', 'Monthly'].map((mode) => (
                            <button
                                key={mode}
                                onClick={() => handleViewModeChange(mode)}
                                className={`px-2 py-1 text-[9px] font-medium transition-colors whitespace-nowrap
                                    ${viewMode === mode
                                        ? 'bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900'
                                        : 'bg-white dark:bg-zinc-900 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800'
                                    }`}
                            >
                                {mode}
                            </button>
                        ))}
                    </div>

                    {viewMode === 'Daily' && (
                        <input
                            type="date"
                            value={dailyDate}
                            onChange={(e) => handleDailyDateChange(e.target.value)}
                            className="flex-shrink-0 text-[9px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        />
                    )}

                    {viewMode === 'Per Cut Off' && (
                        <select
                            value={selectedCutoff}
                            onChange={(e) => handleCutoffChange(e.target.value)}
                            className="flex-shrink-0 text-[9px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        >
                            <option value="">Select Cut Off</option>
                            {cutoffOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                    )}

                    {viewMode === 'Monthly' && (
                        <select
                            value={selectedMonth}
                            onChange={(e) => handleMonthChange(e.target.value)}
                            className="flex-shrink-0 text-[9px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        >
                            {monthOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                    )}
                </div>
            </div>

            {/* Body */}
            <div className="flex-1 flex min-h-0 overflow-hidden">

                {/* LEFT: Stats list */}
                <div className="flex flex-col gap-1.5 p-3 border-r border-zinc-100 dark:border-zinc-800 flex-shrink-0 overflow-auto" style={{ width: '45%' }}>
                    {analyticsLoading ? (
                        <div className="flex items-center justify-center h-full text-[9px] text-zinc-400">Loading...</div>
                    ) : stats.map(({ label, value, color, dot }) => (
                        <div key={label} className="flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg border border-zinc-100 dark:border-zinc-800">
                            <div className="flex items-center gap-1.5">
                                <span className={`w-2 h-2 rounded-full flex-shrink-0 ${dot}`} />
                                <span className="text-[9px] text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{label}</span>
                            </div>
                            <span className={`text-[9px] font-bold px-1.5 py-0.5 rounded ${color}`}>{value}</span>
                        </div>
                    ))}
                </div>

                {/* RIGHT: Doughnut chart + progress bar */}
                <div className="flex-1 flex flex-col min-h-0 overflow-hidden">
                    <div className="flex-1 flex items-center justify-center p-2 min-h-0">
                        <DoughnutChart stats={analyticsStats} loading={analyticsLoading} />
                    </div>

                    {(viewMode === 'Per Cut Off' || viewMode === 'Monthly') && periodRange && (
                        <div className="mx-2 mb-2 rounded-lg border border-zinc-100 dark:border-zinc-800 p-2 flex flex-col gap-1.5 flex-shrink-0">
                            <div className="flex items-center justify-between">
                                <span className="text-[9px] font-medium text-zinc-500 dark:text-zinc-400">
                                    {isComplete ? 'Period complete' : isFuture ? 'Period not started' : 'Period progress'}
                                </span>
                                <span className="text-[9px] font-semibold text-zinc-700 dark:text-zinc-200">
                                    {counted} / {totalDays} days
                                </span>
                            </div>
                            <div className="h-1.5 rounded-full bg-zinc-100 dark:bg-zinc-700 overflow-hidden">
                                <div
                                    className="h-full rounded-full bg-zinc-700 dark:bg-zinc-300 transition-all duration-300"
                                    style={{ width: `${pct}%` }}
                                />
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-[8px] text-zinc-400 dark:text-zinc-500">{fmtDate(periodRange.start)}</span>
                                <span className="text-[8px] text-zinc-400 dark:text-zinc-500">{fmtDate(periodRange.end)}</span>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

const toMins = (t) => {
    if (!t || t === '--' || t === '--:--') return null;
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
};

const getTimeStatus = (actual, expected, type = 'in', isToday = false, nowMins = 0, nightShiftNextDayPending = false) => {
    const a = toMins(actual);
    const e = toMins(expected);
    if (a === null && e !== null) {
        if (nightShiftNextDayPending && e < 720) return 'pending';
        if (isToday && nowMins < e) return 'pending';
        if (isToday && nowMins >= e) return 'missing';
        return 'missing';
    }
    if (a === null || e === null) return 'neutral';
    let diff = a - e;
    if (Math.abs(diff) > 720) diff = diff > 0 ? diff - 1440 : diff + 1440;
    if (type === 'in')  return diff <= 0 ? 'on-time' : 'late';
    if (type === 'out') return diff >= 0 ? 'on-time' : 'early-out';
    return 'neutral';
};

const getBreakOutStatus = (actual, expected, isToday, nowMins, nightShiftNextDayPending = false) => {
    const a = toMins(actual);
    const e = toMins(expected);
    if (a === null && e !== null) {
        if (nightShiftNextDayPending && e < 720) return 'pending';
        if (isToday && nowMins < e) return 'pending';
        if (isToday && nowMins >= e) return 'missing';
        return 'missing';
    }
    if (a !== null) return 'on-time';
    return 'neutral';
};

const getBreakInStatus = (actualIn, actualOut, allowedMinutes, isToday, nowMins, expectedOut, nightShiftNextDayPending = false) => {
    const inMins       = toMins(actualIn);
    const outMins      = toMins(actualOut);
    const expectedMins = toMins(expectedOut);
    if (outMins === null) {
        if (expectedMins !== null) {
            if (nightShiftNextDayPending && expectedMins < 720) return 'pending';
            if (isToday && nowMins < expectedMins + allowedMinutes) return 'pending';
            if (isToday && nowMins >= expectedMins + allowedMinutes) return 'missing';
            return 'missing';
        }
        return 'neutral';
    }
    if (inMins === null) {
        if (nightShiftNextDayPending && outMins < 720) return 'pending';
        if (isToday && nowMins < outMins + allowedMinutes) return 'pending';
        if (isToday && nowMins >= outMins + allowedMinutes) return 'missing';
        return 'missing';
    }
    let duration = inMins - outMins;
    if (duration < 0) duration += 1440;
    return duration > allowedMinutes ? 'over-break' : 'on-time';
};

const TIME_CELL_CLS = {
    'on-time':    'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300',
    'late':       'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300',
    'early-out':  'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300',
    'over-break': 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300',
    'missing':    'bg-red-100   dark:bg-red-900/40   text-red-800   dark:text-red-300',
    'rest-day':   'bg-zinc-100  dark:bg-zinc-700/60  text-zinc-400  dark:text-zinc-500',
    'pending':    'bg-blue-100  dark:bg-blue-900/40  text-blue-800  dark:text-blue-300',
    'ob':         'bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-300',
    'neutral':    '',
};

const DailyTimeRecord = ({ rows = [], meta, page, onPageChange, loading, searchInput = '', onSearchChange, shiftFilter = '', onShiftFilterChange, statusFilter = '', onStatusFilterChange, selectedDate = '' }) => (
    <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden h-full min-h-0">
        <div className="px-4 py-2 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between gap-2 flex-shrink-0">
            <p className="text-[10px] font-medium text-zinc-400 uppercase tracking-widest whitespace-nowrap">
                Daily Time Record
            </p>
            {/* ── DTR filters: no wrap, scroll if needed ── */}
            <div className="flex items-center gap-2 overflow-x-auto flex-nowrap min-w-0">
                <select
                    value={shiftFilter}
                    onChange={(e) => onShiftFilterChange(e.target.value)}
                    className="flex-shrink-0 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                >
                    <option value="">All Shifts</option>
                    <option value="Day Shift">Day Shift</option>
                    <option value="Night Shift">Night Shift</option>
                </select>
                <select
                    value={statusFilter}
                    onChange={(e) => onStatusFilterChange(e.target.value)}
                    className="flex-shrink-0 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                >
                    <option value="">All Status</option>
                    <option value="Present">Present</option>
                    <option value="Absent">Absent</option>
                    <option value="Pending">Pending</option>
                    <option value="Late">Late</option>
                    <option value="On Leave">On Leave</option>
                    <option value="Rest Day">Rest Day</option>
                    <option value="On Leave (Present)">On Leave (Present)</option>
                    <option value="Holiday">Holiday</option>
                </select>
                <div className="relative flex-shrink-0">
                    <svg className="absolute left-2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 text-zinc-400 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
                    </svg>
                    <input
                        type="text"
                        placeholder="Search employee..."
                        value={searchInput}
                        onChange={(e) => onSearchChange(e.target.value)}
                        className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 pl-6 pr-3 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400 w-36"
                    />
                </div>
            </div>
        </div>

        <div className="flex-1 overflow-auto min-h-0">
            <table className="w-full text-left" style={{ tableLayout: "fixed", fontSize: "clamp(8px, 0.8vw, 10px)" }}>
                <colgroup>
                    <col style={{ width: "13%" }} />
                    <col style={{ width: "6%" }} />
                    <col style={{ width: "8%" }} />
                    <col style={{ width: "7%" }} />
                    <col style={{ width: "8%" }} />
                    <col style={{ width: "7%" }} />
                    <col style={{ width: "8%" }} />
                    <col style={{ width: "7%" }} />
                    <col style={{ width: "8%" }} />
                    <col style={{ width: "7%" }} />
                    <col style={{ width: "7%" }} />
                    <col style={{ width: "14%" }} />
                </colgroup>
                <thead className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        {["Employee","Shift Code","Shift Type","Time In","Break Out 1","Break In 1","Lunch Out","Lunch In","Break Out 2","Break In 2","Time Out","Remarks"].map((h) => (
                            <th key={h} className="px-2 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider truncate">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                    {loading ? (
                        <tr>
                            <td colSpan={12} className="px-3 py-4 text-center text-zinc-400 dark:text-zinc-500 italic">
                                Loading...
                            </td>
                        </tr>
                    ) : rows.length === 0 ? (
                        <tr>
                            <td colSpan={12} className="px-3 py-4 text-center text-zinc-400 dark:text-zinc-500 italic">
                                No records available.
                            </td>
                        </tr>
                    ) : (
                        rows.map((row) => (
                            <tr key={row.EMPLOYID} className="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td className="px-2 py-1.5 truncate">{row.EMPNAME}</td>
                                <td className="px-2 py-1.5 truncate">{row.SHIFTCODE}</td>
                                <td className="px-2 py-1.5 truncate">{row.SHIFT_TYPE}</td>
                                {(() => {
                                    const isOriginallyShifting  = row.SCHEDULE_TYPE === 'Shifting';
                                    const isShifting            = row.IS_SHIFTING ?? isOriginallyShifting;
                                    const wasEarlyOutOverride   = isOriginallyShifting && !isShifting;
                                    const effectiveTimeOutExpected = wasEarlyOutOverride
                                        ? row['Time Out (actual)']
                                        : row['Time Out (expected)'];
                                    const break2Mins            = isShifting ? 30 : 15;
                                    const hasNoLogs  = !toMins(row['Time In (actual)'])
                                                    && !toMins(row['Time Out (actual)'])
                                                    && !toMins(row['Break Out 1 (actual)'])
                                                    && !toMins(row['Lunch Out (actual)'])
                                                    && !toMins(row['Break Out 2 (actual)']);
                                    const isRestDayNoLog = row.IS_REST_DAY && hasNoLogs;
                                    const isOnLeaveNoLog = row.REMARKS === 'On Leave' && hasNoLogs;
                                    const obInfo         = row.OB_INFO ?? null;
                                    const today = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();
                                    const isToday      = selectedDate === today;
                                    const isNightShift = row.SHIFT_TYPE === 'Night Shift';
                                    const now          = new Date();
                                    const nowMins      = now.getHours() * 60 + now.getMinutes();
                                    const nightShiftNextDayPending = isNightShift && isToday;
                                    const toMinsLocal = (t) => {
                                        if (!t) return null;
                                        const [h, m] = t.split(':').map(Number);
                                        return h * 60 + m;
                                    };
                                    const isObCovered = (slotStart, slotEnd) => {
                                        if (!obInfo || !slotStart) return false;
                                        const obFrom  = toMinsLocal(obInfo.time_from);
                                        const obTo    = toMinsLocal(obInfo.time_to);
                                        const sMins   = toMinsLocal(slotStart);
                                        const eMins   = slotEnd ? toMinsLocal(slotEnd) : sMins;
                                        if (obFrom === null || obTo === null || sMins === null) return false;
                                        return sMins <= obTo && eMins >= obFrom;
                                    };
                                    const isHolidayNoLog = row.IS_HOLIDAY && hasNoLogs;
                                    const cls = (status, forceStatus, slotStart, slotEnd) => {
                                        if (isRestDayNoLog) return TIME_CELL_CLS['rest-day'];
                                        if (forceStatus === 'rest-day') return TIME_CELL_CLS['rest-day'];
                                        if (isHolidayNoLog) return TIME_CELL_CLS['pending'];
                                        if (forceStatus)    return TIME_CELL_CLS[forceStatus];
                                        if (isOnLeaveNoLog) return TIME_CELL_CLS['pending'];
                                        if (obInfo && isObCovered(slotStart, slotEnd)) return TIME_CELL_CLS['ob'];
                                        return TIME_CELL_CLS[status] ?? '';
                                    };
                                    return (
                                        <>
                                            <td className={`px-2 py-1.5 truncate ${cls(getTimeStatus(row['Time In (actual)'], row['Time In (expected)'], 'in', isToday, nowMins, nightShiftNextDayPending), undefined, row['Time In (expected)'], row['Break Out 1 (expected)'] || row['Lunch Out (expected)'])}`}>
                                                {row['Time In (actual)'] ?? '--'}
                                            </td>
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Break Out 1 (actual)'], row['Break Out 1 (expected)'], isToday, nowMins, nightShiftNextDayPending), isShifting ? 'rest-day' : undefined, row['Break Out 1 (expected)'], row['Break In 1 (expected)'])}`}>
                                                {isShifting ? 'N/A' : (row['Break Out 1 (actual)'] ?? '--')}
                                            </td>
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Break In 1 (actual)'], row['Break Out 1 (actual)'], 15, isToday, nowMins, row['Break Out 1 (expected)'], nightShiftNextDayPending), isShifting ? 'rest-day' : undefined, row['Break In 1 (expected)'], row['Lunch Out (expected)'])}`}>
                                                {isShifting ? 'N/A' : (row['Break In 1 (actual)'] ?? '--')}
                                            </td>
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Lunch Out (actual)'], row['Lunch Out (expected)'], isToday, nowMins, nightShiftNextDayPending), undefined, row['Lunch Out (expected)'], row['Lunch In (expected)'])}`}>
                                                {row['Lunch Out (actual)'] ?? '--'}
                                            </td>
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Lunch In (actual)'], row['Lunch Out (actual)'], 60, isToday, nowMins, row['Lunch Out (expected)'], nightShiftNextDayPending), undefined, row['Lunch In (expected)'], row['Break Out 2 (expected)'])}`}>
                                                {row['Lunch In (actual)'] ?? '--'}
                                            </td>
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Break Out 2 (actual)'], row['Break Out 2 (expected)'], isToday, nowMins, nightShiftNextDayPending), undefined, row['Break Out 2 (expected)'], row['Break In 2 (expected)'])}`}>
                                                {row['Break Out 2 (actual)'] ?? '--'}
                                            </td>
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Break In 2 (actual)'], row['Break Out 2 (actual)'], break2Mins, isToday, nowMins, row['Break Out 2 (expected)'], nightShiftNextDayPending), undefined, row['Break In 2 (expected)'], row['Time Out (expected)'])}`}>
                                                {row['Break In 2 (actual)'] ?? '--'}
                                            </td>
                                            <td className={`px-2 py-1.5 truncate ${cls(getTimeStatus(row['Time Out (actual)'], effectiveTimeOutExpected, 'out', isToday, nowMins, nightShiftNextDayPending), undefined, effectiveTimeOutExpected, effectiveTimeOutExpected)}`}>
                                                {row['Time Out (actual)'] ?? '--'}
                                            </td>
                                        </>
                                    );
                                })()}
                                <td className="px-2 py-1.5 truncate">
                                    {row.REMARKS === 'Present' ? (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400 whitespace-nowrap">Present</span>
                                    ) : row.REMARKS === 'Late' ? (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 whitespace-nowrap">Late</span>
                                    ) : row.REMARKS === 'Absent' ? (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400 whitespace-nowrap">Absent</span>
                                    ) : row.REMARKS === 'Rest Day' ? (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-300 whitespace-nowrap">Rest Day</span>
                                    ) : row.REMARKS === 'On Leave' ? (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400 whitespace-nowrap">On Leave</span>
                                    ) : row.REMARKS === 'On Leave (Present)' ? (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400 whitespace-nowrap">On Leave (Present)</span>
                                    ) : row.REMARKS === 'Pending' ? (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400 whitespace-nowrap">Pending</span>
                                    ) : row.REMARKS === 'Holiday' ? (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400 whitespace-nowrap">Holiday</span>
                                    ) : (
                                        <span className="text-zinc-400">—</span>
                                    )}
                                </td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
            <div className="flex items-center justify-between px-3 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                <span className="text-[9px] text-zinc-400">
                    {meta?.total ?? 0} record(s) shown
                    {(shiftFilter || statusFilter || searchInput) ? ' (filtered)' : ''}
                    &nbsp;·&nbsp; Page {page} of {meta?.last_page ?? 1}
                </span>
                <div className="flex items-center gap-1">
                    <button
                        onClick={() => onPageChange(page - 1)}
                        disabled={page <= 1}
                        className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        Prev
                    </button>
                    <button
                        onClick={() => onPageChange(page + 1)}
                        disabled={page >= (meta?.last_page ?? 1)}
                        className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </div>
);

export default function AdminDashboard({ emp_data }) {
    const { app_name } = usePage().props;

    const [filters, setFilters] = useState({ company: '', prodline: '', department: '', station: '' });
    const [filterOptions, setFilterOptions] = useState({ companies: [], prodlines: [], departments: [], stations: [] });
    const [selectedDate, setSelectedDate] = useState(() => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    });
    const [dtrRows, setDtrRows] = useState([]);
    const [dtrPage, setDtrPage] = useState(1);
    const [dtrMeta, setDtrMeta] = useState({ total: 0, last_page: 1, per_page: 15 });
    const [dtrLoading, setDtrLoading] = useState(false);
    const [searchInput, setSearchInput] = useState('');
    const [holiday, setHoliday] = useState(null);
    const [dtrSearch, setDtrSearch] = useState('');

    useEffect(() => {
        const timer = setTimeout(() => {
            setDtrSearch(searchInput);
            setDtrPage(1);
        }, 400);
        return () => clearTimeout(timer);
    }, [searchInput]);

    useEffect(() => {
        const obEmployees = dtrRows.filter(row => row.OB_INFO !== null && row.HAS_SCHEDULE);
        if (obEmployees.length === 0) {
            console.log('[OB/PB] No employees with OB/PB and a schedule found.');
            return;
        }
        console.group(`[OB/PB] ${obEmployees.length} employee(s) with OB/PB and a schedule:`);
        obEmployees.forEach(row => {
            console.group(`👤 ${row.EMPNAME} (${row.EMPLOYID})`);
            console.log('Shift Code    :', row.SHIFTCODE);
            console.log('Shift Type    :', row.SHIFT_TYPE);
            console.log('Remarks       :', row.REMARKS);
            console.log('OB/PB Type    :', row.OB_INFO?.type);
            console.log('Form Type     :', row.OB_INFO?.form_type);
            console.log('Time From     :', row.OB_INFO?.time_from);
            console.log('Time To       :', row.OB_INFO?.time_to);
            console.groupEnd();
        });
        console.groupEnd();
    }, [dtrRows]);

    const [dtrShiftFilter, setDtrShiftFilter] = useState('');
    const [dtrStatusFilter, setDtrStatusFilter] = useState('');
    const [unscheduledEmployees, setUnscheduledEmployees] = useState([]);
    const [unscheduledLoading, setUnscheduledLoading] = useState(false);
    const [shiftCounts, setShiftCounts] = useState({
        day_shift: 0, night_shift: 0, day_rest_day: 0, night_rest_day: 0,
        day_expected: 0, night_expected: 0, day_present: 0, night_present: 0,
        day_present_pct: 0, night_present_pct: 0, day_unscheduled_rd: 0,
        night_unscheduled_rd: 0, day_unscheduled_rd_present: 0,
        night_unscheduled_rd_present: 0, day_unscheduled_rd_pct: 0,
        night_unscheduled_rd_pct: 0, day_total_expected: 0, day_total_present: 0,
        day_total_absent: 0, day_total_present_pct: 0, day_total_absent_pct: 0,
        night_total_expected: 0, night_total_present: 0, night_total_absent: 0,
        night_total_present_pct: 0, night_total_absent_pct: 0,
    });
    const [countsLoading, setCountsLoading] = useState(false);
    const [analyticsStats, setAnalyticsStats] = useState(null);
    const [analyticsLoading, setAnalyticsLoading] = useState(false);
    const [analyticsParams, setAnalyticsParams] = useState(() => {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        return {
            mode: 'Daily',
            date: `${y}-${m}-${d}`,
            cutoff: `first-${y}-${m}`,
            month: `${y}-${m}`,
        };
    });
    const analyticsAbortRef = useRef(null);
    const analyticsTimeoutRef = useRef(null);

    useEffect(() => {
        fetch(`/${app_name}/dashboard/filtered-employees`)
            .then(res => res.json())
            .then(data => {
                if (data.filters) {
                    setFilterOptions({
                        companies: data.filters.companies || [],
                        prodlines: data.filters.prodlines || [],
                        departments: data.filters.departments || [],
                        stations: data.filters.stations || []
                    });
                }
            })
            .catch(err => console.error('Failed to fetch filter options:', err));
    }, []);

    const abortRef = useRef(null);
    const fetchTimeoutRef = useRef(null);

    useEffect(() => {
        if (fetchTimeoutRef.current) clearTimeout(fetchTimeoutRef.current);
        if (abortRef.current) abortRef.current.abort();

        setCountsLoading(true);
        setDtrLoading(true);
        setUnscheduledLoading(true);

        fetchTimeoutRef.current = setTimeout(() => {
            abortRef.current = new AbortController();

            const params = new URLSearchParams();
            Object.entries(filters).forEach(([k, v]) => { if (v) params.set(k, v); });
            params.set('date',          selectedDate);
            params.set('page',          dtrPage);
            params.set('search',        dtrSearch);
            params.set('shift_filter',  dtrShiftFilter);
            params.set('status_filter', dtrStatusFilter);

            fetch(`/${app_name}/dashboard/overview?${params.toString()}`, {
                signal: abortRef.current.signal,
            })
                .then(res => res.json())
                .then(data => {
                    setShiftCounts(data.shift_counts ?? {});
                    setDtrRows(data.dtr?.rows ?? []);
                    setDtrMeta({
                        total:     data.dtr?.total     ?? 0,
                        last_page: data.dtr?.last_page ?? 1,
                        per_page:  data.dtr?.per_page  ?? 15,
                    });
                    setUnscheduledEmployees(data.unscheduled ?? []);
                    setHoliday(data.holiday ?? null);
                })
                .catch(err => { if (err.name !== 'AbortError') console.error(err); })
                .finally(() => {
                    setCountsLoading(false);
                    setDtrLoading(false);
                    setUnscheduledLoading(false);
                });
        }, 300);

        return () => {
            if (fetchTimeoutRef.current) clearTimeout(fetchTimeoutRef.current);
            if (abortRef.current) abortRef.current.abort();
        };
    }, [filters, selectedDate, dtrPage, dtrSearch, dtrShiftFilter, dtrStatusFilter]);

    useEffect(() => {
        if (analyticsTimeoutRef.current) clearTimeout(analyticsTimeoutRef.current);
        if (analyticsAbortRef.current) analyticsAbortRef.current.abort();

        setAnalyticsLoading(true);

        analyticsTimeoutRef.current = setTimeout(() => {
            analyticsAbortRef.current = new AbortController();

            const params = new URLSearchParams();
            Object.entries(filters).forEach(([k, v]) => { if (v) params.set(k, v); });
            params.set('mode', analyticsParams.mode);
            params.set('date', analyticsParams.date);
            if (analyticsParams.mode === 'Per Cut Off') params.set('cutoff', analyticsParams.cutoff);
            if (analyticsParams.mode === 'Monthly') params.set('month', analyticsParams.month);

            fetch(`/${app_name}/dashboard/analytics-stats?${params.toString()}`, {
                signal: analyticsAbortRef.current.signal,
            })
                .then(res => res.json())
                .then(data => setAnalyticsStats(data))
                .catch(err => { if (err.name !== 'AbortError') console.error(err); })
                .finally(() => setAnalyticsLoading(false));
        }, 300);

        return () => {
            if (analyticsTimeoutRef.current) clearTimeout(analyticsTimeoutRef.current);
            if (analyticsAbortRef.current) analyticsAbortRef.current.abort();
        };
    }, [analyticsParams, filters]);

    const resetDataStates = () => {
        setShiftCounts({
            day_shift: 0, night_shift: 0, day_rest_day: 0, night_rest_day: 0,
            day_expected: 0, night_expected: 0, day_present: 0, night_present: 0,
            day_present_pct: 0, night_present_pct: 0, day_unscheduled_rd: 0,
            night_unscheduled_rd: 0, day_unscheduled_rd_present: 0,
            night_unscheduled_rd_present: 0, day_unscheduled_rd_pct: 0,
            night_unscheduled_rd_pct: 0, day_total_expected: 0, day_total_present: 0,
            day_total_absent: 0, day_total_present_pct: 0, day_total_absent_pct: 0,
            night_total_expected: 0, night_total_present: 0, night_total_absent: 0,
            night_total_present_pct: 0, night_total_absent_pct: 0,
        });
        setDtrRows([]);
        setUnscheduledEmployees([]);
    };

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    return (
        <div className="h-full overflow-auto">
            <div className="flex flex-col gap-3 p-3 min-w-[960px] min-h-full">

                {/* Top row: Overview (2/3) + Analytics (1/3) */}
                <div className="grid gap-3 flex-1 min-h-0" style={{ gridTemplateColumns: '2fr 1fr', minHeight: '260px' }}>

                    {/* Overview */}
                    <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden min-h-0">
                        <div className="px-4 py-2 border-b border-zinc-200 dark:border-zinc-700 flex items-center gap-2 flex-shrink-0">
                            <h2 className="text-xs font-semibold text-zinc-700 dark:text-zinc-200 whitespace-nowrap mr-auto">
                                Overview
                            </h2>
                            {/* ── Overview filters: no wrap, scroll if too many ── */}
                            <div className="flex items-center gap-2 overflow-x-auto flex-nowrap min-w-0">
                                <input
                                    type="date"
                                    value={selectedDate}
                                    onChange={(e) => {
                                        resetDataStates();
                                        setDtrPage(1);
                                        setSelectedDate(e.target.value);
                                    }}
                                    className="flex-shrink-0 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                                />
                                {[
                                    { key: 'company',    label: 'All Companies',   options: filterOptions.companies   },
                                    { key: 'prodline',   label: 'All Prodlines',   options: filterOptions.prodlines   },
                                    { key: 'department', label: 'All Departments', options: filterOptions.departments },
                                    { key: 'station',    label: 'All Stations',    options: filterOptions.stations    },
                                ].map(({ key, label, options }) => (
                                    <select
                                        key={key}
                                        value={filters[key]}
                                        onChange={(e) => handleFilterChange(key, e.target.value)}
                                        className="flex-shrink-0 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                                    >
                                        <option value="">{label}</option>
                                        {options.map(o => <option key={o} value={o}>{o}</option>)}
                                    </select>
                                ))}
                            </div>
                        </div>

                        {/* Shift cards */}
                        <div className="flex-1 min-h-0 p-2 overflow-auto">
                            <div className="grid grid-cols-2 gap-2 h-full">
                                <ShiftCard
                                    title="Day Shift"
                                    cols={7}
                                    headerCells={[["3", `Head Count: ${shiftCounts.day_shift}`], ["4", `On Rest Day: ${shiftCounts.day_rest_day}`]]}
                                    firstColSpan={2}
                                    restCols={5}
                                    rowData={{
                                        scheduled_expected:      shiftCounts.day_expected,
                                        scheduled_present:       shiftCounts.day_present,
                                        scheduled_present_pct:   shiftCounts.day_present_pct,
                                        scheduled_absent:        shiftCounts.day_total_absent,
                                        scheduled_absent_pct:    shiftCounts.day_total_absent_pct,
                                        unscheduled_expected:    shiftCounts.day_unscheduled_rd,
                                        unscheduled_present:     shiftCounts.day_unscheduled_rd_present,
                                        unscheduled_present_pct: shiftCounts.day_unscheduled_rd_pct,
                                        total_expected:          shiftCounts.day_total_expected,
                                        total_present:           shiftCounts.day_total_present,
                                        total_present_pct:       shiftCounts.day_total_present_pct,
                                        total_absent:            shiftCounts.day_total_absent,
                                        total_absent_pct:        shiftCounts.day_total_absent_pct,
                                    }}
                                />
                                <ShiftCard
                                    title="Night Shift"
                                    cols={7}
                                    headerCells={[["3", `Head Count: ${shiftCounts.night_shift}`], ["4", `On Rest Day: ${shiftCounts.night_rest_day}`]]}
                                    firstColSpan={2}
                                    restCols={5}
                                    rowData={{
                                        scheduled_expected:      shiftCounts.night_expected,
                                        scheduled_present:       shiftCounts.night_present,
                                        scheduled_present_pct:   shiftCounts.night_present_pct,
                                        scheduled_absent:        shiftCounts.night_total_absent,
                                        scheduled_absent_pct:    shiftCounts.night_total_absent_pct,
                                        unscheduled_expected:    shiftCounts.night_unscheduled_rd,
                                        unscheduled_present:     shiftCounts.night_unscheduled_rd_present,
                                        unscheduled_present_pct: shiftCounts.night_unscheduled_rd_pct,
                                        total_expected:          shiftCounts.night_total_expected,
                                        total_present:           shiftCounts.night_total_present,
                                        total_present_pct:       shiftCounts.night_total_present_pct,
                                        total_absent:            shiftCounts.night_total_absent,
                                        total_absent_pct:        shiftCounts.night_total_absent_pct,
                                    }}
                                />
                            </div>
                        </div>
                    </div>

                    {/* Attendance Analytics */}
                    <AttendanceAnalytics
                        analyticsStats={analyticsStats}
                        analyticsLoading={analyticsLoading}
                        selectedDate={selectedDate}
                        onParamsChange={setAnalyticsParams}
                    />
                </div>

                {/* Bottom row: DTR (2/3) + Unscheduled (1/3) */}
                <div className="grid gap-3 flex-[2] min-h-0" style={{ gridTemplateColumns: '2fr 1fr', minHeight: '300px' }}>
                    <DailyTimeRecord
                        rows={dtrRows}
                        meta={dtrMeta}
                        page={dtrPage}
                        onPageChange={setDtrPage}
                        loading={dtrLoading}
                        searchInput={searchInput}
                        onSearchChange={setSearchInput}
                        shiftFilter={dtrShiftFilter}
                        onShiftFilterChange={(v) => { setDtrShiftFilter(v); setDtrPage(1); }}
                        statusFilter={dtrStatusFilter}
                        onStatusFilterChange={(v) => { setDtrStatusFilter(v); setDtrPage(1); }}
                        selectedDate={selectedDate}
                    />
                    <UnscheduledEmployeeList
                        employees={unscheduledEmployees}
                        loading={unscheduledLoading}
                        holiday={holiday}
                    />
                </div>
            </div>
        </div>
    );
}