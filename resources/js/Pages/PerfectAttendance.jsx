import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import { Award, Users, CheckCircle, XCircle, TrendingUp, ChevronDown } from "lucide-react";
import { useState, useEffect, useRef, useCallback } from "react";

// ── Helpers ───────────────────────────────────────────────────────────────────

const toMins = (t) => {
    if (!t || t === '--' || t === '--:--') return null;
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
};

const getTimeStatus = (actual, expected, type = 'in', isToday = false, nowMins = 0, nightPending = false) => {
    const a = toMins(actual);
    const e = toMins(expected);
    if (a === null && e !== null) {
        if (nightPending && e < 720) return 'pending';
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

const getBreakOutStatus = (actual, expected, isToday, nowMins, nightPending = false) => {
    const a = toMins(actual);
    const e = toMins(expected);
    if (a === null && e !== null) {
        if (nightPending && e < 720) return 'pending';
        if (isToday) return 'pending';
        return 'missing';
    }
    return a !== null ? 'on-time' : 'neutral';
};

const getBreakInStatus = (actualIn, actualOut, allowed, isToday, nowMins, expectedOut, nightPending = false) => {
    const inMins  = toMins(actualIn);
    const outMins = toMins(actualOut);
    const expMins = toMins(expectedOut);
    if (outMins === null) {
        if (expMins !== null) {
            if (nightPending && expMins < 720) return 'pending';
            if (isToday) return 'pending';
            return 'missing';
        }
        return 'neutral';
    }
    if (inMins === null) {
        if (nightPending && outMins < 720) return 'pending';
        if (isToday) return 'pending';
        return 'missing';
    }
    let dur = inMins - outMins;
    if (dur < 0) dur += 1440;
    return dur > allowed ? 'over-break' : 'on-time';
};

const CELL_CLS = {
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

const REMARKS_CLS = {
    'Present':             'bg-green-100  dark:bg-green-900/40  text-green-700  dark:text-green-400',
    'Late':                'bg-amber-100  dark:bg-amber-900/40  text-amber-700  dark:text-amber-400',
    'Absent':              'bg-red-100    dark:bg-red-900/40    text-red-700    dark:text-red-400',
    'Rest Day':            'bg-zinc-100   dark:bg-zinc-700      text-zinc-500   dark:text-zinc-300',
    'On Leave':            'bg-blue-100   dark:bg-blue-900/40   text-blue-700   dark:text-blue-400',
    'On Leave (Present)':  'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400',
    'Pending':             'bg-sky-100    dark:bg-sky-900/40    text-sky-700    dark:text-sky-400',
    'Holiday':             'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400',
};

const fmtDate = (str) => {
    const [y, m, d] = str.split('-');
    return new Date(+y, +m - 1, +d).toLocaleDateString('en-US', {
        weekday: 'short', month: 'short', day: 'numeric',
    });
};

const todayStr = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// ── Month options (Jan 2026 → current) ───────────────────────────────────────
const buildMonthOptions = () => {
    const opts = [];
    const now  = new Date();
    const start = new Date(2026, 0, 1);
    const cur   = new Date(now.getFullYear(), now.getMonth(), 1);
    const d     = new Date(cur);
    while (d >= start) {
        opts.push({
            value: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`,
            label: d.toLocaleString('default', { month: 'long', year: 'numeric' }),
        });
        d.setMonth(d.getMonth() - 1);
    }
    return opts;
};

// ── Perfect Attendance Stats Panel ────────────────────────────────────────────

function PerfectAttendanceStats({ appName, onSelectEmployee }) {
    const now          = new Date();
    const defaultMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

    const [month,      setMonth]      = useState(defaultMonth);
    const [department, setDepartment] = useState('');
    const [station,    setStation]    = useState('');
    const [prodline,   setProdline]   = useState('');
    const [stats,      setStats]      = useState(null);
    const [loading,    setLoading]    = useState(false);
    const [filterOpts, setFilterOpts] = useState({ departments: [], stations: [], prodlines: [] });
    const [empSearch,  setEmpSearch]  = useState('');
    const abortRef  = useRef(null);
    const monthOpts = buildMonthOptions();

    // fetch filter options once on mount — independent of stats
    useEffect(() => {
        fetch(`/${appName}/perfect-attendance/filter-options`)
            .then(r => r.json())
            .then(data => setFilterOpts(data))
            .catch(console.error);
    }, [appName]);

    const fetchStats = useCallback(() => {
        if (abortRef.current) abortRef.current.abort();
        abortRef.current = new AbortController();
        setLoading(true);
        const params = new URLSearchParams({ month });
        if (department) params.set('department', department);
        if (station)    params.set('station',    station);
        if (prodline)   params.set('prodline',   prodline);

        fetch(`/${appName}/perfect-attendance/stats?${params}`, { signal: abortRef.current.signal })
            .then(r => r.json())
            .then(data => {
                setStats(data);
            })
            .catch(err => { if (err.name !== 'AbortError') console.error(err); })
            .finally(() => setLoading(false));
    }, [appName, month, department, station, prodline]);

    useEffect(() => { fetchStats(); }, [fetchStats]);

    const filteredEmps = (stats?.employees ?? []).filter(emp => {
        if (!empSearch) return true;
        const s = empSearch.toLowerCase();
        return emp.EMPNAME.toLowerCase().includes(s) || emp.EMPLOYID.toLowerCase().includes(s);
    });

    const pct          = stats?.perfect_attendance_pct ?? 0;
    const circumference = 2 * Math.PI * 36;
    const offset        = circumference - (pct / 100) * circumference;

    return (
        <div className="flex flex-col h-full min-h-0 overflow-hidden">

            {/* ── Top bar: filters + summary ── */}
            <div className="flex-shrink-0 space-y-3 pb-3 border-b border-zinc-100 dark:border-zinc-800 mb-3">
            <div className="flex items-center gap-2 flex-wrap">
                <select
                    value={month}
                    onChange={e => setMonth(e.target.value)}
                    className="text-[11px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                >
                    {monthOpts.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
                {[
                    { label: 'Department', value: department, setter: setDepartment, options: filterOpts.departments },
                    { label: 'Station',    value: station,    setter: setStation,    options: filterOpts.stations },
                    { label: 'Prodline',   value: prodline,   setter: setProdline,   options: filterOpts.prodlines },
                ].map(({ label, value, setter, options }) => (
                    <select
                        key={label}
                        value={value}
                        onChange={e => setter(e.target.value)}
                        className="text-[11px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    >
                        <option value="">{label}</option>
                        {(options ?? []).map(o => <option key={o} value={o}>{o}</option>)}
                    </select>
                ))}
            </div>

            {/* Summary Cards */}
            <div className="flex-shrink-0">
                {loading ? (
                    <div className="animate-pulse flex items-center gap-4">
                        <div className="w-[84px] h-[84px] rounded-full bg-zinc-100 dark:bg-zinc-700 flex-shrink-0" />
                        <div className="flex gap-3">
                            {[...Array(3)].map((_, i) => (
                                <div key={i} className="w-[100px] h-[84px] rounded-lg bg-zinc-100 dark:bg-zinc-700" />
                            ))}
                        </div>
                    </div>
                ) : stats ? (
                    <div className="flex items-center gap-4 flex-wrap">
                        {/* Donut */}
                        <div className="relative flex-shrink-0">
                            <svg width="84" height="84" viewBox="0 0 84 84">
                                <circle cx="42" cy="42" r="36" fill="none" stroke="currentColor"
                                    className="text-zinc-100 dark:text-zinc-700" strokeWidth="8" />
                                <circle cx="42" cy="42" r="36" fill="none" stroke="currentColor"
                                    className="text-green-500 dark:text-green-400 transition-all duration-700"
                                    strokeWidth="8"
                                    strokeDasharray={circumference}
                                    strokeDashoffset={offset}
                                    strokeLinecap="round"
                                    transform="rotate(-90 42 42)"
                                />
                            </svg>
                            <div className="absolute inset-0 flex items-center justify-center">
                                <span className="text-[13px] font-bold text-zinc-700 dark:text-zinc-200">{pct}%</span>
                            </div>
                        </div>

                        {/* Stat cards */}
                        <div className="flex gap-2 flex-wrap">
                            <div className="flex flex-col px-4 py-3 rounded-lg bg-zinc-50 dark:bg-zinc-800 border border-zinc-100 dark:border-zinc-700 min-w-[90px]">
                                <span className="text-[9px] uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-0.5">Total</span>
                                <span className="text-[22px] font-bold text-zinc-700 dark:text-zinc-200 leading-tight">{stats.total_employees}</span>
                                <span className="text-[9px] text-zinc-400 dark:text-zinc-500">employees</span>
                            </div>
                            <div className="flex flex-col px-4 py-3 rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-100 dark:border-green-800/40 min-w-[90px]">
                                <span className="text-[9px] uppercase tracking-wider text-green-600 dark:text-green-400 mb-0.5">Perfect</span>
                                <span className="text-[22px] font-bold text-green-600 dark:text-green-400 leading-tight">{stats.perfect_attendance}</span>
                                <span className="text-[9px] text-green-500 dark:text-green-500">no issues</span>
                            </div>
                            <div className="flex flex-col px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-100 dark:border-red-800/40 min-w-[90px]">
                                <span className="text-[9px] uppercase tracking-wider text-red-500 dark:text-red-400 mb-0.5">Not Perfect</span>
                                <span className="text-[22px] font-bold text-red-500 dark:text-red-400 leading-tight">{stats.with_absences}</span>
                                <span className="text-[9px] text-red-400 dark:text-red-500">w/ issues</span>
                            </div>
                        </div>
                    </div>
                ) : null}
            </div>

            </div>

            {/* ── Employee list ── */}
            <div className="flex flex-col flex-1 min-h-0 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 overflow-hidden">
                {/* List header + search */}
                <div className="flex items-center justify-between gap-3 px-3 py-2 border-b border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                    <div className="flex items-center gap-1.5">
                        <CheckCircle size={12} className="text-green-500" />
                        <span className="text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
                            Perfect Attendance
                        </span>
                        <span className="text-[10px] text-zinc-400 dark:text-zinc-500">
                            — {filteredEmps.length} employee{filteredEmps.length !== 1 ? 's' : ''}
                        </span>
                    </div>
                    <div className="relative w-48">
                        <svg className="absolute left-2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 text-zinc-400 pointer-events-none"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                        </svg>
                        <input
                            type="text"
                            value={empSearch}
                            onChange={e => setEmpSearch(e.target.value)}
                            placeholder="Search..."
                            className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 pl-6 pr-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        />
                    </div>
                </div>

                {/* Table */}
                <div className="flex-1 overflow-auto min-h-0">
                    <table className="w-full text-left" style={{ tableLayout: 'fixed', fontSize: '11px' }}>
                        <colgroup>
                            <col style={{ width: '30%' }} />
                            <col style={{ width: '15%' }} />
                            <col style={{ width: '20%' }} />
                            <col style={{ width: '10%' }} />
                            <col style={{ width: '10%' }} />
                            <col style={{ width: '15%' }} />
                        </colgroup>
                        <thead className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                            <tr>
                                {['Name', 'Employee ID', 'Department', 'Working Days', 'Present', 'On Leave'].map(h => (
                                    <th key={h} className="px-3 py-2 font-semibold text-[9px] text-zinc-500 dark:text-zinc-400 uppercase tracking-wider truncate">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                            {loading ? (
                                [...Array(8)].map((_, i) => (
                                    <tr key={i} className="animate-pulse">
                                        <td className="px-3 py-2.5">
                                            <div className="flex items-center gap-2">
                                                <div className="w-1.5 h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700 flex-shrink-0" />
                                                <div className="h-2.5 rounded bg-zinc-100 dark:bg-zinc-700" style={{ width: `${60 + (i % 3) * 20}px` }} />
                                            </div>
                                        </td>
                                        <td className="px-3 py-2.5"><div className="h-2.5 w-16 rounded bg-zinc-100 dark:bg-zinc-700" /></td>
                                        <td className="px-3 py-2.5"><div className="h-2.5 w-20 rounded bg-zinc-100 dark:bg-zinc-700" /></td>
                                        <td className="px-3 py-2.5"><div className="h-2.5 w-6 rounded bg-zinc-100 dark:bg-zinc-700 mx-auto" /></td>
                                        <td className="px-3 py-2.5"><div className="h-2.5 w-6 rounded bg-zinc-100 dark:bg-zinc-700 mx-auto" /></td>
                                        <td className="px-3 py-2.5"><div className="h-2.5 w-4 rounded bg-zinc-100 dark:bg-zinc-700 mx-auto" /></td>
                                    </tr>
                                ))
                            ) : filteredEmps.length === 0 ? (
                                <tr><td colSpan={6} className="px-3 py-8 text-center text-[11px] text-zinc-400 italic">
                                    {stats?.employees?.length === 0 ? 'No employees with perfect attendance.' : 'No results.'}
                                </td></tr>
                            ) : filteredEmps.map(emp => (
                                <tr
                                    key={emp.EMPLOYID}
                                    onClick={() => onSelectEmployee({ EMPLOYID: emp.EMPLOYID, EMPNAME: emp.EMPNAME, DEPARTMENT: emp.DEPARTMENT })}
                                    className="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 cursor-pointer group"
                                >
                                    <td className="px-3 py-2 truncate">
                                        <div className="flex items-center gap-2">
                                            <span className="w-1.5 h-1.5 rounded-full bg-green-400 flex-shrink-0" />
                                            <span className="font-medium text-zinc-700 dark:text-zinc-200 group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors truncate">
                                                {emp.EMPNAME}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 text-zinc-500 dark:text-zinc-400 truncate">{emp.EMPLOYID}</td>
                                    <td className="px-3 py-2 text-zinc-500 dark:text-zinc-400 truncate">{emp.DEPARTMENT}</td>
                                    <td className="px-3 py-2 text-zinc-600 dark:text-zinc-300 text-center">{emp.working_days}</td>
                                    <td className="px-3 py-2 text-center">
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400">
                                            {emp.present}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-center">
                                        {emp.on_leave > 0
                                            ? <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400">{emp.on_leave}</span>
                                            : <span className="text-zinc-300 dark:text-zinc-600">—</span>
                                        }
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

// ── Employee sidebar ──────────────────────────────────────────────────────────

function EmployeeSidebar({ appName, selected, onSelect, onShowStats, showingStats }) {
    const [search,    setSearch]    = useState('');
    const [employees, setEmployees] = useState([]);
    const [loading,   setLoading]   = useState(false);
    const debounceRef = useRef(null);

    const fetchEmployees = useCallback((q) => {
        setLoading(true);
        const params = new URLSearchParams();
        if (q) params.set('search', q);
        fetch(`/${appName}/perfect-attendance/employees?${params}`)
            .then(r => r.json())
            .then(data => setEmployees(Array.isArray(data) ? data : []))
            .catch(console.error)
            .finally(() => setLoading(false));
    }, [appName]);

    useEffect(() => { fetchEmployees(''); }, [fetchEmployees]);

    const handleSearch = (e) => {
        const val = e.target.value;
        setSearch(val);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => fetchEmployees(val), 300);
    };

    return (
        <div className="flex flex-col h-full overflow-hidden">
            {/* Search */}
            <div className="p-2 border-b border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                <div className="relative">
                    <svg className="absolute left-2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 text-zinc-400 pointer-events-none"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                              d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                    </svg>
                    <input
                        type="text"
                        value={search}
                        onChange={handleSearch}
                        placeholder="Search employee..."
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 pl-6 pr-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
            </div>

            <div className="flex-1 overflow-y-auto">
                {/* Stats button — always first */}
                <button
                    onClick={onShowStats}
                    className={`w-full text-left px-3 py-2 border-b border-zinc-50 dark:border-zinc-800 transition-colors
                        ${showingStats
                            ? 'bg-zinc-100 dark:bg-zinc-800 border-l-2 border-l-zinc-700 dark:border-l-zinc-300'
                            : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/60'
                        }`}
                >
                    <div className="flex items-center gap-1.5">
                        <TrendingUp size={11} className="text-green-500 flex-shrink-0" />
                        <span className="text-[11px] font-medium text-zinc-700 dark:text-zinc-200">Stats</span>
                    </div>
                    <div className="text-[9px] text-zinc-400 dark:text-zinc-500 pl-5">Perfect attendance</div>
                </button>

                {/* Employee list */}
                {loading ? (
                    <div className="px-3 py-4 text-center text-[10px] text-zinc-400">Loading...</div>
                ) : employees.length === 0 ? (
                    <div className="px-3 py-4 text-center text-[10px] text-zinc-400 italic">No employees found.</div>
                ) : employees.map((emp) => {
                    const isActive = !showingStats && selected?.EMPLOYID === emp.EMPLOYID;
                    return (
                        <button
                            key={emp.EMPLOYID}
                            onClick={() => onSelect(emp)}
                            className={`w-full text-left px-3 py-2 border-b border-zinc-50 dark:border-zinc-800 last:border-0 transition-colors
                                ${isActive
                                    ? 'bg-zinc-100 dark:bg-zinc-800 border-l-2 border-l-zinc-700 dark:border-l-zinc-300'
                                    : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/60'
                                }`}
                        >
                            <div className="text-[11px] font-medium text-zinc-700 dark:text-zinc-200 truncate">
                                {emp.EMPNAME}
                            </div>
                            <div className="text-[9px] text-zinc-400 dark:text-zinc-500 truncate">
                                {emp.EMPLOYID} · {emp.DEPARTMENT}
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

// ── DTR table ─────────────────────────────────────────────────────────────────

function DtrTable({ appName, employee }) {
    const now = new Date();
    const defaultMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

    const [month,        setMonth]        = useState(defaultMonth);
    const [shiftFilter,  setShiftFilter]  = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [rows,         setRows]         = useState([]);
    const [meta,         setMeta]         = useState({ total: 0, last_page: 1 });
    const [page,         setPage]         = useState(1);
    const [loading,      setLoading]      = useState(false);
    const abortRef = useRef(null);

    const monthOptions = buildMonthOptions();

    useEffect(() => {
        if (!employee) { setRows([]); return; }
        if (abortRef.current) abortRef.current.abort();
        abortRef.current = new AbortController();
        setLoading(true);
        const params = new URLSearchParams({
            employ_id:     employee.EMPLOYID,
            month,
            page,
            shift_filter:  shiftFilter,
            status_filter: statusFilter,
        });
        fetch(`/${appName}/perfect-attendance/dtr-rows?${params}`, { signal: abortRef.current.signal })
            .then(r => r.json())
            .then(data => {
                setRows(data.rows ?? []);
                setMeta({ total: data.total ?? 0, last_page: data.last_page ?? 1 });
            })
            .catch(err => { if (err.name !== 'AbortError') console.error(err); })
            .finally(() => setLoading(false));
        return () => abortRef.current?.abort();
    }, [employee, month, page, shiftFilter, statusFilter, appName]);

    const handleMonth        = (v) => { setMonth(v);        setPage(1); };
    const handleShiftFilter  = (v) => { setShiftFilter(v);  setPage(1); };
    const handleStatusFilter = (v) => { setStatusFilter(v); setPage(1); };

    const today   = todayStr();
    const nowMins = now.getHours() * 60 + now.getMinutes();

    if (!employee) {
        return (
            <div className="flex flex-col items-center justify-center h-full gap-2 text-zinc-300 dark:text-zinc-600">
                <Award size={36} />
                <p className="text-[11px]">Select an employee to view their record.</p>
                <p className="text-[10px] text-zinc-400 dark:text-zinc-600">Use the Stats panel to find perfect attendance employees,<br/>or Browse to search all employees.</p>
            </div>
        );
    }

    return (
        <div className="flex flex-col h-full gap-3">
            {/* Employee banner */}
            <div className="flex items-center justify-between gap-3 flex-wrap flex-shrink-0">
                <div>
                    <p className="text-[12px] font-semibold text-zinc-700 dark:text-zinc-200">
                        {employee.EMPNAME}
                    </p>
                    <p className="text-[10px] text-zinc-400 dark:text-zinc-500">
                        {employee.EMPLOYID} · {employee.DEPARTMENT}
                    </p>
                </div>
                <div className="flex items-center gap-1.5 flex-wrap">
                    <select
                        value={month}
                        onChange={(e) => handleMonth(e.target.value)}
                        className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    >
                        {monthOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                    <select
                        value={shiftFilter}
                        onChange={(e) => handleShiftFilter(e.target.value)}
                        className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    >
                        <option value="">All Shifts</option>
                        <option value="Day Shift">Day Shift</option>
                        <option value="Night Shift">Night Shift</option>
                    </select>
                    <select
                        value={statusFilter}
                        onChange={(e) => handleStatusFilter(e.target.value)}
                        className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    >
                        <option value="">All Status</option>
                        <option value="Present">Present</option>
                        <option value="Late">Late</option>
                        <option value="Absent">Absent</option>
                        <option value="Pending">Pending</option>
                        <option value="On Leave">On Leave</option>
                        <option value="On Leave (Present)">On Leave (Present)</option>
                        <option value="Rest Day">Rest Day</option>
                        <option value="Holiday">Holiday</option>
                    </select>
                </div>
            </div>

            {/* Table */}
            <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 overflow-hidden flex-1 min-h-0">
                <div className="flex-1 overflow-auto min-h-0">
                    <table className="w-full text-left" style={{ tableLayout: 'fixed', fontSize: 'clamp(8px, 0.8vw, 10px)' }}>
                        <colgroup>
                            <col style={{ width: '10%' }} />
                            <col style={{ width: '7%' }}  />
                            <col style={{ width: '8%' }}  />
                            <col style={{ width: '7%' }}  />
                            <col style={{ width: '8%' }}  />
                            <col style={{ width: '7%' }}  />
                            <col style={{ width: '8%' }}  />
                            <col style={{ width: '7%' }}  />
                            <col style={{ width: '8%' }}  />
                            <col style={{ width: '7%' }}  />
                            <col style={{ width: '7%' }}  />
                            <col style={{ width: '7%' }}  />
                            <col style={{ width: '9%' }}  />
                        </colgroup>
                        <thead className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                            <tr>
                                {['Date','Shift Code','Shift Type','Time In','Break Out 1','Break In 1',
                                  'Lunch Out','Lunch In','Break Out 2','Break In 2','Time Out','Holiday','Remarks'].map(h => (
                                    <th key={h} className="px-2 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider truncate">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                            {loading ? (
                                <tr><td colSpan={13} className="px-3 py-6 text-center text-zinc-400 dark:text-zinc-500 italic text-[10px]">Loading...</td></tr>
                            ) : rows.length === 0 ? (
                                <tr><td colSpan={13} className="px-3 py-6 text-center text-zinc-400 dark:text-zinc-500 italic text-[10px]">No records found.</td></tr>
                            ) : rows.map((row, idx) => {
                                const isShifting   = row.IS_SHIFTING ?? (row.SCHEDULE_TYPE === 'Shifting');
                                const break2Mins   = isShifting ? 30 : 15;
                                const isToday      = row.DATE === today;
                                const isNightShift = row.SHIFT_TYPE === 'Night Shift';
                                const nightPending = isNightShift && isToday;
                                const obInfo       = row.OB_INFO ?? null;

                                const hasNoLogs =
                                    !toMins(row['Time In (actual)'])  &&
                                    !toMins(row['Time Out (actual)']) &&
                                    !toMins(row['Break Out 1 (actual)']) &&
                                    !toMins(row['Lunch Out (actual)'])   &&
                                    !toMins(row['Break Out 2 (actual)']);

                                const isRestDayNoLog  = row.IS_REST_DAY && hasNoLogs;
                                const isOnLeaveNoLog  = row.REMARKS === 'On Leave' && hasNoLogs;
                                const isHolidayNoLog  = row.IS_HOLIDAY && hasNoLogs;

                                const toMinsL = (t) => { if (!t) return null; const [h,m] = t.split(':').map(Number); return h*60+m; };
                                const isObCovered = (s, e) => {
                                    if (!obInfo || !s) return false;
                                    const of = toMinsL(obInfo.time_from), ot = toMinsL(obInfo.time_to);
                                    const sm = toMinsL(s), em = e ? toMinsL(e) : sm;
                                    if (of === null || ot === null || sm === null) return false;
                                    return sm <= ot && em >= of;
                                };

                                const cls = (status, force, slotStart, slotEnd) => {
                                    if (isRestDayNoLog)        return CELL_CLS['rest-day'];
                                    if (force === 'rest-day')  return CELL_CLS['rest-day'];
                                    if (isHolidayNoLog)        return CELL_CLS['pending'];
                                    if (force)                 return CELL_CLS[force];
                                    if (isOnLeaveNoLog)        return CELL_CLS['pending'];
                                    if (obInfo && isObCovered(slotStart, slotEnd)) return CELL_CLS['ob'];
                                    return CELL_CLS[status] ?? '';
                                };

                                const isOrigShifting      = row.SCHEDULE_TYPE === 'Shifting';
                                const wasEarlyOutOverride = isOrigShifting && !isShifting;
                                const effectiveTimeOutExp = wasEarlyOutOverride
                                    ? row['Time Out (actual)']
                                    : row['Time Out (expected)'];

                                return (
                                    <tr key={idx} className="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                        <td className="px-2 py-1.5 truncate font-medium text-zinc-700 dark:text-zinc-300">{fmtDate(row.DATE)}</td>
                                        <td className="px-2 py-1.5 truncate">{row.SHIFTCODE}</td>
                                        <td className="px-2 py-1.5 truncate">{row.SHIFT_TYPE}</td>
                                        <td className={`px-2 py-1.5 truncate ${cls(getTimeStatus(row['Time In (actual)'], row['Time In (expected)'], 'in', isToday, nowMins, nightPending), undefined, row['Time In (expected)'], row['Break Out 1 (expected)'] || row['Lunch Out (expected)'])}`}>
                                            {row['Time In (actual)'] ?? '--'}
                                        </td>
                                        <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Break Out 1 (actual)'], row['Break Out 1 (expected)'], isToday, nowMins, nightPending), isShifting ? 'rest-day' : undefined, row['Break Out 1 (expected)'], row['Break In 1 (expected)'])}`}>
                                            {isShifting ? 'N/A' : (row['Break Out 1 (actual)'] ?? '--')}
                                        </td>
                                        <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Break In 1 (actual)'], row['Break Out 1 (actual)'], 15, isToday, nowMins, row['Break Out 1 (expected)'], nightPending), isShifting ? 'rest-day' : undefined, row['Break In 1 (expected)'], row['Lunch Out (expected)'])}`}>
                                            {isShifting ? 'N/A' : (row['Break In 1 (actual)'] ?? '--')}
                                        </td>
                                        <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Lunch Out (actual)'], row['Lunch Out (expected)'], isToday, nowMins, nightPending), undefined, row['Lunch Out (expected)'], row['Lunch In (expected)'])}`}>
                                            {row['Lunch Out (actual)'] ?? '--'}
                                        </td>
                                        <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Lunch In (actual)'], row['Lunch Out (actual)'], 60, isToday, nowMins, row['Lunch Out (expected)'], nightPending), undefined, row['Lunch In (expected)'], row['Break Out 2 (expected)'])}`}>
                                            {row['Lunch In (actual)'] ?? '--'}
                                        </td>
                                        <td className={`px-2 py-1.5 truncate ${cls(getBreakOutStatus(row['Break Out 2 (actual)'], row['Break Out 2 (expected)'], isToday, nowMins, nightPending), undefined, row['Break Out 2 (expected)'], row['Break In 2 (expected)'])}`}>
                                            {row['Break Out 2 (actual)'] ?? '--'}
                                        </td>
                                        <td className={`px-2 py-1.5 truncate ${cls(getBreakInStatus(row['Break In 2 (actual)'], row['Break Out 2 (actual)'], break2Mins, isToday, nowMins, row['Break Out 2 (expected)'], nightPending), undefined, row['Break In 2 (expected)'], row['Time Out (expected)'])}`}>
                                            {row['Break In 2 (actual)'] ?? '--'}
                                        </td>
                                        <td className={`px-2 py-1.5 truncate ${cls(getTimeStatus(row['Time Out (actual)'], effectiveTimeOutExp, 'out', isToday, nowMins, nightPending), undefined, effectiveTimeOutExp, effectiveTimeOutExp)}`}>
                                            {row['Time Out (actual)'] ?? '--'}
                                        </td>
                                        <td className="px-2 py-1.5 truncate">
                                            {row.HOLIDAY_NAME ? (
                                                <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-semibold bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400 whitespace-nowrap">
                                                    {row.HOLIDAY_NAME}
                                                </span>
                                            ) : <span className="text-zinc-300 dark:text-zinc-600">—</span>}
                                        </td>
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
                <div className="flex items-center justify-between px-3 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                    <span className="text-[9px] text-zinc-400">
                        {meta.total} record(s) · Page {page} of {meta.last_page}
                    </span>
                    <div className="flex items-center gap-1">
                        <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page <= 1}
                            className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            Prev
                        </button>
                        <button onClick={() => setPage(p => Math.min(meta.last_page, p + 1))} disabled={page >= meta.last_page}
                            className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function PerfectAttendance() {
    const { app_name } = usePage().props;
    const [selectedEmployee, setSelectedEmployee] = useState(null);
    const [showingStats,     setShowingStats]     = useState(true); // default: stats view

    const handleSelectEmployee = (emp) => {
        setSelectedEmployee(emp);
        setShowingStats(false);
    };

    const handleShowStats = () => {
        setSelectedEmployee(null);
        setShowingStats(true);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Perfect Attendance" />

            <div className="flex flex-col h-full gap-3 p-4 overflow-hidden">
                <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden flex-1 min-h-0">

                    {/* Header */}
                    <div className="flex items-center gap-2 px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
                        <Award size={15} className="text-zinc-500 dark:text-zinc-400" />
                        <h1 className="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                            Perfect Attendance
                        </h1>
                        {!showingStats && selectedEmployee && (
                            <span className="ml-1 text-[10px] text-zinc-400 dark:text-zinc-500">
                                / {selectedEmployee.EMPNAME}
                            </span>
                        )}
                    </div>

                    {/* Body */}
                    <div className="flex flex-1 min-h-0 overflow-hidden">
                        {/* Sidebar */}
                        <div className="w-56 flex-shrink-0 border-r border-zinc-200 dark:border-zinc-700 overflow-hidden flex flex-col">
                            <EmployeeSidebar
                                appName={app_name}
                                selected={selectedEmployee}
                                onSelect={handleSelectEmployee}
                                onShowStats={handleShowStats}
                                showingStats={showingStats}
                            />
                        </div>

                        {/* Main Content */}
                        <div className="flex-1 overflow-hidden p-4 flex flex-col min-h-0">
                            {showingStats ? (
                                <div className="flex flex-col flex-1 min-h-0 overflow-hidden">
                                    <PerfectAttendanceStats
                                        appName={app_name}
                                        onSelectEmployee={handleSelectEmployee}
                                    />
                                </div>
                            ) : (
                                <DtrTable
                                    appName={app_name}
                                    employee={selectedEmployee}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}