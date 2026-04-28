import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import { useState, useEffect, useRef } from "react";

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
        if (isToday) return 'pending';
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
        if (isToday) return 'pending';
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
            if (isToday) return 'pending';
            return 'missing';
        }
        return 'neutral';
    }
    if (inMins === null) {
        if (nightShiftNextDayPending && outMins < 720) return 'pending';
        if (isToday) return 'pending';
        return 'missing';
    }
    let duration = inMins - outMins;
    if (duration < 0) duration += 1440;
    return duration > allowedMinutes ? 'over-break' : 'on-time';
};

const TIME_CELL_CLS = {
    'on-time':    'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300',
    'late':       'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300',
    'early-out':  'bg-red-100   dark:bg-red-900/40   text-red-800   dark:text-red-300',
    'over-break': 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300',
    'missing':    'bg-red-100   dark:bg-red-900/40   text-red-800   dark:text-red-300',
    'rest-day':   'bg-zinc-100  dark:bg-zinc-700/60  text-zinc-400  dark:text-zinc-500',
    'pending':    'bg-blue-100  dark:bg-blue-900/40  text-blue-800  dark:text-blue-300',
    'ob':         'bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-300',
    'neutral':    '',
};

const REMARKS_CLS = {
    'Present':          'bg-green-100  dark:bg-green-900/40  text-green-700  dark:text-green-400',
    'Late':             'bg-amber-100  dark:bg-amber-900/40  text-amber-700  dark:text-amber-400',
    'Absent':           'bg-red-100    dark:bg-red-900/40    text-red-700    dark:text-red-400',
    'Rest Day':         'bg-zinc-100   dark:bg-zinc-700      text-zinc-500   dark:text-zinc-300',
    'On Leave':         'bg-blue-100   dark:bg-blue-900/40   text-blue-700   dark:text-blue-400',
    'On Leave (Present)':'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400',
    'Pending':          'bg-sky-100    dark:bg-sky-900/40    text-sky-700    dark:text-sky-400',
    'Holiday':          'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400',
};

