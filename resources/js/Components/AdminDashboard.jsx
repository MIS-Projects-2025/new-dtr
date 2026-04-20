import { useState, Fragment } from "react";

const COL_HEADERS = ["Expected", "Present", "%", "Absent", "%"];

const ROW_LABELS = ["", "Scheduled Shift", "Unscheduled Shift", "Total"];

const ShiftCard = ({ title, cols = 7, headerCells, firstColSpan = 2, restCols = 5, rows = 5, rowLabels = ROW_LABELS, colHeaders = COL_HEADERS }) => (
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
                    {Array.from({ length: restCols }).map((_, colIndex) => (
                        <div
                            key={`${title}-r${rowIndex}-c${colIndex + 1}`}
                            className="flex items-center justify-center rounded bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 text-[9px] text-zinc-500 dark:text-zinc-400 overflow-hidden px-0.5"
                            style={{ minHeight: "22px" }}
                        >
                            {rowIndex === 0 ? (colHeaders[colIndex] ?? "") : ""}
                        </div>
                    ))}
                </Fragment>
            ))}
        </div>
    </div>
);

const NS_ROW_LABELS = ["", "Day Shift", "Night Shift", "Total"];
const NS_COL_HEADERS = ["Present", "%", "Absent", "%"];

const NoScheduleCard = () => (
    <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 overflow-hidden min-h-0">
        <div className="px-3 py-1.5 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
            <h3 className="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">
                No Schedule
            </h3>
        </div>
        <div
            className="flex-1 grid gap-0.5 p-1 min-h-0"
            style={{
                gridTemplateColumns: "repeat(6, 1fr)",
                gridTemplateRows: "repeat(5, 1fr)",
            }}
        >
            {/* Row 1 — Head Count spanning full width */}
            <div
                className="flex items-center justify-center rounded bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 text-[9px] text-zinc-500 dark:text-zinc-400 overflow-hidden"
                style={{ gridColumn: "span 6", minHeight: "22px" }}
            >
                Head Count: 0
            </div>

            {/* Rows 2–5 */}
            {Array.from({ length: 4 }).map((_, rowIndex) => (
                <Fragment key={`ns-r${rowIndex}`}>
                    <div
                        key={`ns-r${rowIndex}-c0`}
                        className="flex items-center justify-center rounded bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 text-[9px] text-zinc-500 dark:text-zinc-400 overflow-hidden px-1"
                        style={{ gridColumn: "span 2", minHeight: "22px" }}
                    >
                        {NS_ROW_LABELS[rowIndex] ?? ""}
                    </div>
                    {Array.from({ length: 4 }).map((_, colIndex) => (
                        <div
                            key={`ns-r${rowIndex}-c${colIndex + 1}`}
                            className="flex items-center justify-center rounded bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 text-[9px] text-zinc-500 dark:text-zinc-400 overflow-hidden px-0.5"
                            style={{ minHeight: "22px" }}
                        >
                            {rowIndex === 0 ? (NS_COL_HEADERS[colIndex] ?? "") : ""}
                        </div>
                    ))}
                </Fragment>
            ))}
        </div>
    </div>
);

