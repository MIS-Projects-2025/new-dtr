import { useEffect, useState } from "react";
import axios from "axios";
import {
    LogIn,
    Coffee,
    Utensils,
    LogOut,
    ArrowRightLeft,
    CheckCircle,
    XCircle,
    Clock,
    Moon,
    AlertCircle,
    Loader2,
} from "lucide-react";
import { Tooltip, Skeleton, Card, Spin } from 'antd';
import { useWorkSchedule } from "@/hooks/useWorkSchedule";
import { useShiftLogs } from "@/hooks/useShiftLogs";
import { useAttendanceCounter } from "@/hooks/useAttendanceCounter";

export default function EmployeeDashboard({ emp_data, employees = []}) {
    const [empData, setEmpData] = useState(null);
    const [attendanceView, setAttendanceView] = useState("weekly");
    const [periodValue, setPeriodValue] = useState("");
    const [employeeSearch, setEmployeeSearch] = useState("");
    const [showNoScheduleModal, setShowNoScheduleModal] = useState(false);
    const [selectedDate, setSelectedDate] = useState(() => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, "0");
        const day = String(now.getDate()).padStart(2, "0");
        return `${year}-${month}-${day}`;
    });

    // Use the hooks
    const { workSchedule, isLoading: isLoadingSchedule } = useWorkSchedule(emp_data?.emp_id, selectedDate);
    const { logs: selectedDateLogs, isLoading: isLoadingLogs } = useShiftLogs(emp_data?.emp_id, selectedDate);
    
    // ================= FILTER GENERATORS =================
    const toLocalDateString = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const day = String(date.getDate()).padStart(2, "0");
        return `${year}-${month}-${day}`;
    };

    const generateWeekOptions = () => {
        const weeks = [];
        const today = new Date();
        const year = today.getFullYear();
        const month = today.getMonth();
        
        const firstDayOfMonth = new Date(year, month, 1);
        const lastDayOfMonth = new Date(year, month + 1, 0);
        
        let currentDate = new Date(firstDayOfMonth);
        const currentDayOfWeek = currentDate.getDay();
        const daysToSubtract = currentDayOfWeek === 0 ? 6 : currentDayOfWeek - 1;
        currentDate.setDate(currentDate.getDate() - daysToSubtract);
        
        let weekNum = 1;
        
        while (currentDate <= lastDayOfMonth) {
            const weekStart = new Date(currentDate);
            const weekEnd = new Date(currentDate);
            weekEnd.setDate(weekEnd.getDate() + 6);
            
            if (weekEnd >= firstDayOfMonth && weekStart <= lastDayOfMonth) {
                const displayStart = weekStart < firstDayOfMonth ? firstDayOfMonth : weekStart;
                const displayEnd = weekEnd > lastDayOfMonth ? lastDayOfMonth : weekEnd;
                
                weeks.push({
                    label: `Week ${weekNum} (${displayStart.toLocaleDateString()} - ${displayEnd.toLocaleDateString()})`,
                    start: weekStart,
                    end: weekEnd,
                });
            }
            
            currentDate.setDate(currentDate.getDate() + 7);
            weekNum++;
        }
        
        return weeks;
    };

    const generateCutoffOptions = () => {
        const cutoffs = [];
        const today = new Date();

        let year = 2026;
        let month = 0;

        while (
            year < today.getFullYear() ||
            (year === today.getFullYear() && month <= today.getMonth())
        ) {
            const c1Start = new Date(year, month, 7);
            const c1End = new Date(year, month, 21);

            cutoffs.push({
                label: `1st Cutoff (${c1Start.toLocaleDateString()} - ${c1End.toLocaleDateString()})`,
                start: c1Start,
                end: c1End,
            });

            const nextMonth = new Date(year, month + 1, 1);

            const c2Start = new Date(year, month, 22);
            const c2End = new Date(nextMonth.getFullYear(), nextMonth.getMonth(), 6);

            cutoffs.push({
                label: `2nd Cutoff (${c2Start.toLocaleDateString()} - ${c2End.toLocaleDateString()})`,
                start: c2Start,
                end: c2End,
            });

            month++;
            if (month > 11) {
                month = 0;
                year++;
            }
        }

        return cutoffs;
    };

    const generateMonthOptions = () => {
        const months = [];
        const year = new Date().getFullYear();

        for (let i = 0; i < 12; i++) {
            const start = new Date(year, i, 1);
            const end = new Date(year, i + 1, 0);

            months.push({
                label: `${start.toLocaleString("default", { month: "long" })} ${year} (${start.getDate()}-${end.getDate()})`,
                start,
                end,
            });
        }

        return months;
    };

    const weekOptions = generateWeekOptions();
    const cutoffOptions = generateCutoffOptions();
    const monthOptions = generateMonthOptions();

    const getCurrentWeekIndex = (weeks) => {
        const today = new Date();
        return weeks.findIndex(
            (week) => today >= week.start && today <= week.end
        );
    };

    const currentOptions =
        attendanceView === "weekly"
            ? weekOptions
            : attendanceView === "cutoff"
            ? cutoffOptions
            : monthOptions;

    // ================= FETCH ATTENDANCE COUNTER =================
    let counterStartDate = null;
    let counterEndDate = null;
    
    if (periodValue !== "") {
        const selectedPeriod = currentOptions[parseInt(periodValue)];
        if (selectedPeriod) {
            counterStartDate = toLocalDateString(selectedPeriod.start);
            counterEndDate = toLocalDateString(selectedPeriod.end);
        }
    }
    
    const { counter: attendanceCounter, isLoading: isLoadingCounter } = useAttendanceCounter(
        emp_data?.emp_id,
        counterStartDate,
        counterEndDate
    );

    // ================= FETCH DASHBOARD DATA =================
    useEffect(() => {
        const fetchDashboard = async () => {
            try {
                const res = await axios.get("/api/dashboard/employee", {
                    params: {
                        emp_id: emp_data?.emp_id,
                    },
                });
                setEmpData(res.data);
            } catch (err) {
                console.error("Dashboard fetch error:", err);
            }
        };

        if (emp_data?.emp_id) {
            fetchDashboard();
        }
    }, [emp_data?.emp_id]);

    // ================= SET INITIAL PERIOD VALUE =================
    useEffect(() => {
        if (attendanceView === "weekly" && periodValue === "") {
            const currentWeekIndex = getCurrentWeekIndex(weekOptions);
            if (currentWeekIndex !== -1) {
                setPeriodValue(currentWeekIndex.toString());
            } else if (weekOptions.length > 0) {
                setPeriodValue("0");
            }
        } else if (periodValue === "" && currentOptions.length > 0) {
            setPeriodValue("0");
        }
    }, [attendanceView]);

    // Show modal if no schedule exists
    useEffect(() => {
        if (workSchedule && !isLoadingSchedule) {
            const scheduleForDate = workSchedule?.schedule_with_shifts?.[selectedDate];
            const hasSchedule = scheduleForDate?.details !== null && scheduleForDate?.details !== undefined;
            
            if (!hasSchedule && scheduleForDate) {
                setShowNoScheduleModal(true);
                setTimeout(() => {
                    setShowNoScheduleModal(false);
                }, 3000);
            }
        }
    }, [workSchedule, isLoadingSchedule, selectedDate]);

    // ================= DATA =================
    const scheduleForDate = workSchedule?.schedule_with_shifts?.[selectedDate];
    const hasSchedule = scheduleForDate?.details !== null && scheduleForDate?.details !== undefined;
    const tw = scheduleForDate?.details?.TIME_WINDOWS || [];
    const t = (i) => tw[i] || "--:--";
    
    const parseTime = (timeStr) => {
        if (!timeStr || timeStr === "--:--") return null;
        const parts = timeStr.split(":").map(Number);
        return parts[0] * 60 + (parts[1] || 0);
    };

    const timeInMinutes = parseTime(t(0));
    const timeOutMinutes = parseTime(t(7));

    const shiftDurationHours = (() => {
        if (timeInMinutes === null || timeOutMinutes === null) return null;
        let diff = timeOutMinutes - timeInMinutes;
        if (diff < 0) diff += 24 * 60;
        return diff / 60;
    })();

    const isNightShift = timeInMinutes !== null && timeOutMinutes !== null && timeOutMinutes < timeInMinutes;
    const isBreak1Disabled = shiftDurationHours !== null && shiftDurationHours === 12;

    const normalizeNightTime = (timeStr) => {
        if (!timeStr || timeStr === "--:--") return timeStr;
        if (!isNightShift) return timeStr;
        const [h] = timeStr.split(":").map(Number);
        const refH = timeInMinutes !== null ? Math.floor(timeInMinutes / 60) : 12;
        if (h < refH - 2) return `${timeStr} (+1)`;
        return timeStr;
    };

    const shiftSlots = [
        { key: "check_in",   label: "Time In",     icon: LogIn,          color: "text-green-600",  expected: hasSchedule ? t(0) : null },
        { key: "break_out1", label: "Break Out 1", icon: Coffee,         color: "text-yellow-600", expected: hasSchedule ? t(1) : null, disabled: isBreak1Disabled },
        { key: "break_in1",  label: "Break In 1",  icon: ArrowRightLeft, color: "text-blue-500",   expected: hasSchedule ? t(2) : null, disabled: isBreak1Disabled },
        { key: "lunch_out",  label: "Lunch Out",   icon: Utensils,       color: "text-orange-500", expected: hasSchedule ? t(3) : null },
        { key: "lunch_in",   label: "Lunch In",    icon: ArrowRightLeft, color: "text-blue-500",   expected: hasSchedule ? t(4) : null },
        { key: "break_out2", label: "Break Out 2", icon: Coffee,         color: "text-yellow-600", expected: hasSchedule ? t(5) : null },
        { key: "break_in2",  label: "Break In 2",  icon: ArrowRightLeft, color: "text-blue-500",   expected: hasSchedule ? t(6) : null },
        { key: "check_out",  label: "Time Out",    icon: LogOut,         color: "text-red-600",    expected: hasSchedule ? t(7) : null },
    ];

    const attendance = attendanceCounter;
    
    // Check if any data is loading
    const isInitialLoading = isLoadingSchedule || isLoadingLogs || isLoadingCounter;

    const filteredEmployees = employees.filter((emp) => {
        const search = employeeSearch.toLowerCase();
        return (
            emp.EMPNAME?.toLowerCase().includes(search) ||
            emp.JOB_TITLE?.toLowerCase().includes(search) ||
            emp.POSITION_LABEL?.toLowerCase().includes(search)
        );
    });

    // Skeleton for shift logs grid
    const ShiftLogsSkeleton = () => (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
            {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
                <Card key={i} className="border rounded-md p-2 min-h-[90px]">
                    <Skeleton 
                        active 
                        paragraph={{ rows: 2, width: ['80%', '60%'] }}
                        title={{ width: '70%' }}
                        avatar={false}
                    />
                </Card>
            ))}
        </div>
    );

    // Skeleton for attendance counter
    const AttendanceCounterSkeleton = () => (
        <div className="grid grid-cols-2 gap-2">
            {[1, 2, 3, 4].map((i) => (
                <Card key={i} className="border rounded-md p-2 min-h-[90px]">
                    <Skeleton 
                        active 
                        paragraph={{ rows: 1, width: '50%' }}
                        title={false}
                        avatar={false}
                    />
                    <div className="mt-2">
                        <Skeleton.Button active size="small" block />
                    </div>
                </Card>
            ))}
        </div>
    );

    // Skeleton for employee list
    const EmployeeListSkeleton = () => (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-2">
            {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((i) => (
                <Card key={i} className="border rounded-lg p-3 h-[110px]">
                    <Skeleton 
                        active 
                        paragraph={{ rows: 3, width: ['90%', '70%', '80%'] }}
                        title={{ width: '60%' }}
                        avatar={false}
                    />
                </Card>
            ))}
        </div>
    );

    // Loading overlay component
    const LoadingOverlay = () => (
        <div className="absolute inset-0 bg-white/50 dark:bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-10 rounded-lg">
            <Spin size="large" tip="Loading..." />
        </div>
    );

    return (
        <div className="w-full h-screen flex flex-col gap-3 p-2 sm:p-3 lg:p-4 overflow-hidden relative">
            {/* No Schedule Modal */}
            {showNoScheduleModal && !hasSchedule && (
                <div className="fixed inset-0 flex items-center justify-center z-50 bg-black bg-opacity-50">
                    <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 max-w-md mx-4">
                        <div className="flex items-center gap-3 mb-4">
                            <div className="bg-yellow-100 dark:bg-yellow-900 rounded-full p-2">
                                <AlertCircle className="w-6 h-6 text-yellow-600 dark:text-yellow-400" />
                            </div>
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
                                No Schedule Available
                            </h3>
                        </div>
                        <p className="text-gray-600 dark:text-gray-300 mb-4">
                            This employee doesn't have a work schedule for {selectedDate}. 
                            Only Time In and Time Out logs will be displayed. Break and lunch logs will not be available.
                        </p>
                        <button
                            onClick={() => setShowNoScheduleModal(false)}
                            className="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                        >
                            Got it
                        </button>
                    </div>
                </div>
            )}

            {/* ================= TOP SECTION ================= */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                {/* SHIFT LOGS */}
                <div className="border rounded-lg p-3 sm:p-4 flex flex-col relative">
                    <div className="flex items-center justify-between border-b pb-2 mb-3 gap-2">
                        <div className="flex items-center gap-2 flex-wrap">
                            <h2 className="text-sm font-semibold">Shift Logs</h2>
                            
                            {isLoadingSchedule && (
                                <div className="flex items-center gap-1">
                                    <Loader2 className="w-3 h-3 animate-spin text-blue-600" />
                                    <span className="text-xs text-gray-500">Loading schedule...</span>
                                </div>
                            )}
                            {isLoadingLogs && (
                                <div className="flex items-center gap-1">
                                    <Loader2 className="w-3 h-3 animate-spin text-green-600" />
                                    <span className="text-xs text-gray-500">Loading logs...</span>
                                </div>
                            )}
                            
                            {!hasSchedule && workSchedule && !isLoadingSchedule && (
                                <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300">
                                    ⚠️ No Sched – in/out Only RD count as Absent
                                </span>
                            )}

                            {workSchedule?.leave && !isLoadingSchedule && (
                                <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">
                                    🏖️ On Leave – {workSchedule.leave.type}
                                </span>
                            )}
                            
                            {hasSchedule && !isLoadingSchedule && (() => {
                                const shiftCode = scheduleForDate?.details?.SHIFTCODE ?? "";
                                const holiday = scheduleForDate?.holiday;
                                
                                return (
                                    <div className="flex gap-1">
                                        {shiftCode.includes("RD") && (
                                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                                                On Rest Day
                                            </span>
                                        )}
                                        {holiday && (
                                            <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${
                                                holiday.type?.toLowerCase().includes('regular') 
                                                    ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300'
                                                    : 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300'
                                            }`}>
                                                🎉 {holiday.name}
                                            </span>
                                        )}
                                        {shiftCode && !shiftCode.includes("RD") && (
                                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">
                                                Shift: {shiftCode}
                                            </span>
                                        )}
                                    </div>
                                );
                            })()}
                        </div>
                        <input
                            type="date"
                            value={selectedDate}
                            onChange={(e) => setSelectedDate(e.target.value)}
                            className="text-xs border rounded px-2 py-1 bg-white dark:bg-gray-800 dark:text-white dark:border-gray-600"
                        />
                    </div>

                    {/* Show skeleton while loading */}
                    {(isLoadingSchedule || isLoadingLogs) && <ShiftLogsSkeleton />}
                    
                    {/* Show actual content when loaded */}
                    {!isLoadingSchedule && !isLoadingLogs && (
                        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                            {shiftSlots.map((item, index) => {
                                const Icon = item.icon;
                                const rawTime = selectedDateLogs?.[item.key] ?? null;
                                const isLogged = !!rawTime;
                                
                                let displayTime = "No log yet";
                                let showExpected = hasSchedule && !isLoadingSchedule;
                                let expectedTime = item.expected ? normalizeNightTime(item.expected) : null;
                                
                                if (rawTime) {
                                    displayTime = rawTime;
                                } else if (!hasSchedule && (item.key === "check_in" || item.key === "check_out")) {
                                    displayTime = "No log yet";
                                } else if (!hasSchedule) {
                                    displayTime = "No schedule";
                                    showExpected = false;
                                }

                                if (item.disabled) {
                                    return (
                                        <div key={index} className="border rounded-md p-2 flex flex-col items-center justify-center text-[11px] sm:text-xs gap-1 min-h-[90px] bg-gray-100 dark:bg-gray-800 opacity-50 cursor-not-allowed">
                                            <Icon className="w-4 h-4 text-gray-400" />
                                            <div className="font-semibold text-center text-gray-400">{item.label}</div>
                                            <div className="text-gray-400 italic">N/A</div>
                                            <div className="text-[10px] text-gray-400">Not applicable</div>
                                        </div>
                                    );
                                }

                                return (
                                    <div key={index} className="border rounded-md p-2 flex flex-col items-center justify-center text-[11px] sm:text-xs gap-1 min-h-[90px]">
                                        <Icon className={`w-4 h-4 ${item.color}`} />
                                        <div className="font-semibold text-center">{item.label}</div>
                                        <div className={
                                            isLogged ? "text-green-600 font-semibold" : 
                                            (!hasSchedule && item.key !== "check_in" && item.key !== "check_out") ? "text-gray-400 italic" : 
                                            "text-gray-400 italic"
                                        }>
                                            {displayTime}
                                        </div>
                                        {showExpected && expectedTime && expectedTime !== "--:--" && (
                                            <div className="text-[10px] text-gray-400">
                                                Expected: {expectedTime}
                                            </div>
                                        )}
                                        {!hasSchedule && item.key !== "check_in" && item.key !== "check_out" && !isLoadingSchedule && (
                                            <div className="text-[10px] text-gray-400 italic">
                                                No schedule available
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* ATTENDANCE SECTION */}
                <div className="border rounded-lg p-3 sm:p-4 flex flex-col h-full relative">
                    <div className="flex items-center justify-between border-b pb-2 mb-3 gap-2">
                        <h2 className="text-sm font-semibold whitespace-nowrap">
                            Attendance Counter
                        </h2>
                        <div className="flex gap-2">
                            <select
                                value={attendanceView}
                                onChange={(e) => {
                                    setAttendanceView(e.target.value);
                                    setPeriodValue("");
                                }}
                                className="text-xs border rounded px-2 py-1 bg-white dark:bg-gray-800 dark:text-white dark:border-gray-600"
                                disabled={isLoadingCounter}
                            >
                                <option value="weekly">Weekly</option>
                                <option value="cutoff">Per Cut Off</option>
                                <option value="monthly">Monthly</option>
                            </select>
                            <select
                                value={periodValue}
                                onChange={(e) => setPeriodValue(e.target.value)}
                                className="text-xs border rounded px-2 py-1 bg-white dark:bg-gray-800 dark:text-white dark:border-gray-600 min-w-[200px]"
                                disabled={isLoadingCounter}
                            >
                                <option value="">Select Period</option>
                                {currentOptions.map((opt, index) => (
                                    <option key={index} value={index}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    
                    {/* Show skeleton while loading */}
                    {isLoadingCounter && <AttendanceCounterSkeleton />}
                    
                    {/* Show actual content when loaded */}
                    {!isLoadingCounter && (
                        <div className="flex-1 grid grid-cols-2 gap-2">
                            {[
                                { 
                                    label: "Present", 
                                    icon: CheckCircle, 
                                    color: "text-green-600", 
                                    value: attendance.present,
                                    dates: attendance.present_dates || [],
                                    bgColor: "bg-green-50 dark:bg-green-900/20",
                                    emptyMessage: "No present days in this period"
                                },
                                { 
                                    label: "Absent", 
                                    icon: XCircle, 
                                    color: "text-red-600", 
                                    value: attendance.absent,
                                    dates: attendance.absent_dates || [],
                                    bgColor: "bg-red-50 dark:bg-red-900/20",
                                    emptyMessage: "No absent days in this period"
                                },
                                { 
                                    label: "Late", 
                                    icon: Clock, 
                                    color: "text-yellow-600", 
                                    value: attendance.late,
                                    dates: attendance.late_dates || [],
                                    bgColor: "bg-yellow-50 dark:bg-yellow-900/20",
                                    emptyMessage: "No late days in this period"
                                },
                                { 
                                    label: "Rest Day", 
                                    icon: Moon, 
                                    color: "text-blue-600", 
                                    value: attendance.restday,
                                    dates: attendance.restday_dates || [],
                                    bgColor: "bg-blue-50 dark:bg-blue-900/20",
                                    emptyMessage: "No rest days in this period"
                                },
                            ].map((item, i) => {
                                const Icon = item.icon;
                                const hasDates = item.dates && item.dates.length > 0;
                                
                                const tooltipContent = hasDates ? (
                                    <div style={{ maxWidth: '250px' }}>
                                        <div style={{ fontWeight: 'bold', marginBottom: '8px', borderBottom: '1px solid rgba(255,255,255,0.2)', paddingBottom: '4px' }}>
                                            {item.label} Dates ({item.dates.length})
                                        </div>
                                        <div style={{ maxHeight: '200px', overflowY: 'auto', fontSize: '12px' }}>
                                            {item.dates.map((date, idx) => (
                                                <div key={idx} style={{ padding: '2px 0' }}>
                                                    • {date}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ) : item.emptyMessage;
                                
                                return (
                                    <Tooltip 
                                        key={i} 
                                        title={tooltipContent} 
                                        placement="top"
                                        mouseEnterDelay={0.3}
                                    >
                                        <div className={`border rounded-md p-2 flex flex-col items-center justify-center gap-1 text-xs min-h-[90px] cursor-help ${item.bgColor} hover:shadow-md transition-all duration-200`}>
                                            <Icon className={`w-4 h-4 ${item.color}`} />
                                            <div className={`font-semibold ${item.color}`}>{item.label}</div>
                                            <div className="text-2xl font-bold text-gray-700 dark:text-gray-300">{item.value}</div>
                                        </div>
                                    </Tooltip>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            {/* BOTTOM SECTION */}
            <div className="border rounded-lg p-3 sm:p-4 flex flex-col flex-1 min-h-0 relative">
                <div className="flex items-center justify-between border-b pb-2 mb-3 gap-2">
                    <h2 className="text-sm font-semibold">
                        Management Presence
                    </h2>
                    <input
                        type="text"
                        placeholder="Search employee..."
                        value={employeeSearch}
                        onChange={(e) => setEmployeeSearch(e.target.value)}
                        className="text-xs border rounded px-2 py-1 w-40 sm:w-56 bg-white dark:bg-gray-800 dark:text-white dark:border-gray-600 dark:placeholder-gray-400"
                    />
                </div>
                
                {/* Show skeleton while employees are loading */}
                {employees.length === 0 && <EmployeeListSkeleton />}
                
                {/* Show actual content when loaded */}
                {employees.length > 0 && (
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-2 flex-1 min-h-0 content-start overflow-y-auto">
                        {filteredEmployees.map((emp) => (
                            <div
                                key={emp.EMPLOYID}
                                className={`border rounded-lg p-3 text-xs flex flex-col gap-1 shadow-sm h-[110px] overflow-hidden ${
                                    emp.attendance_status === 'in'      ? 'border-green-300 dark:border-green-700'  :
                                    emp.attendance_status === 'out'     ? 'border-blue-300 dark:border-blue-700'    :
                                    emp.attendance_status === 'leave'   ? 'border-purple-300 dark:border-purple-700':
                                    emp.attendance_status === 'restday' ? 'border-gray-300 dark:border-gray-600'    :
                                                                        'border-red-300 dark:border-red-700'
                                }`}
                            >
                                <div className="flex items-center justify-between gap-1">
                                    <div className="font-semibold text-sm truncate">{emp.EMPNAME}</div>
                                    <span className={`shrink-0 text-[10px] font-semibold px-2 py-0.5 rounded-full ${
                                        emp.attendance_status === 'in'
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                                            : emp.attendance_status === 'out'
                                            ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300'
                                            : emp.attendance_status === 'leave'
                                            ? 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300'
                                            : emp.attendance_status === 'restday'
                                            ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
                                            : emp.last_seen_date
                                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'
                                            : 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300'
                                    }`}>
                                        {emp.attendance_status === 'in'      ? '🟢 In'
                                        : emp.attendance_status === 'out'     ? '🔵 Out'
                                        : emp.attendance_status === 'leave'   ? '🏖️ On Leave'
                                        : emp.attendance_status === 'restday' ? '😴 Rest Day'
                                        : emp.last_seen_date                  ? `Last: ${emp.last_seen_date}`
                                        :                                       '🔴 Absent'}
                                    </span>
                                </div>

                                <div className="text-gray-500 truncate">{emp.JOB_TITLE}</div>

                                {emp.attendance_status === 'leave' && emp.leave_type && (
                                    <div className="text-[10px] text-purple-600 dark:text-purple-400 truncate">
                                        {emp.leave_type}
                                    </div>
                                )}

                                {emp.attendance_status === 'absent' && emp.last_seen_date && (
                                    <div className="text-[10px] text-amber-600 dark:text-amber-400">
                                        No log today
                                    </div>
                                )}

                                <div>
                                    <span className="font-medium">Position:</span> {emp.POSITION_LABEL}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}