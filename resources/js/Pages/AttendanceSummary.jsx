import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import { useState, useMemo, useEffect, useRef, Fragment } from "react";
import { ChevronLeft, ChevronRight, CalendarDays } from "lucide-react";

// ─── Date helpers ─────────────────────────────────────────────────────────────

function getSaturdayOfWeek(date) {
    const d   = new Date(date);
    const dow = d.getDay(); // 0=Sun, 1=Mon, ..., 6=Sat
    // How many days to go back to reach Saturday:
    // Sat=0, Sun=1, Mon=2, Tue=3, Wed=4, Thu=5, Fri=6
    const daysBack = dow === 6 ? 0 : dow + 1;
    d.setDate(d.getDate() - daysBack);
    d.setHours(0, 0, 0, 0);
    return d;
}

function getWeekDays(saturday) {
    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(saturday);
        d.setDate(d.getDate() + i); // Day 0=Sat, 1=Sun, 2=Mon, 3=Tue, 4=Wed, 5=Thu, 6=Fri
        return d;
    });
}

function formatDayLabel(date) {
    const days = ["SUN", "MON", "TUE", "WED", "THU", "FRI", "SAT"];
    const mm   = String(date.getMonth() + 1).padStart(2, "0");
    const dd   = String(date.getDate()).padStart(2, "0");
    const yyyy = date.getFullYear();
    return {
        day:      days[date.getDay()],
        date:     `${mm}/${dd}`,           // display label e.g. "05/11"
        dateKey:  `${yyyy}-${mm}-${dd}`,   // lookup key  e.g. "2026-05-11"
    };
}

function formatWeekLabel(saturday) {
    const friday = new Date(saturday);
    friday.setDate(saturday.getDate() + 6);
    const fmt = (d) =>
        d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
    return `${fmt(saturday)} – ${fmt(friday)}`;
}

// ─── Remark badge config (compact) ───────────────────────────────────────────

const REMARK = {
    "Present":            { label: "P",   cls: "bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400" },
    "Late":               { label: "L",   cls: "bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400" },
    "Absent":             { label: "A",   cls: "bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400" },
    "Rest Day":           { label: "RD",  cls: "bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-300" },
    "On Leave":           { label: "OL",  cls: "bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400" },
    "On Leave (Present)": { label: "OLP", cls: "bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400" },
    "Pending":            { label: "···", cls: "bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400" },
    "Holiday":            { label: "H",   cls: "bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400" },
};

function RemarkCell({ value }) {
    if (!value) return <span className="text-zinc-300 dark:text-zinc-600">—</span>;
    const cfg = REMARK[value];
    if (!cfg) return <span className="text-zinc-500 text-[10px]">{value}</span>;
    return (
        <div className="relative group flex flex-col items-center justify-center gap-0.5">
            <span className={`inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[10px] font-semibold ${cfg.cls}`}>
                {cfg.label}
            </span>
            {/* Tooltip */}
            <div className="absolute bottom-full mb-1.5 left-1/2 -translate-x-1/2 z-50
                            hidden group-hover:flex
                            px-2 py-1 rounded-md shadow-lg
                            bg-zinc-800 dark:bg-zinc-100
                            text-white dark:text-zinc-900
                            text-[9px] font-medium whitespace-nowrap
                            pointer-events-none">
                {value}
                {/* Arrow */}
                <span className="absolute top-full left-1/2 -translate-x-1/2
                                 border-4 border-transparent
                                 border-t-zinc-800 dark:border-t-zinc-100" />
            </div>
        </div>
    );
}

// ─── Legend ───────────────────────────────────────────────────────────────────

