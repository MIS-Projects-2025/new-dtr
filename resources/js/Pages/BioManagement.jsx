import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import { useState, useEffect, useRef, useMemo } from "react";
import axios from 'axios';

const LoadingModal = ({ show, message = 'Preparing your export...' }) => {
    if (!show) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black/40 dark:bg-black/60 backdrop-blur-sm" />
            <div className="relative z-10 flex flex-col items-center gap-4 bg-white dark:bg-zinc-900 rounded-2xl shadow-2xl border border-zinc-200 dark:border-zinc-700 px-10 py-8 min-w-[280px]">
                <div className="relative w-14 h-14">
                    <svg
                        className="animate-spin w-14 h-14 text-zinc-300 dark:text-zinc-700"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                    >
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2.5" />
                        <path
                            className="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                        />
                    </svg>
                    <div className="absolute inset-0 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5 text-zinc-500 dark:text-zinc-400">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                    </div>
                </div>
                <div className="flex flex-col items-center gap-1 text-center">
                    <p className="text-[13px] font-semibold text-zinc-700 dark:text-zinc-200">
                        {message}
                    </p>
                    <p className="text-[10px] text-zinc-400 dark:text-zinc-500">
                        This may take a moment for large date ranges.
                    </p>
                </div>
                <div className="flex items-center gap-1.5">
                    {[0, 1, 2].map(i => (
                        <span
                            key={i}
                            className="w-1.5 h-1.5 rounded-full bg-zinc-400 dark:bg-zinc-500 animate-bounce"
                            style={{ animationDelay: `${i * 0.15}s` }}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
};

const TABS = ['Biometric Logs', 'Data Management'];

// ── Shared Log Form (Official Business / Fit to Work / Newly Hired) ────────
const PUNCH_MAP = [
    { label: 'Time In',     field: 'time_in',     punch_type: 'check_in'  },
    { label: 'Break Out 1', field: 'break_out_1', punch_type: 'break_out' },
    { label: 'Break In 1',  field: 'break_in_1',  punch_type: 'break_in'  },
    { label: 'Lunch Out',   field: 'lunch_out',   punch_type: 'break_out' },
    { label: 'Lunch In',    field: 'lunch_in',    punch_type: 'break_in'  },
    { label: 'Break Out 2', field: 'break_out_2', punch_type: 'break_out' },
    { label: 'Break In 2',  field: 'break_in_2',  punch_type: 'break_in'  },
    { label: 'Time Out',    field: 'time_out',    punch_type: 'check_out' },
];

const EMPTY_ENTRIES = {
    time_in: '', break_out_1: '', break_in_1: '', lunch_out: '',
    lunch_in: '', break_out_2: '', break_in_2: '', time_out: ''
};

const SIMPLE_PUNCH_MAP = [
    { label: 'Time In',  field: 'time_in',  punch_type: 'check_in'  },
    { label: 'Time Out', field: 'time_out', punch_type: 'check_out' },
];

const SIMPLE_EMPTY_ENTRIES = { time_in: '', time_out: '' };

const ObDatePicker = ({ dates = [], value, onChange }) => {
    const [open,       setOpen]       = useState(false);
    const [search,     setSearch]     = useState('');
    const dropdownRef                 = useRef(null);

    const filtered = dates.filter(d => d.includes(search.trim()));

    // Format for display: "Mon, Jan 6, 2026"
    const fmt = (dateStr) => {
        const [y, m, d] = dateStr.split('-');
        return new Date(+y, +m - 1, +d).toLocaleDateString('en-US', {
            weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
        });
    };

    // Close on outside click
    useEffect(() => {
        const handler = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                setOpen(false);
                setSearch('');
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    return (
        <div className="relative" ref={dropdownRef}>
            {/* Trigger */}
            <button
                type="button"
                onClick={() => setOpen(prev => !prev)}
                className="w-full text-left text-[11px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-3 py-2 focus:outline-none focus:ring-1 focus:ring-zinc-400 flex items-center justify-between"
            >
                <span className={value ? 'text-zinc-700 dark:text-zinc-200' : 'text-zinc-400 dark:text-zinc-500'}>
                    {value ? fmt(value) : 'Select a date...'}
                </span>
                <svg className={`w-3 h-3 text-zinc-400 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {/* Dropdown */}
            {open && (
                <div className="absolute z-30 left-0 right-0 mt-1 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-lg overflow-hidden">
                    {/* Search input */}
                    <div className="p-2 border-b border-zinc-100 dark:border-zinc-800">
                        <div className="relative">
                            <svg className="absolute left-2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 text-zinc-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
                            </svg>
                            <input
                                type="text"
                                placeholder="Search date... (e.g. Jan, 2026-01)"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                autoFocus
                                className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 pl-6 pr-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                            />
                        </div>
                    </div>

                    {/* Date list */}
                    <div className="max-h-52 overflow-y-auto">
                        {filtered.length === 0 ? (
                            <div className="px-3 py-3 text-[10px] text-zinc-400 text-center">
                                No dates match "{search}"
                            </div>
                        ) : (
                            filtered.map(d => (
                                <button
                                    key={d}
                                    type="button"
                                    onClick={() => {
                                        onChange(d);
                                        setOpen(false);
                                        setSearch('');
                                    }}
                                    className={`w-full text-left px-3 py-2 text-[11px] border-b border-zinc-50 dark:border-zinc-800 last:border-0 transition-colors flex items-center justify-between
                                        ${d === value
                                            ? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-100 font-medium'
                                            : 'text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800'
                                        }`}
                                >
                                    <span>{fmt(d)}</span>
                                    <span className="text-[9px] text-zinc-400 dark:text-zinc-500">{d}</span>
                                </button>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

const GenericLogForm = ({ category, onClose }) => {
    const isOB         = category === 'official_business';
    const isNewlyHired = category === 'newly_hired';
    const isFtw        = category === 'fit_to_work';
    const needsDateList = isOB || isNewlyHired || isFtw;

    const [selectedDate,       setSelectedDate]       = useState('');
    const [obDates,            setObDates]            = useState(new Set());
    const [obDatesLoading,     setObDatesLoading]     = useState(false);
    const [obEmployeeList,     setObEmployeeList]     = useState([]);
    const [obListLoading,      setObListLoading]      = useState(false);
    const [selectedEmployees,  setSelectedEmployees]  = useState([]);
    const [employeeSearchTerm, setEmployeeSearchTerm] = useState('');
    const [searchResults,      setSearchResults]      = useState([]);
    const [logEntries,         setLogEntries]         = useState(SIMPLE_EMPTY_ENTRIES);
    const [saving,             setSaving]             = useState(false);

    useEffect(() => {
        if (!needsDateList) return;
        setObDatesLoading(true);
        const routeName = isNewlyHired
            ? 'bio.newly-hired-dates'
            : isFtw
            ? 'bio.ftw-dates'
            : 'bio.ob-dates';
        axios.get(route(routeName))
            .then(({ data }) => setObDates(new Set(data)))
            .catch(err => console.error(err))
            .finally(() => setObDatesLoading(false));
    }, []);

    const handleDateChange = async (date) => {
        setSelectedDate(date);
        setSelectedEmployees([]);
        setObEmployeeList([]);
        setLogEntries(SIMPLE_EMPTY_ENTRIES);
        if (!date || !needsDateList) return;
        setObListLoading(true);
        try {
            const routeName = isNewlyHired
                ? 'bio.newly-hired-employees'
                : isFtw
                ? 'bio.ftw-employees'
                : 'bio.ob-employees';
            const { data } = await axios.get(route(routeName), { params: { date } });
            setObEmployeeList(data);
        } catch (err) {
            console.error(err);
        } finally {
            setObListLoading(false);
        }
    };

    const addEmployee = (emp) => {
        if (!selectedEmployees.some(e => e.EMPLOYID === emp.EMPLOYID))
            setSelectedEmployees(prev => [...prev, emp]);
    };

    const removeEmployee = (idx) =>
        setSelectedEmployees(prev => prev.filter((_, i) => i !== idx));

    // For FTW: slots are fixed per employee from the FTW record (no manual time input)
    // For OB/Newly Hired: use the existing slotStatus logic
    const slotStatus = useMemo(() => {
        if (isFtw) {
            return SIMPLE_PUNCH_MAP.reduce((acc, { field }) => ({ ...acc, [field]: 'disabled' }), {});
        }
        if ((!isOB && !isNewlyHired) || selectedEmployees.length === 0) {
            return SIMPLE_PUNCH_MAP.reduce((acc, { field }) => ({ ...acc, [field]: 'enabled' }), {});
        }
        return SIMPLE_PUNCH_MAP.reduce((acc, { field }) => {
            const statuses = selectedEmployees.map(emp => {
                const slot = (emp.slots ?? []).find(s => s.key === field);
                return slot?.status ?? 'out_of_range';
            });
            let resolved;
            if (statuses.some(s => s === 'missing'))       resolved = 'enabled';
            else if (statuses.every(s => s === 'has_log')) resolved = 'has_log';
            else                                           resolved = 'disabled';
            return { ...acc, [field]: resolved };
        }, {});
    }, [selectedEmployees, isOB, isNewlyHired, isFtw]);

    useEffect(() => {
        setLogEntries(prev => {
            const next = { ...prev };
            SIMPLE_PUNCH_MAP.forEach(({ field }) => {
                if (slotStatus[field] !== 'enabled') next[field] = '';
            });
            return next;
        });
    }, [slotStatus]);

    const searchEmployees = async (query) => {
        if (!query.trim()) { setSearchResults([]); return; }
        try {
            const { data } = await axios.get(route('bio.search-employees'), { params: { search: query } });
            setSearchResults(data);
        } catch (err) { console.error(err); }
    };

    // ── FTW Save: time comes from the FTW record (emp.slots[].time_value) ──
    const handleFtwSave = async () => {
        if (!selectedDate)             { alert('Please select a date'); return; }
        if (!selectedEmployees.length) { alert('Please select at least one employee'); return; }
        setSaving(true);
        try {
            for (const emp of selectedEmployees) {
                for (const slot of (emp.slots ?? [])) {
                    if (slot.status !== 'missing' || !slot.time_value) continue;
                    await axios.post(route('bio.add-manual-log'), {
                        employid:          emp.EMPLOYID,
                        datetime:          `${selectedDate} ${slot.time_value}:00`,
                        punch_type:        slot.punch_type,
                        employee_category: 'fit_to_work',
                        auth_mode: '', device_number: '', device_ip: '',
                        work_code: null, state: null,
                    });
                }
            }
            alert('Logs saved successfully!');
            onClose();
            setSelectedDate('');
            setLogEntries(SIMPLE_EMPTY_ENTRIES);
            setSelectedEmployees([]);
            setObEmployeeList([]);
        } catch (err) {
            console.error(err);
            alert('Error saving logs. Please try again.');
        } finally {
            setSaving(false);
        }
    };

    const handleSave = async () => {
        if (isFtw) return handleFtwSave();
        if (!selectedDate)             { alert('Please select a date'); return; }
        if (!selectedEmployees.length) { alert('Please select at least one employee'); return; }
        setSaving(true);
        try {
            for (const emp of selectedEmployees) {
                for (const { field, punch_type } of SIMPLE_PUNCH_MAP) {
                    if (!logEntries[field]) continue;
                    if (isOB || isNewlyHired) {
                        const slot = (emp.slots ?? []).find(s => s.key === field);
                        if (!slot || slot.status !== 'missing') continue;
                    }
                    await axios.post(route('bio.add-manual-log'), {
                        employid:          emp.EMPLOYID,
                        datetime:          `${selectedDate} ${logEntries[field]}:00`,
                        punch_type,
                        employee_category: category,
                        auth_mode: '', device_number: '', device_ip: '',
                        work_code: null, state: null,
                    });
                }
            }
            alert('Logs saved successfully!');
            onClose();
            setSelectedDate('');
            setLogEntries(SIMPLE_EMPTY_ENTRIES);
            setSelectedEmployees([]);
            setObEmployeeList([]);
        } catch (err) {
            console.error(err);
            alert('Error saving logs. Please try again.');
        } finally {
            setSaving(false);
        }
    };

    const selectedIds = new Set(selectedEmployees.map(e => e.EMPLOYID));

    return (
        <div className="space-y-4">

            {/* ── Date picker ── */}
            <div>
                <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-1">
                    Select Date
                </label>
                {needsDateList ? (
                    obDatesLoading ? (
                        <div className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800 text-zinc-400 px-3 py-2">
                            Loading available dates...
                        </div>
                    ) : (
                        <ObDatePicker
                            dates={[...obDates].sort().reverse()}
                            value={selectedDate}
                            onChange={handleDateChange}
                        />
                    )
                ) : (
                    <input
                        type="date"
                        value={selectedDate}
                        onChange={(e) => setSelectedDate(e.target.value)}
                        className="w-full text-[11px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-3 py-2 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                )}
            </div>

            {/* ── FTW / OB / Newly Hired employee list ── */}
            {(isOB || isNewlyHired || isFtw) && (
                <div>
                    <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-1">
                        {isFtw
                            ? 'Fit to Work Employees'
                            : isNewlyHired
                            ? 'Newly Hired Employees'
                            : 'Employees on OB'}
                        {selectedDate && !obListLoading && (
                            <span className="ml-1 font-normal text-zinc-400">
                                — {obEmployeeList.length} with missing logs
                            </span>
                        )}
                    </label>
                    <div className="rounded-md border border-zinc-200 dark:border-zinc-700 overflow-hidden min-h-[80px] max-h-[200px] overflow-y-auto bg-zinc-50 dark:bg-zinc-800/50">
                        {!selectedDate ? (
                            <div className="px-3 py-4 text-[10px] text-zinc-400 text-center">
                                Select a date to see {isFtw ? 'fit to work employees' : isNewlyHired ? 'newly hired employees' : 'employees on OB'}
                            </div>
                        ) : obListLoading ? (
                            <div className="px-3 py-4 text-[10px] text-zinc-400 text-center">Loading...</div>
                        ) : obEmployeeList.length === 0 ? (
                            <div className="px-3 py-4 text-[10px] text-zinc-400 text-center">
                                No employees with missing logs on this date
                            </div>
                        ) : (
                            obEmployeeList.map((emp) => {
                                const isAdded      = selectedIds.has(emp.EMPLOYID);
                                const missingSlots = (emp.slots ?? []).filter(s => s.status === 'missing');
                                return (
                                    <button
                                        key={emp.EMPLOYID}
                                        onClick={() => addEmployee(emp)}
                                        disabled={isAdded}
                                        className={`w-full text-left px-3 py-2 border-b border-zinc-100 dark:border-zinc-700 last:border-0 transition-colors flex items-center justify-between gap-2
                                            ${isAdded
                                                ? 'bg-blue-50 dark:bg-blue-900/20 cursor-default'
                                                : 'hover:bg-white dark:hover:bg-zinc-700 cursor-pointer'
                                            }`}
                                    >
                                        <div className="min-w-0">
                                            <div className="text-[11px] font-medium text-zinc-700 dark:text-zinc-300 truncate">
                                                {emp.EMPNAME}
                                            </div>
                                            <div className="text-[9px] text-zinc-500 dark:text-zinc-400 truncate">
                                                {emp.EMPLOYID} · {emp.DEPARTMENT}
                                                {isFtw && emp.recommendation && (
                                                    <>
                                                        {' · '}
                                                        <span className="text-teal-600 dark:text-teal-400">
                                                            Rec: {emp.recommendation === 1 ? 'Return to Work' : emp.recommendation === 2 ? 'Rest' : 'Refer'}
                                                        </span>
                                                    </>
                                                )}
                                                {isFtw && emp.diagnose && (
                                                    <>
                                                        {' · '}
                                                        <span className="text-amber-600 dark:text-amber-400">
                                                            Dx: {emp.diagnose}
                                                        </span>
                                                    </>
                                                )}
                                                {' · missing: '}
                                                <span className="text-red-500 dark:text-red-400">
                                                    {missingSlots.map(s => s.label).join(', ')}
                                                </span>
                                            </div>
                                        </div>
                                        {isAdded ? (
                                            <span className="text-[9px] font-medium text-blue-600 dark:text-blue-400 flex-shrink-0">Added</span>
                                        ) : (
                                            <svg className="w-3.5 h-3.5 text-zinc-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                        )}
                                    </button>
                                );
                            })
                        )}
                    </div>
                </div>
            )}

            {/* ── Non-OB/FTW employee search ── */}
            {!isOB && !isNewlyHired && !isFtw && (
                <div className="relative">
                    <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-1">
                        Search Employee
                    </label>
                    <input
                        type="text"
                        placeholder="Type to search employee..."
                        value={employeeSearchTerm}
                        onChange={(e) => {
                            setEmployeeSearchTerm(e.target.value);
                            searchEmployees(e.target.value);
                        }}
                        className="w-full text-[11px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-3 py-2 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                    {employeeSearchTerm && searchResults.length > 0 && (
                        <div className="absolute z-20 left-0 right-0 mt-1 border border-zinc-200 dark:border-zinc-700 rounded-md bg-white dark:bg-zinc-900 shadow-lg max-h-48 overflow-y-auto">
                            {searchResults.map((emp) => (
                                <button
                                    key={emp.EMPLOYID}
                                    onClick={() => {
                                        addEmployee(emp);
                                        setEmployeeSearchTerm('');
                                        setSearchResults([]);
                                    }}
                                    className="w-full text-left px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800 border-b border-zinc-100 dark:border-zinc-800 last:border-0"
                                >
                                    <div className="text-[11px] font-medium text-zinc-700 dark:text-zinc-300">{emp.EMPNAME}</div>
                                    <div className="text-[9px] text-zinc-500 dark:text-zinc-400">
                                        ID: {emp.EMPLOYID} | Dept: {emp.DEPARTMENT} | Job: {emp.JOB_TITLE}
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* ── Selected employees ── */}
            <div>
                <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-1">
                    Selected Employees
                </label>
                <div className="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 p-3 min-h-[80px]">
                    {selectedEmployees.length === 0 ? (
                        <p className="text-[10px] text-zinc-400 dark:text-zinc-500">
                            {(isOB || isNewlyHired || isFtw) ? 'Click an employee from the list above to add' : 'No employees selected — search above to add'}
                        </p>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {selectedEmployees.map((emp, i) => (
                                <div key={i} className="inline-flex items-center gap-1.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 px-2 py-1 rounded-md text-[10px]">
                                    <span>{emp.EMPNAME} ({emp.EMPLOYID})</span>
                                    <button onClick={() => removeEmployee(i)} className="hover:text-blue-900 dark:hover:text-blue-200">
                                        <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* ── FTW: show fixed log preview instead of time inputs ── */}
            {isFtw ? (
                selectedEmployees.length > 0 && (
                    <div>
                        <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-2">
                            Logs to be Saved
                        </label>
                        <div className="rounded-md border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                            {selectedEmployees.map((emp) => (
                                <div key={emp.EMPLOYID} className="px-3 py-2 border-b border-zinc-100 dark:border-zinc-800 last:border-0">
                                    <div className="text-[10px] font-medium text-zinc-700 dark:text-zinc-300">
                                        {emp.EMPNAME}
                                    </div>
                                    {emp.diagnose && (
                                        <div className="text-[9px] text-amber-600 dark:text-amber-400 mb-1">
                                            Dx: {emp.diagnose}
                                        </div>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {(emp.slots ?? []).map((slot) => (
                                            <div
                                                key={slot.key}
                                                className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-medium
                                                    ${slot.status === 'missing'
                                                        ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-800'
                                                        : 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800'
                                                    }`}
                                            >
                                                <span>{slot.label}:</span>
                                                <span className="font-mono">{slot.time_value ?? '—'}</span>
                                                {slot.status === 'has_log' && <span>✓</span>}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <p className="mt-1.5 text-[9px] text-zinc-400 dark:text-zinc-500">
                            Times are pulled directly from the FTW record. Only missing logs will be saved.
                        </p>
                    </div>
                )
            ) : (
                /* ── Non-FTW: time input grid ── */
                <div>
                    <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-2">
                        Log Entries
                    </label>
                    <div className="grid grid-cols-2 gap-3">
                        {SIMPLE_PUNCH_MAP.map(({ label, field }) => {
                            const status   = slotStatus[field] ?? 'enabled';
                            const disabled = status !== 'enabled';
                            return (
                                <div key={field}>
                                    <label className={`block text-[9px] mb-1 ${
                                        status === 'has_log'  ? 'text-green-600 dark:text-green-400' :
                                        status === 'disabled' ? 'text-zinc-300 dark:text-zinc-600'   :
                                        'text-zinc-500 dark:text-zinc-400'
                                    }`}>
                                        {label}
                                        {status === 'has_log'  && <span className="ml-1">✓</span>}
                                        {status === 'disabled' && <span className="ml-1 text-[8px]">N/A</span>}
                                    </label>
                                    <input
                                        type="time"
                                        value={logEntries[field]}
                                        disabled={disabled}
                                        onChange={(e) => setLogEntries(prev => ({ ...prev, [field]: e.target.value }))}
                                        className={`w-full text-[10px] rounded-md border px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400 transition-colors
                                            ${disabled
                                                ? 'border-zinc-100 dark:border-zinc-800 bg-zinc-100 dark:bg-zinc-800/30 text-zinc-300 dark:text-zinc-600 cursor-not-allowed'
                                                : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'
                                            }`}
                                    />
                                </div>
                            );
                        })}
                    </div>
                    {(isOB || isNewlyHired) && selectedEmployees.length > 0 && (
                        <div className="mt-2 flex items-center gap-3 text-[9px] text-zinc-400">
                            <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-sm bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 inline-block" /> Editable</span>
                            <span className="flex items-center gap-1"><span className="text-green-600 dark:text-green-400">✓</span> Already logged</span>
                        </div>
                    )}
                </div>
            )}

            {/* ── Actions ── */}
            <div className="flex items-center justify-end gap-2 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <button
                    onClick={onClose}
                    className="px-3 py-1.5 text-[10px] font-medium rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                >
                    Cancel
                </button>
                <button
                    onClick={handleSave}
                    disabled={saving || !selectedDate || !selectedEmployees.length}
                    className="px-3 py-1.5 text-[10px] font-medium rounded-md bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900 hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {saving ? 'Saving...' : 'Save Log'}
                </button>
            </div>
        </div>
    );
};

export default function BioManagement() {
    const { app_name } = usePage().props;

    const [activeTab,     setActiveTab]     = useState('Biometric Logs');
    const [importFile,    setImportFile]    = useState(null);
    const [importLoading, setImportLoading] = useState(false);
    const [importResult,  setImportResult]  = useState(null);

    const handleImport = async () => {
        if (!importFile) return;
        setImportLoading(true);
        setImportResult(null);
        try {
            const formData = new FormData();
            formData.append('file', importFile);
            const { data } = await axios.post(route('bio.import'), formData);
            setImportResult(data);
            setImportFile(null);
            document.getElementById('bio-import-input').value = '';
        } catch (err) {
            const message = err.response?.data?.message ?? err.message;
            setImportResult({ error: true, message });
        } finally {
            setImportLoading(false);
        }
    };

    const [manualLogs, setManualLogs] = useState([]);
    const [logsLoading, setLogsLoading] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [pagination, setPagination] = useState({
        current_page: 1,
        last_page: 1,
        total: 0,
        per_page: 50
    });

    const fetchManualLogs = async (page = 1, search = '') => {
        setLogsLoading(true);
        try {
            const params = new URLSearchParams({
                page: page,
                ...(search && { search: search })
            });
            const url = route('bio.manual-logs') + '?' + params.toString();
            const response = await axios.get(url);
            setManualLogs(response.data.data);
            setPagination({
                current_page: response.data.current_page,
                last_page: response.data.last_page,
                total: response.data.total,
                per_page: response.data.per_page
            });
        } catch (error) {
            console.error('Error fetching logs:', error);
        } finally {
            setLogsLoading(false);
        }
    };

    const handleSearch = (e) => {
        const value = e.target.value;
        setSearchTerm(value);
        fetchManualLogs(1, value);
    };

    useEffect(() => {
        fetchManualLogs();
    }, []);

// AddLogModal with responsive height and width
const AddLogModal = ({ show, onClose }) => {
    const [activeSideTab, setActiveSideTab] = useState('Manual Log');
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    
    // Employee search states
    const [employeeSearchTerm, setEmployeeSearchTerm] = useState('');
    const [searchResults, setSearchResults] = useState([]);
    const [selectedEmployees, setSelectedEmployees] = useState([]);
    const [selectedDate, setSelectedDate] = useState('');
    const [saving, setSaving] = useState(false);
    const [logEntries, setLogEntries] = useState({
        time_in: '',
        break_out_1: '',
        break_in_1: '',
        lunch_out: '',
        lunch_in: '',
        break_out_2: '',
        break_in_2: '',
        time_out: ''
    });
    
    const sideTabs = [
        'Manual Log',
        'Official Business',
        'Fit to Work',
        'Newly Hired'
    ];

    // Search employees function
    const searchEmployees = async (query) => {
        if (!query.trim()) {
            setSearchResults([]);
            return;
        }
        
        try {
            const response = await axios.get(route('bio.search-employees'), {
                params: { search: query }
            });
            setSearchResults(response.data);
        } catch (error) {
            console.error('Error searching employees:', error);
        }
    };

    // Add employee to selection
    const addEmployee = (employee) => {
        // Check if already selected
        if (!selectedEmployees.some(emp => emp.EMPLOYID === employee.EMPLOYID)) {
            setSelectedEmployees([...selectedEmployees, employee]);
        }
        setEmployeeSearchTerm('');
        setSearchResults([]);
    };

    // Remove employee from selection
    const removeEmployee = (index) => {
        const newSelection = [...selectedEmployees];
        newSelection.splice(index, 1);
        setSelectedEmployees(newSelection);
    };

    const handleLogEntryChange = (field, value) => {
        setLogEntries(prev => ({
            ...prev,
            [field]: value
        }));
    };

    // Save manual logs
    const saveManualLogs = async () => {
        if (!selectedDate) {
            alert('Please select a date');
            return;
        }
        
        if (selectedEmployees.length === 0) {
            alert('Please select at least one employee');
            return;
        }
        
        setSaving(true);
        
        try {
            // For each selected employee, save each log entry that has a value
            for (const employee of selectedEmployees) {
                // Save Time In
                if (logEntries.time_in) {
                    await axios.post(route('bio.add-manual-log'), {
                        employid: employee.EMPLOYID,
                        datetime: `${selectedDate} ${logEntries.time_in}:00`,
                        punch_type: 'check_in',
                        employee_category: 'manual',
                        auth_mode: '',
                        device_number: '',
                        device_ip: '',
                        work_code: null,
                        state: null
                    });
                }
                
                if (logEntries.break_out_1) {
                    await axios.post(route('bio.add-manual-log'), {
                        employid: employee.EMPLOYID,
                        datetime: `${selectedDate} ${logEntries.break_out_1}:00`,
                        punch_type: 'break_out',
                        employee_category: 'manual',
                        auth_mode: '',
                        device_number: '',
                        device_ip: '',
                        work_code: null,
                        state: null
                    });
                }
                
                if (logEntries.break_in_1) {
                    await axios.post(route('bio.add-manual-log'), {
                        employid: employee.EMPLOYID,
                        datetime: `${selectedDate} ${logEntries.break_in_1}:00`,
                        punch_type: 'break_in',
                        employee_category: 'manual',
                        auth_mode: '',
                        device_number: '',
                        device_ip: '',
                        work_code: null,
                        state: null
                    });
                }
                
                if (logEntries.lunch_out) {
                    await axios.post(route('bio.add-manual-log'), {
                        employid: employee.EMPLOYID,
                        datetime: `${selectedDate} ${logEntries.lunch_out}:00`,
                        punch_type: 'break_out',
                        employee_category: 'manual',
                        auth_mode: '',
                        device_number: '',
                        device_ip: '',
                        work_code: null,
                        state: null
                    });
                }
                
                if (logEntries.lunch_in) {
                    await axios.post(route('bio.add-manual-log'), {
                        employid: employee.EMPLOYID,
                        datetime: `${selectedDate} ${logEntries.lunch_in}:00`,
                        punch_type: 'break_in',
                        employee_category: 'manual',
                        auth_mode: '',
                        device_number: '',
                        device_ip: '',
                        work_code: null,
                        state: null
                    });
                }
                
                if (logEntries.break_out_2) {
                    await axios.post(route('bio.add-manual-log'), {
                        employid: employee.EMPLOYID,
                        datetime: `${selectedDate} ${logEntries.break_out_2}:00`,
                        punch_type: 'break_out',
                        employee_category: 'manual',
                        auth_mode: '',
                        device_number: '',
                        device_ip: '',
                        work_code: null,
                        state: null
                    });
                }
                
                if (logEntries.break_in_2) {
                    await axios.post(route('bio.add-manual-log'), {
                        employid: employee.EMPLOYID,
                        datetime: `${selectedDate} ${logEntries.break_in_2}:00`,
                        punch_type: 'break_in',
                        employee_category: 'manual',
                        auth_mode: '',
                        device_number: '',
                        device_ip: '',
                        work_code: null,
                        state: null
                    });
                }
                
                if (logEntries.time_out) {
                    await axios.post(route('bio.add-manual-log'), {
                        employid: employee.EMPLOYID,
                        datetime: `${selectedDate} ${logEntries.time_out}:00`,
                        punch_type: 'check_out',
                        employee_category: 'manual',
                        auth_mode: '',
                        device_number: '',
                        device_ip: '',
                        work_code: null,
                        state: null
                    });
                }
            }
            
            alert('Manual logs saved successfully!');
            onClose();
            setSelectedDate('');
            setLogEntries({
                time_in: '',
                break_out_1: '',
                break_in_1: '',
                lunch_out: '',
                lunch_in: '',
                break_out_2: '',
                break_in_2: '',
                time_out: ''
            });
            setSelectedEmployees([]);
        } catch (error) {
            console.error('Error saving manual logs:', error);
            alert('Error saving manual logs. Please try again.');
        } finally {
            setSaving(false);
        }
    };

    // Handle search input change
    const handleEmployeeSearch = (e) => {
        const value = e.target.value;
        setEmployeeSearchTerm(value);
        searchEmployees(value);
    };

    if (!show) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
            <div className="relative z-10 bg-white dark:bg-zinc-900 rounded-xl shadow-2xl border border-zinc-200 dark:border-zinc-700 w-full h-full sm:h-auto sm:max-h-[90vh] sm:max-w-4xl sm:mx-auto flex flex-col">
                {/* Header */}
                <div className="flex items-center justify-between px-4 sm:px-6 py-4 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
                    <h2 className="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                        Add Manual Log
                    </h2>
                    <div className="flex items-center gap-2">
                        {/* Mobile menu button */}
                        <button
                            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                            className="sm:hidden p-1.5 rounded-md text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <button
                            onClick={onClose}
                            className="p-1.5 rounded-md text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                {/* Body with Sidebar - Responsive */}
                <div className="flex flex-col sm:flex-row flex-1 min-h-0 overflow-hidden">
                    {/* Sidebar - Desktop */}
                    <div className="hidden sm:block w-48 border-r border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 overflow-y-auto flex-shrink-0">
                        {sideTabs.map((tab) => (
                            <button
                                key={tab}
                                onClick={() => setActiveSideTab(tab)}
                                className={`w-full text-left px-4 py-2.5 text-[11px] font-medium transition-colors
                                    ${activeSideTab === tab
                                        ? 'bg-white dark:bg-zinc-900 text-zinc-800 dark:text-zinc-100 border-l-2 border-zinc-700 dark:border-zinc-300'
                                        : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 hover:bg-white/50 dark:hover:bg-zinc-800/50'
                                    }`}
                            >
                                {tab}
                            </button>
                        ))}
                    </div>

                    {/* Mobile Sidebar Drawer */}
                    {mobileMenuOpen && (
                        <>
                            <div 
                                className="fixed inset-0 z-20 bg-black/50 sm:hidden"
                                onClick={() => setMobileMenuOpen(false)}
                            />
                            <div className="fixed left-0 top-0 bottom-0 z-30 w-64 bg-white dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700 overflow-y-auto sm:hidden">
                                <div className="p-4 border-b border-zinc-200 dark:border-zinc-700 flex justify-between items-center">
                                    <span className="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Menu</span>
                                    <button
                                        onClick={() => setMobileMenuOpen(false)}
                                        className="p-1 rounded-md text-zinc-400 hover:text-zinc-600"
                                    >
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                                {sideTabs.map((tab) => (
                                    <button
                                        key={tab}
                                        onClick={() => {
                                            setActiveSideTab(tab);
                                            setMobileMenuOpen(false);
                                        }}
                                        className={`w-full text-left px-4 py-3 text-[12px] font-medium transition-colors
                                            ${activeSideTab === tab
                                                ? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-100 border-l-2 border-zinc-700 dark:border-zinc-300'
                                                : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800'
                                            }`}
                                    >
                                        {tab}
                                    </button>
                                ))}
                            </div>
                        </>
                    )}

                    {/* Content Area */}
                    <div className="flex-1 p-4 sm:p-6 overflow-y-auto min-h-0">
                        {/* Mobile: Show active tab name */}
                        <div className="sm:hidden mb-4 pb-2 border-b border-zinc-200 dark:border-zinc-700">
                            <h3 className="text-[13px] font-semibold text-zinc-700 dark:text-zinc-200">
                                {activeSideTab}
                            </h3>
                        </div>
                        
{activeSideTab === 'Manual Log' && (
    <div className="space-y-4">
        {/* Search Employee and Select Date - Single Row */}
        <div className="grid grid-cols-2 gap-3">
            <div className="relative">
                <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-1">
                    Search Employee
                </label>
                <input
                    type="text"
                    placeholder="Type to search employee..."
                    value={employeeSearchTerm}
                    onChange={handleEmployeeSearch}
                    className="w-full text-[11px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-3 py-2 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                />
            </div>
            <div>
                <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-1">
                    Select Date
                </label>
                <input
                    type="date"
                    value={selectedDate}
                    onChange={(e) => setSelectedDate(e.target.value)}
                    className="w-full text-[11px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-3 py-2 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                />
            </div>
        </div>

        {/* Employee Search Results */}
        {employeeSearchTerm && searchResults.length > 0 && (
            <div className="border border-zinc-200 dark:border-zinc-700 rounded-md max-h-48 overflow-y-auto">
                {searchResults.map((employee) => (
                    <button
                        key={employee.EMPLOYID}
                        onClick={() => addEmployee(employee)}
                        className="w-full text-left px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors border-b border-zinc-100 dark:border-zinc-800 last:border-0"
                    >
                        <div className="text-[11px] font-medium text-zinc-700 dark:text-zinc-300">
                            {employee.EMPNAME}
                        </div>
                        <div className="text-[9px] text-zinc-500 dark:text-zinc-400">
                            ID: {employee.EMPLOYID} | Dept: {employee.DEPARTMENT} | Job: {employee.JOB_TITLE}
                        </div>
                    </button>
                ))}
            </div>
        )}

        {/* Selected Employees - Multiple with higher height */}
        <div>
            <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-1">
                Selected Employees
            </label>
            <div className="w-full text-[11px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 p-3 min-h-[100px]">
                {selectedEmployees.length === 0 ? (
                    <p className="text-zinc-500 dark:text-zinc-400">No employees selected</p>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {selectedEmployees.map((emp, index) => (
                            <div key={index} className="inline-flex items-center gap-1.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 px-2 py-1 rounded-md text-[10px]">
                                <span>{emp.EMPNAME} ({emp.EMPLOYID})</span>
                                <button
                                    onClick={() => removeEmployee(index)}
                                    className="hover:text-blue-900 dark:hover:text-blue-200"
                                >
                                    <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>

        {/* Log Entries - 4 columns, 2 rows */}
        <div className="space-y-3">
            <label className="block text-[10px] font-medium text-zinc-600 dark:text-zinc-400">
                Log Entries
            </label>
            
            <div className="grid grid-cols-4 gap-3">
                <div>
                    <label className="block text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Time In</label>
                    <input
                        type="time"
                        value={logEntries.time_in}
                        onChange={(e) => handleLogEntryChange('time_in', e.target.value)}
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
                <div>
                    <label className="block text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Break Out 1</label>
                    <input
                        type="time"
                        value={logEntries.break_out_1}
                        onChange={(e) => handleLogEntryChange('break_out_1', e.target.value)}
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
                <div>
                    <label className="block text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Break In 1</label>
                    <input
                        type="time"
                        value={logEntries.break_in_1}
                        onChange={(e) => handleLogEntryChange('break_in_1', e.target.value)}
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
                <div>
                    <label className="block text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Lunch Out</label>
                    <input
                        type="time"
                        value={logEntries.lunch_out}
                        onChange={(e) => handleLogEntryChange('lunch_out', e.target.value)}
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
                <div>
                    <label className="block text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Lunch In</label>
                    <input
                        type="time"
                        value={logEntries.lunch_in}
                        onChange={(e) => handleLogEntryChange('lunch_in', e.target.value)}
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
                <div>
                    <label className="block text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Break Out 2</label>
                    <input
                        type="time"
                        value={logEntries.break_out_2}
                        onChange={(e) => handleLogEntryChange('break_out_2', e.target.value)}
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
                <div>
                    <label className="block text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Break In 2</label>
                    <input
                        type="time"
                        value={logEntries.break_in_2}
                        onChange={(e) => handleLogEntryChange('break_in_2', e.target.value)}
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
                <div>
                    <label className="block text-[9px] text-zinc-500 dark:text-zinc-400 mb-1">Time Out</label>
                    <input
                        type="time"
                        value={logEntries.time_out}
                        onChange={(e) => handleLogEntryChange('time_out', e.target.value)}
                        className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                    />
                </div>
            </div>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center justify-end gap-2 pt-4 border-t border-zinc-200 dark:border-zinc-700">
            <button
                onClick={onClose}
                className="px-3 py-1.5 text-[10px] font-medium rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
            >
                Cancel
            </button>
            <button
                onClick={saveManualLogs}
                disabled={saving}
                className="px-3 py-1.5 text-[10px] font-medium rounded-md bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900 hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-colors disabled:opacity-50"
            >
                {saving ? 'Saving...' : 'Save Log'}
            </button>
        </div>
    </div>
)}
                        {activeSideTab === 'Official Business' && (
                            <GenericLogForm key="official_business" category="official_business" onClose={onClose} />
                        )}
                        {activeSideTab === 'Fit to Work' && (
                            <GenericLogForm key="fit_to_work" category="fit_to_work" onClose={onClose} />
                        )}
                        {activeSideTab === 'Newly Hired' && (
                            <GenericLogForm key="newly_hired" category="newly_hired" onClose={onClose} />
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

const [showAddModal, setShowAddModal] = useState(false);

    return (
        <AuthenticatedLayout>
            <Head title="Biometric Management" />

                <AddLogModal 
                    show={showAddModal}
                    onClose={() => setShowAddModal(false)}
                />

            <div className="flex flex-col h-full gap-3 p-4 overflow-hidden">

                {/* Header */}
                <div className="flex items-center justify-between gap-3 flex-wrap flex-shrink-0">
                    <h1 className="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                        Biometric Management
                    </h1>
                </div>

                {/* Main Container */}
                <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden flex-1 min-h-0">

                    {/* Tabs */}
                    <div className="flex items-center gap-0 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
                        {TABS.map(tab => (
                            <button
                                key={tab}
                                onClick={() => setActiveTab(tab)}
                                className={`px-4 py-2.5 text-[11px] font-medium transition-colors border-b-2 -mb-px whitespace-nowrap
                                    ${activeTab === tab
                                        ? 'border-zinc-700 dark:border-zinc-300 text-zinc-800 dark:text-zinc-100'
                                        : 'border-transparent text-zinc-400 dark:text-zinc-500 hover:text-zinc-600 dark:hover:text-zinc-300'
                                    }`}
                            >
                                {tab}
                            </button>
                        ))}
                    </div>

                    {/* Tab Content */}
                    <div className="flex-1 overflow-auto min-h-0 p-4">

                        {/* ── Biometric Logs Tab ── */}
                        {activeTab === 'Biometric Logs' && (
                            <div className="flex flex-col gap-3 h-full min-h-0">

                                {/* Toolbar */}
                                <div className="flex items-center justify-between gap-2 flex-shrink-0">
                                    <div className="relative">
                                        <svg className="absolute left-2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 text-zinc-400 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
                                        </svg>
                                        <input
                                            type="text"
                                            placeholder="Search employee..."
                                            value={searchTerm}
                                            onChange={handleSearch}
                                            className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 pl-6 pr-3 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400 w-48"
                                        />
                                    </div>
                                    <button 
                                        onClick={() => setShowAddModal(true)}
                                        className="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900 text-[10px] font-medium hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-colors"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Add Log
                                    </button>
                                </div>

                                {/* Table */}
                                <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 overflow-hidden flex-1 min-h-0">
                                    <div className="flex-1 overflow-auto min-h-0">
                                        <table className="w-full text-left" style={{ tableLayout: 'fixed', fontSize: 'clamp(8px, 0.8vw, 10px)' }}>
                                            <colgroup>
                                                <col style={{ width: '15%' }} />
                                                <col style={{ width: '25%' }} />
                                                <col style={{ width: '15%' }} />
                                                <col style={{ width: '12%' }} />
                                                <col style={{ width: '13%' }} />
                                                <col style={{ width: '20%' }} />
                                            </colgroup>
                                            <thead className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                                <tr>
                                                    {['Employee ID', 'Employee Name', 'Date', 'Time', 'Flag', 'Category'].map(h => (
                                                        <th key={h} className="px-3 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider truncate">
                                                            {h}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                                                {logsLoading ? (
                                                    <tr>
                                                        <td colSpan={6} className="px-3 py-6 text-center text-zinc-400 dark:text-zinc-500 text-[10px]">
                                                            Loading...
                                                        </td>
                                                    </tr>
                                                ) : manualLogs.length === 0 ? (
                                                    <tr>
                                                        <td colSpan={6} className="px-3 py-6 text-center text-zinc-400 dark:text-zinc-500 italic text-[10px]">
                                                            No records found.
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    manualLogs.map((log, index) => (
                                                        <tr key={index} className="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                                            <td className="px-3 py-1.5 truncate">{log.employid}</td>
                                                            <td className="px-3 py-1.5 truncate">{log.employee_name || '-'}</td>
                                                            <td className="px-3 py-1.5 truncate">
                                                                {new Date(log.datetime).toLocaleDateString()}
                                                            </td>
                                                            <td className="px-3 py-1.5 truncate">
                                                                {new Date(log.datetime).toLocaleTimeString()}
                                                            </td>
                                                            <td className="px-3 py-1.5 truncate">
                                                                <span className={`inline-flex px-2 py-0.5 rounded-full text-[9px] font-medium
                                                                    ${log.punch_type === 'check_in' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ''}
                                                                    ${log.punch_type === 'check_out' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : ''}
                                                                    ${log.punch_type === 'break_in' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' : ''}
                                                                    ${log.punch_type === 'break_out' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : ''}
                                                                `}>
                                                                    {log.punch_type}
                                                                </span>
                                                            </td>
                                                            <td className="px-3 py-1.5 truncate">{log.employee_category  || '-'}</td>
                                                        </tr>
                                                    ))
                                                )}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="flex items-center justify-between px-3 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                                        <span className="text-[9px] text-zinc-400">
                                            {pagination.total} record(s) &nbsp;·&nbsp; Page {pagination.current_page} of {pagination.last_page}
                                        </span>
                                        <div className="flex items-center gap-1">
                                            <button 
                                                onClick={() => fetchManualLogs(pagination.current_page - 1, searchTerm)}
                                                disabled={pagination.current_page <= 1 || logsLoading}
                                                className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                            >
                                                Prev
                                            </button>
                                            <button 
                                                onClick={() => fetchManualLogs(pagination.current_page + 1, searchTerm)}
                                                disabled={pagination.current_page >= pagination.last_page || logsLoading}
                                                className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                            >
                                                Next
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* ── Data Management Tab ── */}
                        {activeTab === 'Data Management' && (
                            <div className="grid grid-cols-2 gap-4 h-full">

                                {/* Import Biometric Logs */}
                                <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 overflow-hidden">
                                    <div className="px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
                                        <h3 className="text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
                                            Import Biometric Logs
                                        </h3>
                                    </div>
                                    <div className="flex-1 flex flex-col gap-4 p-4">

                                        {/* Guidelines */}
                                        <div className="rounded-md bg-zinc-100 dark:bg-zinc-700/50 px-3 py-2.5 text-[10px] text-zinc-500 dark:text-zinc-400 space-y-1">
                                            <p className="font-semibold text-zinc-600 dark:text-zinc-300">Import Guidelines</p>
                                            <ul className="list-disc list-inside space-y-0.5">
                                                <li>Only <span className="font-medium">.dat</span> files are accepted</li>
                                                <li>Each row must follow this format:</li>
                                                <li className="font-mono bg-zinc-200 dark:bg-zinc-700 px-1.5 py-0.5 rounded list-none">
                                                    EmployeeID &nbsp; Date &nbsp; Time &nbsp; DeviceNo &nbsp; PunchType &nbsp; AuthMode
                                                </li>
                                                <li>Punch types: <span className="font-medium">0</span> = Check In, <span className="font-medium">1</span> = Check Out, <span className="font-medium">2</span> = Break Out, <span className="font-medium">3</span> = Break In</li>
                                                <li>Duplicate records will be automatically skipped</li>
                                            </ul>
                                        </div>

                                        {/* Drop zone */}
                                        <div
                                            onClick={() => document.getElementById('bio-import-input').click()}
                                            onDragOver={(e) => e.preventDefault()}
                                            onDrop={(e) => {
                                                e.preventDefault();
                                                const file = e.dataTransfer.files[0];
                                                if (file) setImportFile(file);
                                            }}
                                            className="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-600 p-8 cursor-pointer hover:border-zinc-400 dark:hover:border-zinc-500 transition-colors"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-8 h-8 text-zinc-300 dark:text-zinc-600">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                            </svg>
                                            <p className="text-[10px] text-zinc-400 dark:text-zinc-500">
                                                {importFile ? importFile.name : 'Click or drag & drop a .dat file here'}
                                            </p>
                                            {importFile && (
                                                <p className="text-[9px] text-zinc-400">
                                                    {(importFile.size / 1024).toFixed(1)} KB
                                                </p>
                                            )}
                                        </div>
                                        <input
                                            id="bio-import-input"
                                            type="file"
                                            accept=".dat"
                                            className="hidden"
                                            onChange={(e) => setImportFile(e.target.files[0] ?? null)}
                                        />

                                        {/* Result message */}
                                        {importResult && (
                                            <div className={`rounded-md px-3 py-2 text-[10px] ${importResult.error ? 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400' : 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400'}`}>
                                                {importResult.message}
                                                {!importResult.error && (
                                                    <div className="mt-1 flex gap-3 text-[9px] text-zinc-500 dark:text-zinc-400">
                                                        <span>✅ Inserted: {importResult.inserted}</span>
                                                        <span>⏭️ Skipped: {importResult.duplicates}</span>
                                                        <span>❌ Errors: {importResult.errors}</span>
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {/* Import button */}
                                        <button
                                            onClick={handleImport}
                                            disabled={!importFile || importLoading}
                                            className="self-end inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900 text-[10px] font-medium hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            {importLoading ? 'Importing...' : 'Import'}
                                        </button>
                                    </div>
                                </div>

{/* Export Biometric Logs */}
<div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 overflow-hidden">
    <div className="px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
        <h3 className="text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
            Export Biometric Logs
        </h3>
    </div>
    <div className="flex-1 flex flex-col gap-4 p-4">

        {/* Guidelines */}
        <div className="rounded-md bg-zinc-100 dark:bg-zinc-700/50 px-3 py-2.5 text-[10px] text-zinc-500 dark:text-zinc-400 space-y-1">
            <p className="font-semibold text-zinc-600 dark:text-zinc-300">Export Guidelines</p>
            <ul className="list-disc list-inside space-y-0.5">
                <li>Every employee is listed for <span className="font-medium">every date</span> in the range</li>
                <li>Includes Rest Day, Holiday, On Leave, OB/PB, Absent, Present, Pending</li>
                <li><span className="font-medium">With Breaks</span> — all break/lunch slots; unscheduled employees show Time In &amp; Time Out only</li>
                <li><span className="font-medium">Without Breaks</span> — Time In and Time Out columns only</li>
                <li>Colour-coded rows: green = present, red = absent, yellow = holiday, blue = leave, gray = rest day</li>
            </ul>
        </div>

        {/* Date range */}
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
                <label className="text-[10px] text-zinc-500 dark:text-zinc-400 w-16 flex-shrink-0">Date From</label>
                <input
                    type="date"
                    className="flex-1 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                />
            </div>
            <div className="flex items-center gap-2">
                <label className="text-[10px] text-zinc-500 dark:text-zinc-400 w-16 flex-shrink-0">Date To</label>
                <input
                    type="date"
                    className="flex-1 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                />
            </div>
        </div>

        {/* Export buttons */}
        <div className="flex items-center gap-2 self-end mt-auto">
            <button
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 text-[10px] font-medium hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors"
            >
                Without Breaks
            </button>
            <button
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900 text-[10px] font-medium hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-colors"
            >
                With Breaks
            </button>
        </div>

    </div>
</div>

                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}