export default function DailyTimeRecord({ emp_data, app_name }) {
    const now         = new Date();
    const defaultMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

    const [selectedMonth, setSelectedMonth]   = useState(defaultMonth);
    const [shiftFilter,   setShiftFilter]     = useState('');
    const [statusFilter,  setStatusFilter]    = useState('');
    const [rows,          setRows]            = useState([]);
    const [meta,          setMeta]            = useState({ total: 0, last_page: 1 });
    const [page,          setPage]            = useState(1);
    const [loading,       setLoading]         = useState(false);

    const abortRef   = useRef(null);
    const timeoutRef = useRef(null);

    // Month options from Jan 2026 to current month
    const monthOptions = (() => {
        const options = [];
        const start   = new Date(2026, 0, 1);
        const current = new Date(now.getFullYear(), now.getMonth(), 1);
        const d       = new Date(current);
        while (d >= start) {
            options.push({
                value: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`,
                label: d.toLocaleString('default', { month: 'long', year: 'numeric' }),
            });
            d.setMonth(d.getMonth() - 1);
        }
        return options;
    })();

    useEffect(() => {
        if (timeoutRef.current) clearTimeout(timeoutRef.current);
        if (abortRef.current)   abortRef.current.abort();

        setLoading(true);

        timeoutRef.current = setTimeout(() => {
            abortRef.current = new AbortController();

            const params = new URLSearchParams();
            params.set('month',         selectedMonth);
            params.set('page',          page);
            params.set('shift_filter',  shiftFilter);
            params.set('status_filter', statusFilter);

            fetch(`/${app_name}/daily-time-record/rows?${params.toString()}`, {
                signal: abortRef.current.signal,
            })
                .then(res => res.json())
                .then(data => {
                    setRows(data.rows ?? []);
                    setMeta({ total: data.total ?? 0, last_page: data.last_page ?? 1 });
                })
                .catch(err => { if (err.name !== 'AbortError') console.error(err); })
                .finally(() => setLoading(false));
        }, 300);

        return () => {
            if (timeoutRef.current) clearTimeout(timeoutRef.current);
            if (abortRef.current)   abortRef.current.abort();
        };
    }, [selectedMonth, page, shiftFilter, statusFilter]);

    const today = (() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();
const nowMins = now.getHours() * 60 + now.getMinutes();

    const fmtDate = (str) => {
        const [y, m, d] = str.split('-');
        return new Date(+y, +m - 1, +d).toLocaleDateString('en-US', {
            weekday: 'short', month: 'short', day: 'numeric',
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Daily Time Record" />

            <div className="flex flex-col h-full gap-3 p-4 overflow-hidden">

                {/* Header */}
                <div className="flex items-center justify-between gap-3 flex-wrap flex-shrink-0">
                    <h1 className="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                        Daily Time Record
                    </h1>
                    <div className="flex items-center gap-2 flex-wrap">
                        {/* Month filter */}
                        <select
                            value={selectedMonth}
                            onChange={(e) => { setSelectedMonth(e.target.value); setPage(1); }}
                            className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        >
                            {monthOptions.map(opt => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>

                        {/* Shift filter */}
                        <select
                            value={shiftFilter}
                            onChange={(e) => { setShiftFilter(e.target.value); setPage(1); }}
                            className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        >
                            <option value="">All Shifts</option>
                            <option value="Day Shift">Day Shift</option>
                            <option value="Night Shift">Night Shift</option>
                        </select>

                        {/* Status filter */}
                        <select
                            value={statusFilter}
                            onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
                            className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        >
                            <option value="">All Status</option>
                            <option value="Present">Present</option>
                            <option value="Absent">Absent</option>
                            <option value="Pending">Pending</option>
                            <option value="Late">Late</option>
                            <option value="On Leave">On Leave</option>
                            <option value="On Leave (Present)">On Leave (Present)</option>
                            <option value="Rest Day">Rest Day</option>
                            <option value="Holiday">Holiday</option>
                        </select>
                    </div>
                </div>

                {/* Table */}
                <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden flex-1 min-h-0">
                    <div className="flex-1 overflow-auto min-h-0">
                        <table className="w-full text-left" style={{ tableLayout: 'fixed', fontSize: 'clamp(8px, 0.8vw, 10px)' }}>
                            <colgroup>
                                <col style={{ width: '10%' }} />
                                <col style={{ width: '7%' }} />
                                <col style={{ width: '8%' }} />
                                <col style={{ width: '7%' }} />
                                <col style={{ width: '8%' }} />
                                <col style={{ width: '7%' }} />
                                <col style={{ width: '8%' }} />
                                <col style={{ width: '7%' }} />
                                <col style={{ width: '8%' }} />
                                <col style={{ width: '7%' }} />
                                <col style={{ width: '7%' }} />
                                <col style={{ width: '7%' }} />
                                <col style={{ width: '9%' }} />
                            </colgroup>
                            <thead className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    {['Date','Shift Code','Shift Type','Time In','Break Out 1','Break In 1','Lunch Out','Lunch In','Break Out 2','Break In 2','Time Out','Holiday','Remarks'].map(h => (
                                        <th key={h} className="px-2 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider truncate">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                                {loading ? (
                                    <tr>
                                        <td colSpan={13} className="px-3 py-6 text-center text-zinc-400 dark:text-zinc-500 italic text-[10px]">
                                            Loading...
                                        </td>
                                    </tr>
                                ) : rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={13} className="px-3 py-6 text-center text-zinc-400 dark:text-zinc-500 italic text-[10px]">
                                            No records found.
                                        </td>
                                    </tr>
                                ) : rows.map((row, idx) => {
                                    const isShifting            = row.IS_SHIFTING ?? (row.SCHEDULE_TYPE === 'Shifting');
                                    const break2Mins            = isShifting ? 30 : 15;
                                    const isToday               = row.DATE === today;
                                    const isNightShift          = row.SHIFT_TYPE === 'Night Shift';
                                    const nightShiftNextDayPending = isNightShift && isToday;
                                    const obInfo                = row.OB_INFO ?? null;
                                    const hasNoLogs             = !toMins(row['Time In (actual)'])
                                                               && !toMins(row['Time Out (actual)'])
                                                               && !toMins(row['Break Out 1 (actual)'])
                                                               && !toMins(row['Lunch Out (actual)'])
                                                               && !toMins(row['Break Out 2 (actual)']);
                                    const isRestDayNoLog        = row.IS_REST_DAY && hasNoLogs;
                                    const isOnLeaveNoLog        = row.REMARKS === 'On Leave' && hasNoLogs;

                                    const toMinsLocal = (t) => {
                                        if (!t) return null;
                                        const [h, m] = t.split(':').map(Number);
                                        return h * 60 + m;
                                    };
                                    const isObCovered = (slotStart, slotEnd) => {
                                        if (!obInfo || !slotStart) return false;
                                        const obFrom = toMinsLocal(obInfo.time_from);
                                        const obTo   = toMinsLocal(obInfo.time_to);
                                        const sMins  = toMinsLocal(slotStart);
                                        const eMins  = slotEnd ? toMinsLocal(slotEnd) : sMins;
                                        if (obFrom === null || obTo === null || sMins === null) return false;
                                        return sMins <= obTo && eMins >= obFrom;
                                    };
                                    const isHolidayNoLog = row.IS_HOLIDAY && hasNoLogs;

                                    const cls = (status, forceStatus, slotStart, slotEnd) => {
                                        if (isRestDayNoLog) return TIME_CELL_CLS['rest-day'];
                                        if (forceStatus === 'rest-day') return TIME_CELL_CLS['rest-day']; // disabled slots always gray
                                        if (isHolidayNoLog) return TIME_CELL_CLS['pending'];              // holiday + no logs = blue
                                        if (forceStatus)    return TIME_CELL_CLS[forceStatus];
                                        if (isOnLeaveNoLog) return TIME_CELL_CLS['pending'];
                                        if (obInfo && isObCovered(slotStart, slotEnd)) return TIME_CELL_CLS['ob'];
                                        return TIME_CELL_CLS[status] ?? '';
                                    };

                                    return (
                                        <tr key={idx} className="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                            {(() => {
                                                const isOriginallyShifting  = row.SCHEDULE_TYPE === 'Shifting';
                                                const isEffectivelyShifting = row.IS_SHIFTING ?? isOriginallyShifting;
                                                const wasEarlyOutOverride   = isOriginallyShifting && !isEffectivelyShifting;
                                                row._effectiveTimeOutExpected = wasEarlyOutOverride
                                                    ? row['Time Out (actual)']
                                                    : row['Time Out (expected)'];
                                            })()}
                                            {/* Date */}
                                            <td className="px-2 py-1.5 truncate font-medium text-zinc-700 dark:text-zinc-300">
                                                {fmtDate(row.DATE)}
                                            </td>
                                            {/* Shift Code */}
                                            <td className="px-2 py-1.5 truncate">{row.SHIFTCODE}</td>
                                            {/* Shift Type */}
                                            <td className="px-2 py-1.5 truncate">{row.SHIFT_TYPE}</td>
                                            {/* Time In */}
                                            <td className={`px-2 py-1.5 truncate ${cls(getTimeStatus(row['Time In (actual)'], row['Time In (expected)'], 'in', isToday, nowMins, nightShiftNextDayPending), undefined, row['Time In (expected)'], row['Break Out 1 (expected)'] || row['Lunch Out (expected)'])}`}>
                                                {row['Time In (actual)'] ?? '--'}
                                            </td>
                                            {/* Break Out 1 */}
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Break Out 1 (actual)'], row['Break Out 1 (expected)'], isToday, nowMins, nightShiftNextDayPending), isShifting ? 'rest-day' : undefined, row['Break Out 1 (expected)'], row['Break In 1 (expected)'])}`}>
                                                {isShifting ? 'N/A' : (row['Break Out 1 (actual)'] ?? '--')}
                                            </td>
                                            {/* Break In 1 */}
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Break In 1 (actual)'], row['Break Out 1 (actual)'], 15, isToday, nowMins, row['Break Out 1 (expected)'], nightShiftNextDayPending), isShifting ? 'rest-day' : undefined, row['Break In 1 (expected)'], row['Lunch Out (expected)'])}`}>
                                                {isShifting ? 'N/A' : (row['Break In 1 (actual)'] ?? '--')}
                                            </td>
                                            {/* Lunch Out */}
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Lunch Out (actual)'], row['Lunch Out (expected)'], isToday, nowMins, nightShiftNextDayPending), undefined, row['Lunch Out (expected)'], row['Lunch In (expected)'])}`}>
                                                {row['Lunch Out (actual)'] ?? '--'}
                                            </td>
                                            {/* Lunch In */}
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Lunch In (actual)'], row['Lunch Out (actual)'], 60, isToday, nowMins, row['Lunch Out (expected)'], nightShiftNextDayPending), undefined, row['Lunch In (expected)'], row['Break Out 2 (expected)'])}`}>
                                                {row['Lunch In (actual)'] ?? '--'}
                                            </td>
                                            {/* Break Out 2 */}
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Break Out 2 (actual)'], row['Break Out 2 (expected)'], isToday, nowMins, nightShiftNextDayPending), undefined, row['Break Out 2 (expected)'], row['Break In 2 (expected)'])}`}>
                                                {row['Break Out 2 (actual)'] ?? '--'}
                                            </td>
                                            {/* Break In 2 */}
                                            <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Break In 2 (actual)'], row['Break Out 2 (actual)'], break2Mins, isToday, nowMins, row['Break Out 2 (expected)'], nightShiftNextDayPending), undefined, row['Break In 2 (expected)'], row['Time Out (expected)'])}`}>
                                                {row['Break In 2 (actual)'] ?? '--'}
                                            </td>
                                            {/* Time Out */}
                                            <td className={`px-2 py-1.5 truncate ${cls(getTimeStatus(row['Time Out (actual)'], row._effectiveTimeOutExpected, 'out', isToday, nowMins, nightShiftNextDayPending), undefined, row._effectiveTimeOutExpected, row._effectiveTimeOutExpected)}`}>
                                                {row['Time Out (actual)'] ?? '--'}
                                            </td>
                                            {/* Holiday */}
                                            <td className="px-2 py-1.5 truncate">
                                                {row.HOLIDAY_NAME ? (
                                                    <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400 whitespace-nowrap">
                                                        {row.HOLIDAY_NAME}
                                                    </span>
                                                ) : (
                                                    <span className="text-zinc-300 dark:text-zinc-600">—</span>
                                                )}
                                            </td>
                                            {/* Remarks */}
                                            <td className="px-2 py-1.5 truncate">
                                                <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold whitespace-nowrap ${REMARKS_CLS[row.REMARKS] ?? 'text-zinc-400'}`}>
                                                    {row.REMARKS ?? '—'}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    <div className="flex items-center justify-between px-3 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                        <span className="text-[9px] text-zinc-400">
                            {meta.total} record(s) &nbsp;·&nbsp; Page {page} of {meta.last_page}
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
                                onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
                                disabled={page >= meta.last_page}
                                className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}