const AttendanceAnalytics = () => {
    const [date, setDate] = useState(() => new Date().toISOString().split("T")[0]);

    const data = [
        { label: "Present",  value: 42, color: "#22c55e" },
        { label: "Absent",   value: 8,  color: "#ef4444" },
        { label: "Late",     value: 12, color: "#f59e0b" },
        { label: "On Leave", value: 5,  color: "#3b82f6" },
    ];

    const total = data.reduce((sum, d) => sum + d.value, 0);
    const radius = 70;
    const cx = 90;
    const cy = 90;
    const strokeWidth = 28;
    const circumference = 2 * Math.PI * radius;

    let cumulativePercent = 0;
    const slices = data.map((d) => {
        const percent = d.value / total;
        const strokeDasharray = `${percent * circumference} ${circumference}`;
        const strokeDashoffset = -cumulativePercent * circumference;
        cumulativePercent += percent;
        return { ...d, strokeDasharray, strokeDashoffset };
    });

    return (
        <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden min-h-0">
            <div className="px-3 py-2 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between gap-2 flex-shrink-0 flex-wrap">
                <p className="text-[10px] font-medium text-zinc-400 uppercase tracking-widest whitespace-nowrap">
                    Attendance Analytics
                </p>
                <input
                    type="date"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                    className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                />
            </div>

            <div className="flex-1 flex flex-col items-center justify-center gap-2 p-3 min-h-0 overflow-hidden">
                <svg
                    viewBox="0 0 180 180"
                    className="w-full flex-shrink-0"
                    style={{ maxWidth: 140, height: "auto" }}
                >
                    {slices.map((slice, i) => (
                        <circle
                            key={i}
                            cx={cx}
                            cy={cy}
                            r={radius}
                            fill="none"
                            stroke={slice.color}
                            strokeWidth={strokeWidth}
                            strokeDasharray={slice.strokeDasharray}
                            strokeDashoffset={slice.strokeDashoffset}
                            style={{ transition: "stroke-dasharray 0.4s ease" }}
                            transform={`rotate(-90 ${cx} ${cy})`}
                        />
                    ))}
                    <text x={cx} y={cy - 6} textAnchor="middle" style={{ fontSize: 22, fontWeight: 500, fill: "currentColor" }}>
                        {total}
                    </text>
                    <text x={cx} y={cy + 12} textAnchor="middle" style={{ fontSize: 9, fill: "#a1a1aa", letterSpacing: 1 }}>
                        TOTAL
                    </text>
                </svg>

                <div className="w-full grid grid-cols-2 gap-x-3 gap-y-1 flex-shrink-0">
                    {data.map((d) => (
                        <div key={d.label} className="flex items-center gap-1.5">
                            <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: d.color }} />
                            <span className="text-[9px] text-zinc-500 dark:text-zinc-400 truncate">{d.label}</span>
                            <span className="ml-auto text-[9px] font-semibold text-zinc-700 dark:text-zinc-200">{d.value}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

const DailyTimeRecord = () => (
    <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden min-h-0">
        <div className="px-4 py-2 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between gap-2 flex-shrink-0 flex-wrap">
            <p className="text-[10px] font-medium text-zinc-400 uppercase tracking-widest whitespace-nowrap">
                Daily Time Record
            </p>
            <div className="flex items-center gap-2 flex-wrap">
                <select className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400">
                    <option>All Shifts</option>
                    <option>Day Shift</option>
                    <option>Night Shift</option>
                    <option>No Schedule</option>
                </select>
                <select className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400">
                    <option>All Status</option>
                    <option>Present</option>
                    <option>Absent</option>
                    <option>Late</option>
                    <option>On Leave</option>
                </select>
                <div className="relative">
                    <svg className="absolute left-2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 text-zinc-400 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
                    </svg>
                    <input
                        type="text"
                        placeholder="Search employee..."
                        className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 pl-6 pr-3 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400 w-36"
                    />
                </div>
            </div>
        </div>

        {/* Scrollable table — only this inner div scrolls if content overflows */}
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
                    <tr>
                        <td colSpan={12} className="px-3 py-4 text-center text-zinc-400 dark:text-zinc-500 italic">
                            No records available.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
);

export default function AdminDashboard({ emp_data }) {
    return (
        // This wrapper must be given a fixed height by its parent (e.g. h-screen or h-full)
        <div className="flex flex-col h-full gap-3 p-3 overflow-hidden">

            {/* Top: Overview — flex-[3] gives cells enough height at 768px */}
            <div className="flex flex-col flex-[3] min-h-0 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden">
                <div className="px-4 py-2 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between gap-2 flex-shrink-0 flex-wrap">
                    <h2 className="text-xs font-semibold text-zinc-700 dark:text-zinc-200 whitespace-nowrap">
                        Overview
                    </h2>
                    <div className="flex items-center gap-2 flex-wrap">
                        <input
                            type="date"
                            defaultValue={new Date().toISOString().split("T")[0]}
                            className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400"
                        />
                        {["All Companies","All Prodlines","All Departments","All Stations"].map((label) => (
                            <select key={label} className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400">
                                <option>{label}</option>
                            </select>
                        ))}
                    </div>
                </div>

                {/* 3-column shift card grid — fills remaining height */}
                <div className="grid grid-cols-3 gap-2 p-2 flex-1 min-h-0">
                    <ShiftCard
                        title="Day Shift"
                        cols={7}
                        headerCells={[["3","Header Count: 0"],["4","On Rest Day: 0"]]}
                        firstColSpan={2}
                        restCols={5}
                    />
                    <ShiftCard
                        title="Night Shift"
                        cols={7}
                        headerCells={[["3","Header Count: 0"],["4","On Rest Day: 0"]]}
                        firstColSpan={2}
                        restCols={5}
                    />
                    <NoScheduleCard />
                </div>
            </div>

            {/* Bottom: DTR (2/3) + Analytics (1/3) — flex-[4] */}
            <div className="grid grid-cols-3 gap-3 flex-[4] min-h-0">
                <div className="col-span-2 min-h-0 flex flex-col">
                    <DailyTimeRecord />
                </div>
                <div className="col-span-1 min-h-0 flex flex-col">
                    <AttendanceAnalytics />
                </div>
            </div>

        </div>
    );
}