function Legend() {
    return (
        <div className="flex items-center gap-3 flex-wrap">
            {Object.entries(REMARK).map(([key, { label, cls }]) => (
                <div key={key} className="flex items-center gap-1">
                    <span className={`inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[10px] font-semibold ${cls}`}>
                        {label}
                    </span>
                    <span className="text-[10px] text-zinc-400 dark:text-zinc-500">{key}</span>
                </div>
            ))}
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function AttendanceSummary({ tableFilters }) {
    const { app_name } = usePage().props;

    const [activeLayout, setActiveLayout] = useState("layout1");

    // ── Week state ──────────────────────────────────────────────────────────
    const initialSat = useMemo(
        () => getSaturdayOfWeek(tableFilters?.start_date ?? new Date()),
        []
    );
    const [currentSaturday, setCurrentSaturday] = useState(initialSat);
    const weekDays = useMemo(() => getWeekDays(currentSaturday), [currentSaturday]);
    const [showAddDataModal, setShowAddDataModal] = useState(false);
    const [areaInput, setAreaInput] = useState("");

    const goToPrev = () =>
        setCurrentSaturday((p) => { const d = new Date(p); d.setDate(d.getDate() - 7); return d; });
    const goToNext = () =>
        setCurrentSaturday((p) => { const d = new Date(p); d.setDate(d.getDate() + 7); return d; });
    const goToCurrent = () => setCurrentSaturday(getSaturdayOfWeek(new Date()));
    const isCurrentWeek =
        currentSaturday.toDateString() === getSaturdayOfWeek(new Date()).toDateString();

    // ── Filters ─────────────────────────────────────────────────────────────
    const [filterOptions, setFilterOptions] = useState({
        companies: [], prodlines: [], departments: [], stations: [],
    });
    const [filters, setFilters] = useState({
        company: "", prodline: "", department: "", station: "",
    });
    const [empSearch, setEmpSearch]       = useState("");
    const [selectedEmps, setSelectedEmps] = useState([]);
    const [showEmpDropdown, setShowEmpDropdown] = useState(false);
    // ── Employee search state ──────────────────────────────────────
    const [empResults, setEmpResults]         = useState([]);
    const [empSearchLoading, setEmpSearchLoading] = useState(false);
    const empSearchAbortRef                   = useRef(null);

    // ── Area state ──────────────────────────────────────────────────
    const [areaSearch, setAreaSearch]           = useState("");
    const [areaList, setAreaList]               = useState([]);
    const [areaListLoading, setAreaListLoading] = useState(false);
    const [selectedArea, setSelectedArea]       = useState(null);
    const [areaDropdownOpen, setAreaDropdownOpen] = useState(false);
    const [saving, setSaving]                   = useState(false);
    const [saveError, setSaveError]             = useState("");
    const [existingEmpIds, setExistingEmpIds]   = useState(new Set());
    const [areaEmpsLoading, setAreaEmpsLoading] = useState(false);
    const [category, setCategory] = useState("");
    const [createdByFilter, setCreatedByFilter] = useState("");
    const [creatorOptions, setCreatorOptions]   = useState([]);

useEffect(() => {
    const trimmed = empSearch.trim();

    if (!trimmed) {
        setEmpResults([]);
        return;
    }

    // Cancel any in-flight request
    if (empSearchAbortRef.current) empSearchAbortRef.current.abort();
    empSearchAbortRef.current = new AbortController();

    const timer = setTimeout(() => {
        setEmpSearchLoading(true);
        fetch(
            `/${app_name}/attendance-summary/employees?q=${encodeURIComponent(trimmed)}`,
            { signal: empSearchAbortRef.current.signal }
        )
            .then((r) => r.json())
            .then((data) => setEmpResults(data.data ?? []))
            .catch((err) => { if (err.name !== 'AbortError') console.error(err); })
            .finally(() => setEmpSearchLoading(false));
    }, 300);

    return () => {
        clearTimeout(timer);
        empSearchAbortRef.current?.abort();
    };
}, [empSearch]);


    useEffect(() => {
        fetch(`/${app_name}/dashboard/filtered-employees`)
            .then((r) => r.json())
            .then((data) => {
                if (data.filters) setFilterOptions({
                    companies:   data.filters.companies   || [],
                    prodlines:   data.filters.prodlines   || [],
                    departments: data.filters.departments || [],
                    stations:    data.filters.stations    || [],
                });
            })
            .catch(console.error);
    }, []);

    // ── Data fetch ──────────────────────────────────────────────────────────
const [tableData, setTableData]     = useState([]);
const [loading, setLoading]         = useState(false);
const [currentPage, setCurrentPage] = useState(1);
const [totalCount, setTotalCount]   = useState(0);
const PER_PAGE                      = 25;
const abortRef                      = useRef(null);

// ── Layout 2 state ──────────────────────────────────────────────
const [layout2Date, setLayout2Date]     = useState(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
});
const [layout2Data, setLayout2Data]     = useState([]);
const [layout2Loading, setLayout2Loading] = useState(false);
const layout2AbortRef                   = useRef(null);

// Debounce filters so rapid dropdown changes don't fire multiple requests
const [debouncedFilters, setDebouncedFilters] = useState(filters);

const closeModal = () => {
    setShowAddDataModal(false);
    setSelectedArea(null);
    setAreaSearch("");
    setSelectedEmps([]);
    setEmpSearch("");
    setSaveError("");
    setExistingEmpIds(new Set());
    setCategory("");
};

const handleSave = async () => {
    setSaveError("");
    if (!selectedArea && !areaSearch.trim()) {
        setSaveError("Please select or enter an area name.");
        return;
    }

if (!category.trim()) {
    setSaveError("Category is required.");
    return;
}
const newEmps = selectedEmps.filter((e) => !existingEmpIds.has(e.id));
if (newEmps.length === 0) {
    setSaveError("Please add at least one new employee.");
    return;
}

    setSaving(true);
    try {
        const body = {
            employee_ids: newEmps.map((e) => e.id),
            category: category || null,
            ...(selectedArea
                ? { area_id: selectedArea.id }
                : { area_name: areaSearch.trim() }),
        };

        const res = await fetch(`/${app_name}/attendance-summary/areas`, {
            method:  "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
            },
            body: JSON.stringify(body),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? "Save failed.");

        if (!selectedArea) {
            setAreaList((prev) =>
                [...prev, data.area].sort((a, b) => a.name.localeCompare(b.name))
            );
        }

        closeModal();
    } catch (err) {
        setSaveError(err.message);
    } finally {
        setSaving(false);
    }
};

const filteredAreas = useMemo(() => {
    if (!areaSearch.trim()) return areaList;
    const q = areaSearch.toLowerCase();
    return areaList.filter((a) => a.name.toLowerCase().includes(q));
}, [areaSearch, areaList]);

const isNewArea = areaSearch.trim() !== "" &&
    !areaList.some(
        (a) =>
            a.name.toLowerCase()     === areaSearch.trim().toLowerCase() &&
            (a.category ?? "").toLowerCase() === category.trim().toLowerCase()
    );

useEffect(() => {
    if (!showAddDataModal) return;
    setAreaListLoading(true);
    setSaveError("");
    fetch(`/${app_name}/attendance-summary/areas`)
        .then((r) => r.json())
        .then((data) => setAreaList(data.data ?? []))
        .catch(console.error)
        .finally(() => setAreaListLoading(false));
}, [showAddDataModal]);

useEffect(() => {
    const timer = setTimeout(() => setDebouncedFilters(filters), 400);
    return () => clearTimeout(timer);
}, [filters]);

// Reset to page 1 when week or filters change
useEffect(() => {
    setCurrentPage(1);
}, [currentSaturday, debouncedFilters]);

useEffect(() => {
    if (abortRef.current) abortRef.current.abort();
    abortRef.current = new AbortController();

    setLoading(true);

    const params = new URLSearchParams();
    // Use local date to avoid UTC offset shifting the date back by 1 day
const sat = currentSaturday;
const satStr = `${sat.getFullYear()}-${String(sat.getMonth() + 1).padStart(2, "0")}-${String(sat.getDate()).padStart(2, "0")}`;
params.set("start_date", satStr);
    params.set("page",       currentPage);
    params.set("per_page",   PER_PAGE);
    Object.entries(debouncedFilters).forEach(([k, v]) => { if (v) params.set(k, v); });

    fetch(`/${app_name}/attendance-summary/data?${params.toString()}`, {
        signal: abortRef.current.signal,
    })
        .then((r) => r.json())
        .then((data) => {
            // Temporary debug — remove after fix
            if (data.data?.length > 0) {
                console.log('First row attendance keys:', Object.keys(data.data[0].attendance ?? {}));
                console.log('First row attendance:', data.data[0].attendance);
                console.log('Week days ds values:', Array.from({ length: 7 }, (_, i) => {
                    const d = new Date(currentSaturday);
                    d.setDate(currentSaturday.getDate() + i);
                    const mm = String(d.getMonth() + 1).padStart(2, "0");
                    const dd = String(d.getDate()).padStart(2, "0");
                    return `${mm}/${dd}`;
                }));
            }
            setTableData(data.data ?? []);
            setTotalCount(data.total ?? 0);
        })
        .catch((err) => { if (err.name !== "AbortError") console.error(err); })
        .finally(() => setLoading(false));

    return () => abortRef.current?.abort();
}, [currentSaturday, debouncedFilters, currentPage]);

// ── Layout 2 fetch ──────────────────────────────────────────────
useEffect(() => {
    if (activeLayout !== "layout2") return;
    fetch(`/${app_name}/attendance-summary/creators`)
        .then((r) => r.json())
        .then((data) => setCreatorOptions(data.data ?? []))
        .catch(console.error);
}, [activeLayout]);

useEffect(() => {
    if (activeLayout !== "layout2") return;

    if (layout2AbortRef.current) layout2AbortRef.current.abort();
    layout2AbortRef.current = new AbortController();

    setLayout2Loading(true);

    const params = new URLSearchParams();
    params.set("date", layout2Date);
    if (createdByFilter) params.set("created_by", createdByFilter);
    Object.entries(filters).forEach(([k, v]) => { if (v) params.set(k, v); });

    fetch(`/${app_name}/attendance-summary/layout2?${params.toString()}`, {
        signal: layout2AbortRef.current.signal,
    })
        .then((r) => r.json())
        .then((data) => {
            console.log("Layout2 API response:", data);
            console.log("First row area value:", data.data?.[0]?.area);
            console.log("Full first row:", data.data?.[0]);
            setLayout2Data(data.data ?? []);
        })
        .catch((err) => { if (err.name !== "AbortError") console.error(err); })
        .finally(() => setLayout2Loading(false));

    return () => layout2AbortRef.current?.abort();
}, [activeLayout, layout2Date, filters, createdByFilter]);

const handleFilter = (key, value) =>
    setFilters((prev) => ({ ...prev, [key]: value }));

    // ── Render ──────────────────────────────────────────────────────────────
    return (
        <AuthenticatedLayout>
            <Head title="Attendance Summary" />

            <div className="flex flex-col h-full gap-3 p-4 overflow-hidden">

                {/* ── Page header ── */}
                <div className="flex items-center justify-between gap-3 flex-wrap flex-shrink-0">
                    <h1 className="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                        Attendance Summary
                    </h1>

                    {/* A/B toggle */}
                    <div className="flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800 p-1">
                        {["layout1", "layout2"].map((l) => (
                            <button
                                key={l}
                                onClick={() => setActiveLayout(l)}
                                className={`px-3 py-1 text-xs font-medium rounded-md transition-all duration-200 ${
                                    activeLayout === l
                                        ? "bg-white dark:bg-zinc-900 text-zinc-800 dark:text-zinc-100 shadow-sm"
                                        : "text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                }`}
                            >
                                {l === "layout1" ? "Layout 1" : "Layout 2"}
                            </button>
                        ))}
                    </div>
                </div>

                {/* ── Layout 1 ── */}
                {activeLayout === "layout1" ? (
                    <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden flex-1 min-h-0">

                        {/* Week nav + filters bar */}
                        <div className="flex items-center gap-3 flex-wrap px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/60 flex-shrink-0">

                            {/* Week navigator */}
                            <div className="flex items-center gap-2 flex-shrink-0">
                                <CalendarDays className="w-3.5 h-3.5 text-zinc-400" />
                                <button
                                    onClick={goToPrev}
                                    className="p-1 rounded-md hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-500 transition-colors"
                                >
                                    <ChevronLeft className="w-4 h-4" />
                                </button>
                                <span className="text-xs font-semibold text-zinc-700 dark:text-zinc-200 min-w-[210px] text-center">
                                    {formatWeekLabel(currentSaturday)}
                                </span>
                                <button
                                    onClick={goToNext}
                                    className="p-1 rounded-md hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-500 transition-colors"
                                >
                                    <ChevronRight className="w-4 h-4" />
                                </button>
                                <button
                                    onClick={goToCurrent}
                                    disabled={isCurrentWeek}
                                    className={`text-[11px] font-medium px-2.5 py-1 rounded-full border transition-all ${
                                        isCurrentWeek
                                            ? "border-zinc-200 dark:border-zinc-700 text-zinc-400 cursor-default"
                                            : "border-indigo-300 dark:border-indigo-700 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 cursor-pointer"
                                    }`}
                                >
                                    Current Week
                                </button>
                            </div>

                            {/* Divider */}
                            <div className="h-5 w-px bg-zinc-200 dark:bg-zinc-700 flex-shrink-0" />

                            {/* Dropdown filters */}
                            <div className="flex items-center gap-2 flex-wrap min-w-0">
                                {[
                                    { key: "company",    label: "All Companies",   opts: filterOptions.companies   },
                                    { key: "prodline",   label: "All Prodlines",   opts: filterOptions.prodlines   },
                                    { key: "department", label: "All Departments", opts: filterOptions.departments },
                                    { key: "station",    label: "All Stations",    opts: filterOptions.stations    },
                                ].map(({ key, label, opts }) => (
                                    <select
                                        key={key}
                                        value={filters[key]}
                                        onChange={(e) => handleFilter(key, e.target.value)}
                                        className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                                    >
                                        <option value="">{label}</option>
                                        {opts.map((o) => <option key={o} value={o}>{o}</option>)}
                                    </select>
                                ))}
                            </div>

                            {/* Loading indicator */}
                            {loading && (
                                <span className="ml-auto text-[10px] text-zinc-400 dark:text-zinc-500 animate-pulse flex-shrink-0">
                                    Loading…
                                </span>
                            )}
                        </div>

                        {/* Legend */}
                        <div className="px-4 py-2 border-b border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                            <Legend />
                        </div>

                        {/* Scrollable table */}
                        <div className="overflow-auto flex-1">
                            <table className="w-full text-xs border-collapse min-w-max [&_th]:border-slate-300 dark:[&_th]:border-slate-500 [&_td]:border-slate-300 dark:[&_td]:border-slate-500">
                                <thead>
                                    <tr className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800">
                                        <th className="text-left px-3 py-2.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide border border-zinc-200 dark:border-zinc-700 whitespace-nowrap w-28">
                                            Employee ID
                                        </th>
                                        <th className="text-left px-3 py-2.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide border border-zinc-200 dark:border-zinc-700 whitespace-nowrap w-40">
                                            Emp Name
                                        </th>
                                        <th className="text-left px-3 py-2.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide border border-zinc-200 dark:border-zinc-700 whitespace-nowrap w-24">
                                            Team
                                        </th>
                                        <th className="text-left px-3 py-2.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide border border-zinc-200 dark:border-zinc-700 whitespace-nowrap w-40">
                                            Station
                                        </th>

                                        {weekDays.map((date, i) => {
                                            const { day, date: ds, dateKey } = formatDayLabel(date);
                                            const isWeekend = day === "SAT" || day === "FRI";
                                            const isToday   = date.toDateString() === new Date().toDateString();
                                            return (
                                                <th
                                                    key={i}
                                                    className={`text-center px-2 py-2.5 font-semibold uppercase tracking-wide border border-zinc-200 dark:border-zinc-700 whitespace-nowrap w-24 ${
                                                        isWeekend
                                                            ? "text-indigo-500 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/30"
                                                            : "text-zinc-500 dark:text-zinc-400"
                                                    }`}
                                                >
                                                    <span className={`block text-[10px] font-bold ${isToday ? "text-emerald-500 dark:text-emerald-400" : ""}`}>
                                                        {day}
                                                    </span>
                                                    <span className={`block text-[10px] font-normal ${isToday ? "text-emerald-500 dark:text-emerald-400" : "opacity-70"}`}>
                                                        {ds}
                                                    </span>
                                                    {isToday && (
                                                        <span className="block w-1 h-1 rounded-full bg-emerald-500 mx-auto mt-0.5" />
                                                    )}
                                                </th>
                                            );
                                        })}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    {loading ? (
                                        <tr>
                                            <td
                                                colSpan={4 + weekDays.length}
                                                className="px-3 py-10 text-center text-zinc-400 dark:text-zinc-500 text-[11px]"
                                            >
                                                Loading…
                                            </td>
                                        </tr>
                                    ) : tableData.length > 0 ? (
                                        tableData.map((row, idx) => (
                                            <tr
                                                key={idx}
                                                className="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                                            >
                                                <td className="px-3 py-2 text-zinc-500 dark:text-zinc-400 font-mono text-[11px] border border-zinc-200 dark:border-zinc-700">
                                                    {row.employee_id}
                                                </td>
                                                <td className="px-3 py-2 text-zinc-700 dark:text-zinc-200 font-medium max-w-[160px] truncate border border-zinc-200 dark:border-zinc-700">
                                                    {row.emp_name}
                                                </td>
                                                <td className="px-3 py-2 text-zinc-600 dark:text-zinc-300 whitespace-nowrap border border-zinc-200 dark:border-zinc-700">
                                                    {row.team || "—"}
                                                </td>
                                                <td className="px-3 py-2 text-zinc-600 dark:text-zinc-300 max-w-[160px] truncate border border-zinc-200 dark:border-zinc-700">
                                                    {row.station || "—"}
                                                </td>

                                                {weekDays.map((date, i) => {
                                                    const { day, date: ds, dateKey } = formatDayLabel(date);
                                                    const isWeekend = day === "SAT" || day === "FRI";
                                                    const isToday   = date.toDateString() === new Date().toDateString();
                                                    const cell      = row.attendance?.[dateKey] ?? null;
                                                    const remark    = cell?.remark ?? null;
                                                    const shiftType = cell?.shift_type ?? null;
                                                    return (
                                                        <td
                                                            key={i}
                                                            className={`px-2 py-1.5 text-center align-middle border border-zinc-200 dark:border-zinc-700
                                                                ${isWeekend ? "bg-indigo-50/40 dark:bg-indigo-950/10" : ""}
                                                                ${isToday   ? "bg-emerald-50/60 dark:bg-emerald-950/20" : ""}
                                                            `}
                                                        >
                                                            <RemarkCell value={remark} />
                                                            {shiftType && (
                                                                <span className={`block text-[8px] mt-0.5 leading-tight font-medium truncate max-w-[80px] mx-auto ${
                                                                    shiftType === "Night Shift"
                                                                        ? "text-indigo-500 dark:text-indigo-400"
                                                                        : shiftType === "Day Shift"
                                                                        ? "text-amber-500 dark:text-amber-400"
                                                                        : "text-zinc-400"
                                                                }`}>
                                                                    {shiftType === "Night Shift" ? "NS" : shiftType === "Day Shift" ? "DS" : shiftType}
                                                                </span>
                                                            )}
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td
                                                colSpan={4 + weekDays.length}
                                                className="px-3 py-10 text-center text-zinc-400 dark:text-zinc-500 text-[11px]"
                                            >
                                                No records found for this week.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Footer: pagination */}
                        <div className="flex items-center justify-between px-4 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                            <span className="text-[10px] text-zinc-400 dark:text-zinc-500">
                                {totalCount} employee{totalCount !== 1 ? "s" : ""}
                                &nbsp;·&nbsp;
                                Page {currentPage} of {Math.max(1, Math.ceil(totalCount / PER_PAGE))}
                            </span>
                            <div className="flex items-center gap-1">
                                <button
                                    onClick={() => setCurrentPage(1)}
                                    disabled={currentPage <= 1 || loading}
                                    className="px-2 py-1 text-[10px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                >
                                    «
                                </button>
                                <button
                                    onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                                    disabled={currentPage <= 1 || loading}
                                    className="px-2 py-1 text-[10px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                >
                                    Prev
                                </button>
                                <button
                                    onClick={() => setCurrentPage((p) => Math.min(Math.ceil(totalCount / PER_PAGE), p + 1))}
                                    disabled={currentPage >= Math.ceil(totalCount / PER_PAGE) || loading}
                                    className="px-2 py-1 text-[10px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                >
                                    Next
                                </button>
                                <button
                                    onClick={() => setCurrentPage(Math.ceil(totalCount / PER_PAGE))}
                                    disabled={currentPage >= Math.ceil(totalCount / PER_PAGE) || loading}
                                    className="px-2 py-1 text-[10px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                >
                                    »
                                </button>
                            </div>
                        </div>
                    </div>
                ) : (
    <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden flex-1 min-h-0">

        {/* ── Date filter bar ── */}
        <div className="flex items-center gap-3 flex-wrap px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/60 flex-shrink-0">
            <div className="flex items-center gap-2 flex-shrink-0">
                <CalendarDays className="w-3.5 h-3.5 text-zinc-400" />
                <span className="text-[10px] font-medium text-zinc-500 dark:text-zinc-400">Date</span>
                <input
                    type="date"
                    value={layout2Date}
                    onChange={(e) => setLayout2Date(e.target.value)}
                    className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                />
            </div>

            {/* Created by filter */}
            <div className="flex items-center gap-2 flex-shrink-0">
                <span className="text-[10px] font-medium text-zinc-500 dark:text-zinc-400">Created by</span>
                <select
                    value={createdByFilter}
                    onChange={(e) => setCreatedByFilter(e.target.value)}
                    className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                >
                    <option value="">All</option>
                    {creatorOptions.map((c) => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                </select>
            </div>

            {layout2Loading && (
                <span className="text-[10px] text-zinc-400 dark:text-zinc-500 animate-pulse flex-shrink-0">
                    Loading…
                </span>
            )}

            <button
        onClick={() => setShowAddDataModal(true)}
        className="ml-auto text-[10px] font-medium px-3 py-1 rounded-md border border-indigo-300 dark:border-indigo-700 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors flex-shrink-0"
    >
        + Add Data
    </button>
</div>

{/* ── Add Data Modal ── */}
{showAddDataModal && (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
        {/* Backdrop */}
        <div
            className="absolute inset-0 bg-black/40 dark:bg-black/60 backdrop-blur-sm"
            onClick={() => setShowAddDataModal(false)}
        />

        {/* Modal */}
        <div className="relative z-10 w-full max-w-2xl mx-4 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-xl">
            {/* Header */}
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-zinc-100 dark:border-zinc-800">
                <h2 className="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Add Data</h2>
                <button
                    onClick={() => setShowAddDataModal(false)}
                    className="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors text-lg leading-none"
                >
                    ✕
                </button>
            </div>

            {/* Body */}
            <div className="px-5 py-6 flex flex-col gap-3 max-h-[60vh] overflow-y-auto">

                {/* Two-column row */}
                <div className="flex gap-3">

                    {/* ── Left: Area ── */}
<div className="flex flex-col gap-2 flex-1 min-w-0">

    {/* Area search/select input */}
    <div className="relative">
        <input
            type="text"
            value={areaSearch}
            onChange={(e) => {
                setAreaSearch(e.target.value);
                setSelectedArea(null);
                setSelectedEmps([]);
                setExistingEmpIds(new Set());
                setAreaDropdownOpen(true);
            }}
            onFocus={() => setAreaDropdownOpen(true)}
            onBlur={() => setTimeout(() => setAreaDropdownOpen(false), 150)}
            placeholder={areaListLoading ? "Loading areas…" : "Search or add area"}
            disabled={areaListLoading}
            className="w-full text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300 px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-400 placeholder:text-zinc-300 dark:placeholder:text-zinc-600 disabled:opacity-50"
        />

        {/* Dropdown */}
        {areaDropdownOpen && (
            <div className="absolute top-full left-0 right-0 z-50 mt-1 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-lg max-h-40 overflow-y-auto">
                {/* "Add new" option when name doesn't exist yet */}
                {isNewArea && (
                    <button
                        onMouseDown={() => {
                            setSelectedArea(null);
                            setAreaDropdownOpen(false);
                        }}
                        className="w-full text-left px-3 py-2 text-[10px] border-b border-zinc-100 dark:border-zinc-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors"
                    >
                        <span className="font-semibold">+ Create "</span>
                        <span className="font-semibold">{areaSearch.trim()}"</span>
                    </button>
                )}

                {/* Existing areas */}
                {filteredAreas.length === 0 && !isNewArea ? (
                    <div className="px-3 py-3 text-center text-[10px] text-zinc-300 dark:text-zinc-600">
                        No areas found.
                    </div>
                ) : (
                    filteredAreas.map((area) => (
                        <button
                            key={area.id}
                            onMouseDown={() => {
                                setSelectedArea(area);
                                setAreaSearch(area.name);
                                setCategory(area.category ?? "");  // ← auto-fill category
                                setAreaDropdownOpen(false);
                                setAreaEmpsLoading(true);
                                setSelectedEmps([]);
                                setExistingEmpIds(new Set());
                                fetch(`/${app_name}/attendance-summary/areas/${area.id}/employees`)
                                    .then((r) => r.json())
                                    .then((data) => {
                                        const emps = (data.data ?? []).map((e) => ({
                                            id:   e.employee_id,
                                            name: e.emp_name,
                                        }));
                                        setSelectedEmps(emps);
                                        setExistingEmpIds(new Set(emps.map((e) => e.id)));
                                    })
                                    .catch(console.error)
                                    .finally(() => setAreaEmpsLoading(false));
                            }}
                            className="w-full text-left px-3 py-2 text-[10px] text-zinc-600 dark:text-zinc-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors border-b border-zinc-100 dark:border-zinc-800 last:border-0"
                        >
                            <span className="font-medium">{area.name}</span>
                            {area.category && (
                                <span className="ml-2 text-[9px] px-1 py-0.5 rounded bg-indigo-100 dark:bg-indigo-900/40 text-indigo-500 dark:text-indigo-400">
                                    {area.category}
                                </span>
                            )}
                            <span className="ml-2 text-zinc-300 dark:text-zinc-600">
                                HC: {area.required_hc}
                            </span>
                        </button>
                    ))
                )}
            </div>
        )}
    </div>

    {/* Selected area confirmation chip */}
    <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
        <div className="flex items-center justify-between px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
            <span className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                Area
            </span>
            {selectedArea && (
                <span className="text-[10px] text-zinc-400 dark:text-zinc-500">existing</span>
            )}
            {isNewArea && !selectedArea && (
                <span className="text-[10px] text-indigo-500">new</span>
            )}
        </div>
        <div className="max-h-40 overflow-y-auto">
            {!selectedArea && !areaSearch.trim() ? (
                <div className="px-3 py-6 text-center text-[10px] text-zinc-300 dark:text-zinc-600">
                    No area selected.
                </div>
            ) : (
                <div className="flex items-center justify-between px-3 py-2">
                    <div className="flex items-center gap-2">
                        <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold ${
                            selectedArea
                                ? "bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-300"
                                : "bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400"
                        }`}>
                            {selectedArea ? "existing" : "new"}
                        </span>
                        <span className="text-[10px] text-zinc-600 dark:text-zinc-300 font-medium">
                            {areaSearch.trim()}
                        </span>
                    </div>
                    <button
                        onClick={() => { setSelectedArea(null); setAreaSearch(""); }}
                        className="text-[10px] text-zinc-300 dark:text-zinc-600 hover:text-red-400 transition-colors px-1"
                    >
                        ✕
                    </button>
                </div>
            )}
        </div>
    </div>

    {/* ── Category Input (ADD THIS BLOCK) ── */}
    <div className="flex flex-col gap-1">
        <label className="text-[9px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
            Category <span className="text-red-400">*</span>
        </label>
        <input
            type="text"
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            placeholder="e.g., Production, Maintenance, Quality"
            className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300 px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-400 placeholder:text-zinc-300 dark:placeholder:text-zinc-600"
        />
    </div>
</div>

                    {/* ── Right: Employee ── */}
                    <div className="flex flex-col gap-2 flex-1 min-w-0">
                        {/* Search input */}
                        <div className="flex items-center gap-2 relative">
                            <input
                                type="text"
                                value={empSearch}
                                onChange={(e) => {
                                    setEmpSearch(e.target.value);
                                    setShowEmpDropdown(true);
                                }}
                                onFocus={() => setShowEmpDropdown(true)}
                                onBlur={() => setTimeout(() => setShowEmpDropdown(false), 150)}
                                placeholder="Search employee"
                                className="flex-1 min-w-0 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300 px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-400 placeholder:text-zinc-300 dark:placeholder:text-zinc-600"
                            />

                            {showEmpDropdown && empSearch.trim().length > 0 && (
                            <div className="absolute top-full left-0 right-0 z-50 mt-1 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-lg max-h-40 overflow-y-auto">
                                {empSearchLoading ? (
                                    <div className="px-3 py-3 text-center text-[10px] text-zinc-400 animate-pulse">
                                        Searching…
                                    </div>
                                ) : empResults.length === 0 ? (
                                    <div className="px-3 py-3 text-center text-[10px] text-zinc-300 dark:text-zinc-600">
                                        No employees found.
                                    </div>
                                ) : (
                                    empResults.map((emp) => (
                                        <button
                                            key={emp.EMPLOYID}
                                            onMouseDown={() => {
                                                if (selectedEmps.find((e) => e.id === emp.EMPLOYID)) return;
                                                setSelectedEmps((prev) => [
                                                    ...prev,
                                                    { id: emp.EMPLOYID, name: emp.EMPNAME },
                                                ]);
                                                setEmpSearch("");
                                                setShowEmpDropdown(false);
                                            }}
                                            className="w-full text-left px-3 py-2 text-[10px] text-zinc-600 dark:text-zinc-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors border-b border-zinc-100 dark:border-zinc-800 last:border-0"
                                        >
                                            <span className="font-medium">{emp.EMPNAME}</span>
                                            <span className="ml-2 text-zinc-300 dark:text-zinc-600 font-mono">
                                                {emp.EMPLOYID}
                                            </span>
                                        </button>
                                    ))
                                )}
                            </div>
                        )}
                        </div>

                        {/* Selected employees container */}
                        <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                            <div className="flex items-center justify-between px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                <span className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                    Employees
                                </span>
                                <div className="flex items-center gap-2">
                                    {existingEmpIds.size > 0 && (
                                        <span className="text-[10px] text-zinc-400 dark:text-zinc-500">
                                            {existingEmpIds.size} existing
                                        </span>
                                    )}
                                    {selectedEmps.filter((e) => !existingEmpIds.has(e.id)).length > 0 && (
                                        <span className="text-[10px] text-indigo-500 dark:text-indigo-400">
                                            +{selectedEmps.filter((e) => !existingEmpIds.has(e.id)).length} new
                                        </span>
                                    )}
                                </div>
                            </div>
                            <div className="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800 max-h-40 overflow-y-auto">
                                {areaEmpsLoading ? (
                                    <div className="px-3 py-6 text-center text-[10px] text-zinc-400 animate-pulse">
                                        Loading employees…
                                    </div>
                                ) : selectedEmps.length === 0 ? (
                                    <div className="px-3 py-6 text-center text-[10px] text-zinc-300 dark:text-zinc-600">
                                        No employees selected.
                                    </div>
                                ) : (
                                    selectedEmps.map((emp, i) => {
                                        const isExisting = existingEmpIds.has(emp.id);
                                        return (
                                            <div key={emp.id} className="flex items-center justify-between px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-[9px] text-zinc-300 dark:text-zinc-600 font-mono w-4 text-right">{i + 1}</span>
                                                    {isExisting ? (
                                                        <span className="inline-flex items-center px-1 py-0.5 rounded text-[8px] font-semibold bg-zinc-100 dark:bg-zinc-700 text-zinc-400 dark:text-zinc-500">
                                                            existing
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center px-1 py-0.5 rounded text-[8px] font-semibold bg-indigo-100 dark:bg-indigo-900/40 text-indigo-500 dark:text-indigo-400">
                                                            new
                                                        </span>
                                                    )}
                                                    <span className="text-[10px] text-zinc-600 dark:text-zinc-300 font-medium truncate">{emp.name}</span>
                                                </div>
                                                {/* Only allow removing newly added employees */}
                                                {!isExisting && (
                                                    <button
                                                        onClick={() => setSelectedEmps((prev) => prev.filter((e) => e.id !== emp.id))}
                                                        className="text-[10px] text-zinc-300 dark:text-zinc-600 hover:text-red-400 dark:hover:text-red-400 transition-colors px-1"
                                                    >
                                                        ✕
                                                    </button>
                                                )}
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            {/* Footer */}
            <div className="flex items-center justify-end gap-2 px-5 py-3.5 border-t border-zinc-100 dark:border-zinc-800">
                {saveError && (
                    <span className="text-[10px] text-red-500 mr-auto">{saveError}</span>
                )}
                <button
                    onClick={closeModal}
                    className="text-[10px] font-medium px-3 py-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                >
                    Cancel
                </button>
                <button
                    onClick={handleSave}
                    disabled={saving}
                    className="text-[10px] font-medium px-3 py-1.5 rounded-md bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white transition-colors"
                >
                    {saving ? "Saving…" : "Save"}
                </button>
            </div>
        </div>
    </div>
)}

{/* ── Table ── */}
<div className="overflow-auto flex-1">
    <table className="w-full text-left border-collapse min-w-max [&_th]:border-slate-300 dark:[&_th]:border-slate-500 [&_td]:border-slate-300 dark:[&_td]:border-slate-500" style={{ fontSize: "clamp(8px, 0.8vw, 11px)" }}>
<thead className="sticky top-0 z-10">
    <tr className="bg-zinc-100 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
        {/* Area name spans 2 rows */}
        <th rowSpan={2} className="px-3 py-2 font-semibold text-zinc-700 dark:text-zinc-200 uppercase tracking-wide whitespace-nowrap text-left border border-zinc-200 dark:border-zinc-700 bg-zinc-200 dark:bg-zinc-700">
            HC per Area
        </th>
        {/* Shift column spans 2 rows */}
        <th rowSpan={2} className="px-3 py-2 font-semibold text-zinc-600 dark:text-zinc-300 uppercase tracking-wide whitespace-nowrap text-center border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800">
            Shift
        </th>
        {/* Required Headcount — single column, no DS/NS split */}
        <th rowSpan={2} className="px-3 py-2 font-semibold text-zinc-600 dark:text-zinc-300 uppercase tracking-wide whitespace-nowrap text-center border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800">
            Required HC
        </th>
        {/* Rest Day — standalone column outside Actual Headcount group */}
        <th rowSpan={2} className="px-3 py-2 font-semibold text-zinc-600 dark:text-zinc-300 uppercase tracking-wide whitespace-nowrap text-center border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800">
            Rest Day
        </th>
        {/* Actual Headcount group — colSpan 6 (Rest Day removed) */}
        <th colSpan={6} className="px-3 py-2 font-semibold text-blue-700 dark:text-blue-400 uppercase tracking-wide whitespace-nowrap text-center border border-zinc-200 dark:border-zinc-700 bg-blue-50 dark:bg-blue-950/40">
            Actual Headcount
        </th>
        {/* Absences group */}
        <th colSpan={7} className="px-3 py-2 font-semibold text-red-700 dark:text-red-400 uppercase tracking-wide whitespace-nowrap text-center border border-zinc-200 dark:border-zinc-700 bg-red-50 dark:bg-red-950/40">
            Absences
        </th>
    </tr>
    <tr className="bg-zinc-50 dark:bg-zinc-800/80 border-b border-zinc-200 dark:border-zinc-700">
        {/* Actual Headcount sub-headers */}
        {[
            "Scheduled HC",
            "Certified Operators",
            "Trainees HC",
            "Rest Day OT",
            "Total HC",
            "%",
        ].map((h) => (
            <th key={h} className="px-3 py-1.5 font-semibold text-[9px] text-blue-600 dark:text-blue-400 uppercase tracking-wide whitespace-nowrap text-center border border-zinc-200 dark:border-zinc-700 bg-blue-50/60 dark:bg-blue-950/20">
                {h}
            </th>
        ))}
        {/* Absences sub-headers */}
        {[
            "VL / BL / EL",
            "ML / PL",
            "SL",
            "Absent",
            "Suspended",
            "Total Absent",
            "Absent %",
        ].map((h) => (
            <th key={h} className="px-3 py-1.5 font-semibold text-[9px] text-red-600 dark:text-red-400 uppercase tracking-wide whitespace-nowrap text-center border border-zinc-200 dark:border-zinc-700 bg-red-50/60 dark:bg-red-950/20">
                {h}
            </th>
        ))}
    </tr>
</thead>
<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
    {layout2Loading ? (
        <tr>
            <td colSpan={18} className="px-3 py-10 text-center text-zinc-400 dark:text-zinc-500 text-[11px] border border-slate-300 dark:border-slate-500">
                Loading…
            </td>
        </tr>
    ) : layout2Data.length === 0 ? (
        <tr>
            <td colSpan={18} className="px-3 py-10 text-center text-zinc-400 dark:text-zinc-500 text-[11px] border border-slate-300 dark:border-slate-500">
                No records found.
            </td>
        </tr>
    ) : (
        <>
            {/* ── Overview row (grand total) ── */}
            {(() => {
                const sum = (key) => layout2Data.reduce((acc, r) => acc + (r[key] ?? 0), 0);
                const totalScheduled = sum("scheduled_hc");
                const totalPresent   = sum("total_hc");
                const totalAbsent    = sum("total_absent");
                const attPct = totalScheduled > 0 ? ((totalPresent / totalScheduled) * 100).toFixed(1) : "—";
                const absPct = totalScheduled > 0 ? ((totalAbsent  / totalScheduled) * 100).toFixed(1) : "—";
                // DS = Day Shift rows, NS = Night Shift rows
                const totalReq = layout2Data.reduce((acc, r) => acc + (r.required_hc ?? 0), 0);
                const totalRestDay   = sum("rest_day_count");
                const totalNonRestDay = totalScheduled + totalAbsent - totalRestDay; // scheduled excludes RD already; use req - RD
                const totalNonRD     = sum("required_hc") - totalRestDay;
                const attPctFixed    = totalNonRD > 0 ? ((totalPresent / totalNonRD) * 100).toFixed(1) : "—";
                const absPctFixed    = totalNonRD > 0 ? ((totalAbsent  / totalNonRD) * 100).toFixed(1) : "—";
                return (
                    <tr className="bg-zinc-200 dark:bg-zinc-700 font-bold border-b-2 border-zinc-400 dark:border-zinc-500">
                        <td className="px-3 py-2 text-zinc-800 dark:text-zinc-100 whitespace-nowrap border border-slate-300 dark:border-slate-500 uppercase tracking-wide text-[10px]">Overview</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-500 dark:text-zinc-400 border border-slate-300 dark:border-slate-500">All</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{totalReq || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{totalRestDay || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{totalScheduled        || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{sum("certified_ops")  || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{sum("trainees_hc")    || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{sum("rest_day_ot")    || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-blue-700 dark:text-blue-300 border border-slate-300 dark:border-slate-500">{totalPresent          || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-blue-700 dark:text-blue-300 border border-slate-300 dark:border-slate-500">{attPctFixed !== "—" ? `${attPctFixed}%` : "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{sum("vl_bl_el")     || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{sum("ml_pl")        || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{sum("sl")           || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-red-600 dark:text-red-400 border border-slate-300 dark:border-slate-500">{sum("absent")         || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-zinc-700 dark:text-zinc-200 border border-slate-300 dark:border-slate-500">{sum("suspended")    || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-red-600 dark:text-red-400 border border-slate-300 dark:border-slate-500">{totalAbsent           || "—"}</td>
                        <td className="px-3 py-2 text-center text-[10px] text-red-600 dark:text-red-400 border border-slate-300 dark:border-slate-500">{absPctFixed !== "—" ? `${absPctFixed}%` : "—"}</td>
                    </tr>
                );
            })()}

            {(() => {
                const orderedCats = [...new Set(layout2Data.map((r) => r.category || "Uncategorized"))]
                    .sort((a, b) => a.localeCompare(b));

                // Group by category then by area name — each area can have DS + NS rows
                const groups = layout2Data.reduce((acc, row) => {
                const cat = row.category || "Uncategorized";
                if (!acc[cat]) acc[cat] = {};
                const area = row.area || "—";
                if (!acc[cat][area]) acc[cat][area] = { ds: null, ns: null, na: null };
                if (row.shift_type === "Night Shift")   acc[cat][area].ns = row;
                else if (row.shift_type === "Day Shift") acc[cat][area].ds = row;
                else                                     acc[cat][area].na = row;
                return acc;
            }, {});

                return orderedCats.map((cat) => {
                    const areaMap   = groups[cat];
                    const areaNames = Object.keys(areaMap).sort();
                    // All rows flat for subtotal
                    const allRows   = layout2Data.filter(r => (r.category || "Uncategorized") === cat);
                    const catSum    = (key) => allRows.reduce((acc, r) => acc + (r[key] ?? 0), 0);
                    const catDsReq  = allRows.filter(r => r.shift_type !== "Night Shift").reduce((acc, r) => acc + (r.required_hc ?? 0), 0);
                    const catNsReq  = allRows.filter(r => r.shift_type === "Night Shift").reduce((acc, r) => acc + (r.required_hc ?? 0), 0);
                    const catSched  = catSum("scheduled_hc");
                    const catPres   = catSum("total_hc");
                    const catAbs    = catSum("total_absent");
                    const catAttPct = catSched > 0 ? ((catPres / catSched) * 100).toFixed(1) : "—";
                    const catAbsPct = catSched > 0 ? ((catAbs  / catSched) * 100).toFixed(1) : "—";

                    return (
                        <Fragment key={cat}>
                            {/* ── Category header ── */}
                            <tr className="bg-indigo-50 dark:bg-indigo-950/30 border-t-2 border-indigo-200 dark:border-indigo-800">
                                <td colSpan={17} className="px-3 py-1.5 text-[10px] font-bold text-indigo-700 dark:text-indigo-300 uppercase tracking-widest border border-slate-300 dark:border-slate-500">
                                    {cat}
                                </td>
                            </tr>

{/* ── Area rows — TWO rows per area (DS + NS), area name rowSpan=2 ── */}
{areaNames.map((areaName) => {
    const { ds, ns } = areaMap[areaName];
    const na = areaMap[areaName].na ?? null;
    const shiftRows = [
        { row: ds, label: "DS",  isNight: false, isNA: false },
        { row: ns, label: "NS",  isNight: true,  isNA: false },
        { row: na, label: "N/A", isNight: false, isNA: true  },
    ].filter(s => s.row !== null);
    const totalShifts = shiftRows.filter(s => s.row !== null).length;
    // always render both rows for visual consistency; use rowSpan = 2
    return (
        <Fragment key={areaName}>
            {shiftRows.map(({ row, label, isNight, isNA }, idx) => {
                const isFirst = idx === 0;
                const reqBg   = isNA    ? "bg-zinc-50/40    dark:bg-zinc-800/20"
                            : isNight ? "bg-indigo-50/40  dark:bg-indigo-950/10"
                            :           "bg-amber-50/40   dark:bg-amber-950/10";
                const reqText = isNA    ? "text-zinc-400    dark:text-zinc-500"
                            : isNight ? "text-indigo-700  dark:text-indigo-400"
                            :           "text-amber-700   dark:text-amber-400";
                const exists  = row !== null;
                const v       = (key) => exists ? (row[key] || "—") : "—";
                const p       = (key) => exists && row[key] != null ? `${row[key]}%` : "—";

                return (
                    <tr
                        key={label}
                        className={`transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40 ${
                            isFirst
                                ? ""
                                : "border-t border-dashed border-zinc-200 dark:border-zinc-700"
                        }`}
                    >
                        {/* Area name cell — only on first row, spans both rows */}
                        {isFirst && (
                            <td
                                rowSpan={shiftRows.length}
                                className="px-3 py-2 whitespace-nowrap border border-slate-300 dark:border-slate-500 align-middle pl-6"
                            >
                                <span className="font-medium text-[11px] text-zinc-700 dark:text-zinc-200">
                                    {areaName}
                                </span>
                            </td>
                        )}

                        {/* Shift badge */}
                        <td className={`px-3 py-1.5 text-center border border-slate-300 dark:border-slate-500 ${reqBg}`}>
                            <span className={`inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[9px] font-bold ${reqText}`}>
                                {label}
                            </span>
                        </td>

                        {/* Required HC */}
                        <td className={`px-3 py-1.5 text-center text-[10px] font-semibold border border-slate-300 dark:border-slate-500 ${reqBg} ${reqText}`}>
                            {v("required_hc")}
                        </td>

                        {/* Rest Day — standalone, no blue background */}
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 text-[10px] border border-slate-300 dark:border-slate-500">{v("rest_day_count")}</td>
                        {/* Actual Headcount */}
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 bg-blue-50/20 dark:bg-blue-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("scheduled_hc")}</td>
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 bg-blue-50/20 dark:bg-blue-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("certified_ops")}</td>
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 bg-blue-50/20 dark:bg-blue-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("trainees_hc")}</td>
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 bg-blue-50/20 dark:bg-blue-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("rest_day_ot")}</td>
                        <td className="px-3 py-1.5 text-center font-semibold text-blue-700 dark:text-blue-400 bg-blue-50/20 dark:bg-blue-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("total_hc")}</td>
                        <td className="px-3 py-1.5 text-center font-semibold text-blue-700 dark:text-blue-400 bg-blue-50/20 dark:bg-blue-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{p("attendance_pct")}</td>

                        {/* Absences */}
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 bg-red-50/20 dark:bg-red-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("vl_bl_el")}</td>
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 bg-red-50/20 dark:bg-red-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("ml_pl")}</td>
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 bg-red-50/20 dark:bg-red-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("sl")}</td>
                        <td className="px-3 py-1.5 text-center text-red-600 dark:text-red-400 bg-red-50/20 dark:bg-red-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("absent")}</td>
                        <td className="px-3 py-1.5 text-center text-zinc-500 dark:text-zinc-400 bg-red-50/20 dark:bg-red-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("suspended")}</td>
                        <td className="px-3 py-1.5 text-center font-semibold text-red-600 dark:text-red-400 bg-red-50/20 dark:bg-red-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{v("total_absent")}</td>
                        <td className="px-3 py-1.5 text-center font-semibold text-red-600 dark:text-red-400 bg-red-50/20 dark:bg-red-950/10 text-[10px] border border-slate-300 dark:border-slate-500">{p("absent_pct")}</td>
                    </tr>
                );
            })}
        </Fragment>
    );
})}

                            {/* ── Category subtotal ── */}
                            <tr className="bg-indigo-50/60 dark:bg-indigo-950/20 border-t border-indigo-200 dark:border-indigo-800 font-semibold">
                                {/* col 1: area name (spans area + shift columns) */}
                                <td colSpan={2} className="px-3 py-1.5 text-[10px] text-indigo-700 dark:text-indigo-300 whitespace-nowrap border border-slate-300 dark:border-slate-500 pl-6 italic">
                                    Subtotal — {cat}
                                </td>
                                {/* col 3: required HC */}
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">
                                    {allRows.reduce((acc, r) => acc + (r.required_hc ?? 0), 0) || "—"}
                                </td>
                                {/* col 4: Rest Day — standalone, no blue background */}
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSum("rest_day_count") || "—"}</td>
                                {/* cols 5-10: Actual Headcount */}
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSched || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSum("certified_ops") || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSum("trainees_hc") || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSum("rest_day_ot") || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-blue-700 dark:text-blue-400 border border-slate-300 dark:border-slate-500">{catPres || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-blue-700 dark:text-blue-400 border border-slate-300 dark:border-slate-500">{(() => { const catNonRD = allRows.reduce((acc, r) => acc + (r.required_hc ?? 0), 0) - catSum("rest_day_count"); return catNonRD > 0 ? `${((catPres / catNonRD) * 100).toFixed(1)}%` : "—"; })()}</td>
                                {/* cols 11-17: Absences */}
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSum("vl_bl_el") || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSum("ml_pl") || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSum("sl") || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-red-500 dark:text-red-400 border border-slate-300 dark:border-slate-500">{catSum("absent") || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-indigo-600 dark:text-indigo-400 border border-slate-300 dark:border-slate-500">{catSum("suspended") || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-red-500 dark:text-red-400 border border-slate-300 dark:border-slate-500">{catAbs || "—"}</td>
                                <td className="px-3 py-1.5 text-center text-[10px] text-red-500 dark:text-red-400 border border-slate-300 dark:border-slate-500">{(() => { const catNonRD = allRows.reduce((acc, r) => acc + (r.required_hc ?? 0), 0) - catSum("rest_day_count"); return catNonRD > 0 ? `${((catAbs / catNonRD) * 100).toFixed(1)}%` : "—"; })()}</td>
                            </tr>
                        </Fragment>
                    );
                });
            })()}
        </>
    )}
</tbody>
            </table>
        </div>

        {/* Footer */}
        <div className="px-4 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
            <span className="text-[10px] text-zinc-400 dark:text-zinc-500">
                {layout2Data.length} area{layout2Data.length !== 1 ? "s" : ""}
                &nbsp;·&nbsp;
                {layout2Date}
            </span>
        </div>
    </div>
)}
            </div>
        </AuthenticatedLayout>
    );
}