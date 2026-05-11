<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\EmployeeService;
use App\Models\EmployeeMasterlist;
use App\Models\WorkScheduler;
use App\Models\ShiftCode;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\Area;
use App\Models\AreaEmployee;
use Inertia\Inertia;
use Carbon\Carbon;

class AttendanceSummaryController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('AttendanceSummary', [
            'app_name' => env('APP_NAME', ''),
        ]);
    }

    public function getData(Request $request)
    {
        try {
            $filters   = $request->only(['company', 'prodline', 'department', 'station']);
            $startDate = $request->get('start_date', now()->toDateString());

            // ── Week range (Sat → Fri) ─────────────────────────────────────
            // The frontend sends the Saturday date directly — parse it and
            // derive Friday as exactly 6 days later. No day-of-week arithmetic
            // needed since the frontend already computes the correct Saturday.
            $saturday = Carbon::parse($startDate)->startOfDay();
            $friday   = $saturday->copy()->addDays(6);
            $dateFrom = $saturday->toDateString();
            $dateTo   = $friday->toDateString();

            $dates = [];
            for ($d = $saturday->copy(); $d->lte($friday); $d->addDay()) {
                $dates[] = $d->toDateString();
            }

            \Log::debug('Week range', ['from' => $dateFrom, 'to' => $dateTo, 'dates' => $dates]);

            $teamMap = [
                1 => 'N',    2 => 'A',              3 => 'B',
                4 => 'C',    5 => 'No Team',         6 => 'AM SHIFT',
                7 => 'DayShift', 8 => 'Onsite (ADGT)', 9 => 'Onsite (AMS)',
            ];

            // ── 1. Employees ───────────────────────────────────────────────
            $query = EmployeeMasterlist::where('ACCSTATUS', 1)
                ->whereIn('EMPPOSITION', [1, 2])
                ->whereNotNull('PRODLINE')
                ->where('PRODLINE', '!=', '')
                ->where('PRODLINE', '!=', 'Security')
                ->where('BIOMETRIC_STATUS', 'Enabled')
                ->where(fn($q) => $q->whereNull('DATEHIRED')
                                    ->orWhere('DATEHIRED', '<=', $dateFrom));

            if (!empty($filters['company']))    $query->where('COMPANY',    $filters['company']);
            if (!empty($filters['prodline']))   $query->where('PRODLINE',   $filters['prodline']);
            if (!empty($filters['department'])) $query->where('DEPARTMENT', $filters['department']);
            if (!empty($filters['station']))    $query->where('STATION',    $filters['station']);

            $page    = max(1, (int) $request->get('page', 1));
            $perPage = max(1, (int) $request->get('per_page', 25));
            $total   = (clone $query)->count();

            $employees = $query->select(['EMPLOYID', 'EMPNAME', 'STATION', 'TEAM'])
                            ->orderBy('EMPNAME')
                            ->offset(($page - 1) * $perPage)
                            ->limit($perPage)
                            ->orderBy('station', 'asc')
                            ->get();

            $empIds = $employees->pluck('EMPLOYID')->map(fn($id) => (string)$id)->toArray();

            if (empty($empIds)) {
                return response()->json(['data' => [], 'total' => 0, 'week_start' => $dateFrom, 'week_end' => $dateTo]);
            }

            // ── 2. Schedule metadata (PHP-side, no per-day queries) ────────
            $allSchedules = WorkScheduler::whereIn('EMPID', $empIds)
                ->whereDate('PAYROLL_DATE_START', '<=', $dateTo)
                ->whereDate('PAYROLL_DATE_END',   '>=', $dateFrom)
                ->get(['EMPID', 'SCHEDULE', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END']);

            $allShiftIds = $allSchedules
                ->flatMap(fn($r) => collect($r->SCHEDULE ?? [])->filter()->map(fn($id) => (int)$id))
                ->unique()->values()->toArray();

            $shiftMeta = [];
            if (!empty($allShiftIds)) {
                ShiftCode::whereIn('SHIFT_CODE_ID', $allShiftIds)
                    ->get(['SHIFT_CODE_ID', 'SHIFTCODE', 'TIME_WINDOWS'])
                    ->each(function ($sc) use (&$shiftMeta) {
                        $raw   = $sc->TIME_WINDOWS;
                        $tw    = is_array($raw) ? $raw : (json_decode($raw, true) ?? []);
                        $code  = strtoupper($sc->SHIFTCODE ?? '');
                        $ti    = $tw[0] ?? null;
                        $shiftMeta[(int)$sc->SHIFT_CODE_ID] = [
                            'time_in'  => $ti,
                            'is_rd'    => str_contains($code, 'RD'),
                            'is_night' => $ti && (int)explode(':', $ti)[0] >= 18,
                        ];
                    });
            }

            $shiftInfoByEmpDate = [];

            foreach ($allSchedules as $sch) {
                $empId     = (string)$sch->EMPID;
                $schedule  = $sch->SCHEDULE ?? [];
                $startStr  = substr((string)$sch->PAYROLL_DATE_START, 0, 10);
                $startTs   = strtotime($startStr);
                $iterStart = max($startStr, $dateFrom);
                $iterEnd   = min(substr((string)$sch->PAYROLL_DATE_END, 0, 10), $dateTo);

                for ($ts = strtotime($iterStart); $ts <= strtotime($iterEnd); $ts += 86400) {
                    $d        = date('Y-m-d', $ts);
                    $dayIndex = (int)(($ts - $startTs) / 86400) + 1;
                    $shiftId  = (int)($schedule[(string)$dayIndex] ?? $schedule[$dayIndex] ?? 0);
                    $meta     = $shiftId ? ($shiftMeta[$shiftId] ?? null) : null;
                    $shiftInfoByEmpDate[$empId][$d] = $meta;

                    // $nightEmpIds tracking removed — single query now handles all employees
                }
            }

            // ── 3. Bulk punch queries (2 queries total) ────────────────────
            $ph   = fn(int $n) => implode(',', array_fill(0, $n, '?'));
$from = date('Y-m-d', strtotime($dateFrom . ' -1 day')); // -1 for night shift tails from Friday night
$to   = date('Y-m-d', strtotime($dateTo   . ' +1 day')); // +1 for night shift tails into Saturday morning

// Combined punch index — one query for both day and night shift lookups

// Single query builds both indexes — eliminates the redundant night-shift re-scan
$hasPunchOnDate = [];
$nightPunchSet  = [];

$rows = DB::connection('dtr')->select("
    SELECT
        employid,
        DATE(datetime) AS punch_date,
        IF(TIME(datetime) < '14:00:00',
           DATE(datetime) - INTERVAL 1 DAY,
           DATE(datetime)) AS night_anchor_date
    FROM (
        SELECT employid, datetime FROM biometric_logs
        WHERE employid IN ({$ph(count($empIds))})
          AND datetime BETWEEN ? AND ?
        UNION ALL
        SELECT employid, datetime FROM biometric_logs_manual
        WHERE employid IN ({$ph(count($empIds))})
          AND datetime BETWEEN ? AND ?
    ) c
    GROUP BY employid, DATE(datetime),
             IF(TIME(datetime) < '14:00:00',
                DATE(datetime) - INTERVAL 1 DAY,
                DATE(datetime))
", array_merge(
    $empIds, [$from . ' 00:00:00', $to . ' 23:59:59'],
    $empIds, [$from . ' 00:00:00', $to . ' 23:59:59']
));

foreach ($rows as $row) {
    $eid        = (string) $row->employid;
    $punchDate  = (string) $row->punch_date;
    $nightAnchor = (string) $row->night_anchor_date;
    $hasPunchOnDate[$eid][$punchDate]              = true;
    $nightPunchSet[$eid . '|' . $nightAnchor]      = true;
}

            // ── 4. Leaves ──────────────────────────────────────────────────
            $onLeaveByEmpDate = [];
            EmployeeLeave::whereIn('EMPLOYID', $empIds)
                ->where('LEAVESTATUS', 'approved')
                ->whereDate('DATESTART', '<=', $dateTo)
                ->whereDate('DATEEND',   '>=', $dateFrom)
                ->get(['EMPLOYID', 'DATESTART', 'DATEEND'])
                ->each(function ($lv) use (&$onLeaveByEmpDate, $dateFrom, $dateTo) {
                    $eid = (string)$lv->EMPLOYID;
                    $s   = max(substr((string)$lv->DATESTART, 0, 10), $dateFrom);
                    $e   = min(substr((string)$lv->DATEEND,   0, 10), $dateTo);
                    for ($ts = strtotime($s); $ts <= strtotime($e); $ts += 86400) {
                        $onLeaveByEmpDate[$eid][date('Y-m-d', $ts)] = true;
                    }
                });

            // ── 5. Holidays ────────────────────────────────────────────────
            $holidaySet = Holiday::whereBetween('HOLIDAY_DATE', [$dateFrom, $dateTo])
                ->pluck('HOLIDAY_DATE')
                ->mapWithKeys(fn($d) => [substr((string)$d, 0, 10) => true])
                ->all();

            // ── 6. Build result (pure PHP, O(1) lookups) ───────────────────

            // Temporary debug — remove after confirming fix
            \Log::debug('hasPunchOnDate sample', array_slice($hasPunchOnDate, 0, 2, true));
            \Log::debug('dates', $dates);
            \Log::debug('empIds', array_slice($empIds, 0, 3));

            $result = [];
            foreach ($employees as $employee) {
                $empId = (string)$employee->EMPLOYID;
                $row   = [
                    'employee_id' => $empId,
                    'emp_name'    => $employee->EMPNAME,
                    'team'        => $teamMap[(int)($employee->TEAM ?? 0)] ?? '—',
                    'station'     => $employee->STATION ?? '—',
                    'attendance'  => [],
                ];

                foreach ($dates as $d) {
                    $isHoliday        = isset($holidaySet[$d]);
                    $isOnLeave        = isset($onLeaveByEmpDate[$empId][$d]);
                    $meta             = $shiftInfoByEmpDate[$empId][$d] ?? null;
                    $isNight          = $meta['is_night'] ?? false;

                    $hasPunch = $isNight
                        ? isset($nightPunchSet[$empId . '|' . $d])
                        : isset($hasPunchOnDate[$empId][$d]);

                    $isFuture = $d > now()->toDateString();

                    if ($meta === null) {
                        // Unscheduled employee
                        if ($hasPunch) {
                            $remark = 'Present';
                        } elseif ($isFuture) {
                            $remark = 'Pending';
                        } elseif ($isHoliday) {
                            $remark = 'Holiday';
                        } else {
                            $remark = 'Absent';
                        }
                    } else {
                        $effectiveRestDay = ($meta['is_rd'] ?? false) || $isHoliday;
                        $remark = EmployeeService::fastRemarksPublic(
                            $hasPunch, $effectiveRestDay, $isOnLeave,
                            null, $meta['time_in'], $d, $isHoliday
                        );
                    }

                    $row['attendance'][$d] = $remark;
                }

                $result[] = $row;
            }

            return response()->json([
                'data'       => $result,
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'last_page'  => max(1, (int) ceil($total / $perPage)),
                'week_start' => $dateFrom,
                'week_end'   => $dateTo,
            ]);

        } catch (\Exception $e) {
            \Log::error('Attendance summary error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

public function getLayout2Data(Request $request)
{
    try {
        $date      = $request->get('date', now()->toDateString());
        $dateStr   = Carbon::parse($date)->toDateString();
        $createdBy = $request->get('created_by', '');
        $isFuture  = $dateStr > now()->toDateString();

        // ── 1. Areas ───────────────────────────────────────────────────────
        $areasQuery = Area::withCount('areaEmployees')
            ->orderBy('category')
            ->orderBy('name');

        if ($createdBy !== '') {
            $areasQuery->where('created_by', $createdBy);
        }

        $areas = $areasQuery->get();

        if ($areas->isEmpty()) {
            return response()->json(['data' => [], 'date' => $date]);
        }

        // ── 2. Employees per area ──────────────────────────────────────────
        $areaEmployeeRows = AreaEmployee::whereIn('area_id', $areas->pluck('id'))
            ->get(['area_id', 'employee_id']);

        $empsByArea = $areaEmployeeRows->groupBy('area_id');

        $allEmpIds = $areaEmployeeRows
            ->pluck('employee_id')
            ->map(fn($id) => (string)$id)
            ->unique()
            ->values()
            ->toArray();

        if (empty($allEmpIds)) {
            $result = $areas->map(fn($area) => $this->emptyAreaRow($area))->toArray();
            return response()->json(['data' => $result, 'date' => $date]);
        }

        // ── 3. Shift info for the date ─────────────────────────────────────
        $allSchedules = WorkScheduler::whereIn('EMPID', $allEmpIds)
            ->whereDate('PAYROLL_DATE_START', '<=', $dateStr)
            ->whereDate('PAYROLL_DATE_END',   '>=', $dateStr)
            ->get(['EMPID', 'SCHEDULE', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END']);

        $allShiftIds = $allSchedules
            ->flatMap(fn($r) => collect($r->SCHEDULE ?? [])->filter()->map(fn($id) => (int)$id))
            ->unique()->values()->toArray();

        $shiftMeta = [];
        if (!empty($allShiftIds)) {
            ShiftCode::whereIn('SHIFT_CODE_ID', $allShiftIds)
                ->get(['SHIFT_CODE_ID', 'SHIFTCODE', 'TIME_WINDOWS'])
                ->each(function ($sc) use (&$shiftMeta) {
                    $raw  = $sc->TIME_WINDOWS;
                    $tw   = is_array($raw) ? $raw : (json_decode($raw, true) ?? []);
                    $code = strtoupper($sc->SHIFTCODE ?? '');
                    $ti   = $tw[0] ?? null;
                    $shiftMeta[(int)$sc->SHIFT_CODE_ID] = [
                        'time_in'  => $ti,
                        'is_rd'    => str_contains($code, 'RD'),
                        'is_night' => $ti && (int)explode(':', $ti)[0] >= 18,
                    ];
                });
        }

        $shiftInfoByEmp = [];
        foreach ($allSchedules as $sch) {
            $empId    = (string)$sch->EMPID;
            $schedule = $sch->SCHEDULE ?? [];
            $startStr = substr((string)$sch->PAYROLL_DATE_START, 0, 10);
            $endStr   = substr((string)$sch->PAYROLL_DATE_END,   0, 10);

            if ($dateStr < $startStr || $dateStr > $endStr) continue;

            $dayIndex = (int)((strtotime($dateStr) - strtotime($startStr)) / 86400) + 1;
            $shiftId  = (int)($schedule[(string)$dayIndex] ?? $schedule[$dayIndex] ?? 0);
            $meta     = $shiftId ? ($shiftMeta[$shiftId] ?? null) : null;
            $shiftInfoByEmp[$empId] = $meta;
        }

        // ── 4. Punch data ──────────────────────────────────────────────────
        $ph   = fn(int $n) => implode(',', array_fill(0, $n, '?'));
        $from = date('Y-m-d', strtotime($dateStr . ' -1 day'));
        $to   = date('Y-m-d', strtotime($dateStr . ' +1 day'));

        $hasPunchOnDate = [];
        $nightPunchSet  = [];

        $rows = DB::connection('dtr')->select("
            SELECT
                employid,
                DATE(datetime) AS punch_date,
                IF(TIME(datetime) < '14:00:00',
                   DATE(datetime) - INTERVAL 1 DAY,
                   DATE(datetime)) AS night_anchor_date
            FROM (
                SELECT employid, datetime FROM biometric_logs
                WHERE employid IN ({$ph(count($allEmpIds))})
                  AND datetime BETWEEN ? AND ?
                UNION ALL
                SELECT employid, datetime FROM biometric_logs_manual
                WHERE employid IN ({$ph(count($allEmpIds))})
                  AND datetime BETWEEN ? AND ?
            ) c
            GROUP BY employid, DATE(datetime),
                     IF(TIME(datetime) < '14:00:00',
                        DATE(datetime) - INTERVAL 1 DAY,
                        DATE(datetime))
        ", array_merge(
            $allEmpIds, [$from . ' 00:00:00', $to . ' 23:59:59'],
            $allEmpIds, [$from . ' 00:00:00', $to . ' 23:59:59']
        ));

        foreach ($rows as $row) {
            $eid         = (string)$row->employid;
            $punchDate   = (string)$row->punch_date;
            $nightAnchor = (string)$row->night_anchor_date;
            $hasPunchOnDate[$eid][$punchDate]         = true;
            $nightPunchSet[$eid . '|' . $nightAnchor] = true;
        }

        // ── 5. Leaves ──────────────────────────────────────────────────────────────────
        $vlBlElTypes = ['VL', 'BL', 'EL', 'SPL', 'BRL', 'VAWC', 'MIL'];
        $mlPlTypes   = ['ML', 'SLW'];
        $slTypes     = ['SL'];

        $onLeaveByEmp   = [];
        $leaveTypeByEmp = [];

        EmployeeLeave::whereIn('EMPLOYID', $allEmpIds)
            ->where('LEAVESTATUS', 'approved')
            ->whereDate('DATESTART', '<=', $dateStr)
            ->whereDate('DATEEND',   '>=', $dateStr)
            ->get(['EMPLOYID', 'DATESTART', 'DATEEND', 'TYPEOFLEAVE'])
            ->each(function ($lv) use (&$onLeaveByEmp, &$leaveTypeByEmp, $dateStr, $vlBlElTypes, $mlPlTypes, $slTypes) {
                $s = substr((string)$lv->DATESTART, 0, 10);
                $e = substr((string)$lv->DATEEND,   0, 10);
                if ($dateStr >= $s && $dateStr <= $e) {
                    $eid  = (string)$lv->EMPLOYID;
                    $type = strtoupper(trim($lv->TYPEOFLEAVE ?? ''));

                    $onLeaveByEmp[$eid] = true;

                    if (in_array($type, $vlBlElTypes)) {
                        $leaveTypeByEmp[$eid] = 'vl_bl_el';
                    } elseif (in_array($type, $mlPlTypes)) {
                        $leaveTypeByEmp[$eid] = 'ml_pl';
                    } elseif (in_array($type, $slTypes)) {
                        $leaveTypeByEmp[$eid] = 'sl';
                    }
                }
            });

        // ── 6. Holiday ─────────────────────────────────────────────────────
        $isHoliday = Holiday::whereDate('HOLIDAY_DATE', $dateStr)->exists();

        // Remarks that count as "present/scheduled"
        $excludedRemarks = ['Absent', 'Rest Day', 'On Leave', 'Holiday'];

        // ── 7. Build result ────────────────────────────────────────────────
        $result = [];
        foreach ($areas as $area) {
            $areaEmpIds = ($empsByArea[$area->id] ?? collect())
                ->pluck('employee_id')
                ->map(fn($id) => (string)$id)
                ->toArray();

            $scheduledCount = 0;
            $restDayOtCount = 0;
            $vlBlElCount    = 0;
            $mlPlCount      = 0;
            $slCount        = 0;
            $absentCount    = 0;

            foreach ($areaEmpIds as $empId) {
                $meta      = $shiftInfoByEmp[$empId] ?? null;
                $isNight   = $meta['is_night'] ?? false;
                $hasPunch  = $isNight
                    ? isset($nightPunchSet[$empId . '|' . $dateStr])
                    : isset($hasPunchOnDate[$empId][$dateStr]);
                $isOnLeave = isset($onLeaveByEmp[$empId]);

                $effectiveRestDay = false;

                if ($meta === null) {
                    if ($hasPunch)      $remark = 'Present';
                    elseif ($isFuture)  $remark = 'Pending';
                    elseif ($isHoliday) $remark = 'Holiday';
                    else                $remark = 'Absent';
                } else {
                    $effectiveRestDay = ($meta['is_rd'] ?? false) || $isHoliday;
                    $remark = EmployeeService::fastRemarksPublic(
                        $hasPunch, $effectiveRestDay, $isOnLeave,
                        null, $meta['time_in'], $dateStr, $isHoliday
                    );
                }

                if (!in_array($remark, $excludedRemarks)) {
                    $scheduledCount++;
                }

                // Rest Day OT
                if ($effectiveRestDay && $hasPunch) {
                    $restDayOtCount++;
                }

                // Leave type counts
                if (in_array($remark, ['On Leave', 'On Leave (Present)'])) {
                    $leaveGroup = $leaveTypeByEmp[$empId] ?? null;
                    if ($leaveGroup === 'vl_bl_el')     $vlBlElCount++;
                    elseif ($leaveGroup === 'ml_pl')    $mlPlCount++;
                    elseif ($leaveGroup === 'sl')       $slCount++;
                    else                                $vlBlElCount++; // uncategorized falls into VL/BL/EL
                }

                // Absent count
                if ($remark === 'Absent') {
                    $absentCount++;
                }
            }

            $requiredHc  = $area->area_employees_count;
            $totalAbsent = $vlBlElCount + $mlPlCount + $slCount + $absentCount;
            $attPct      = $requiredHc > 0 ? round(($scheduledCount / $requiredHc) * 100, 1) : null;
            $absPct      = $requiredHc > 0 ? round(($totalAbsent   / $requiredHc) * 100, 1) : null;

            $result[] = [
                'area'           => $area->name,
                'category'       => $area->category ?? 'Uncategorized',
                'required_hc'    => $requiredHc,
                'scheduled_hc'   => $scheduledCount,
                'certified_ops'  => 0,
                'trainees_hc'    => 0,
                'rest_day_ot'    => $restDayOtCount,
                'total_hc'       => $scheduledCount,
                'attendance_pct' => $attPct,
                'vl_bl_el'       => $vlBlElCount,
                'ml_pl'          => $mlPlCount,
                'sl'             => $slCount,
                'absent'         => $absentCount,
                'suspended'      => 0,
                'total_absent'   => $totalAbsent,
                'absent_pct'     => $absPct,
            ];
        }

        return response()->json(['data' => $result, 'date' => $date]);

    } catch (\Exception $e) {
        \Log::error('Layout2 data error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

// ── Private helper ─────────────────────────────────────────────────────────
private function emptyAreaRow(Area $area): array
{
    return [
        'area'           => $area->name,
        'category'       => $area->category ?? 'Uncategorized',
        'required_hc'    => $area->area_employees_count,
        'scheduled_hc'   => 0,
        'certified_ops'  => 0,
        'trainees_hc'    => 0,
        'rest_day_ot'    => 0,
        'total_hc'       => 0,
        'attendance_pct' => null,
        'vl_bl_el'       => 0,
        'ml_pl'          => 0,
        'sl'             => 0,
        'absent'         => 0,
        'suspended'      => 0,
        'total_absent'   => 0,
        'absent_pct'     => null,
    ];
}
    public function getEmployees(Request $request)
        {
            $search = trim($request->get('q', ''));

            $query = EmployeeMasterlist::where('ACCSTATUS', 1)
                ->select(['EMPLOYID', 'EMPNAME'])
                ->orderBy('EMPNAME');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('EMPNAME',  'like', "%{$search}%")
                    ->orWhere('EMPLOYID', 'like', "%{$search}%");
                });
            }

            return response()->json([
                'data' => $query->limit(20)->get(),
            ]);
        }

    // GET /attendance-summary/areas
    public function getAreas(Request $request)
    {
        $areas = Area::orderBy('category')->orderBy('name')->get(['id', 'name', 'category']);
        return response()->json(['data' => $areas]);
    }

    public function getCreators()
        {
            $creatorIds = Area::whereNotNull('created_by')
                ->where('created_by', '!=', '')
                ->distinct()
                ->pluck('created_by');

            $creators = $creatorIds->map(function ($id) {
                $name = EmployeeMasterlist::where('EMPLOYID', $id)->value('EMPNAME');
                return [
                    'id'   => $id,
                    'name' => $name ?? $id, // fallback to ID if name not found
                ];
            })->sortBy('name')->values();

            return response()->json(['data' => $creators]);
        }

    // GET /attendance-summary/areas/{id}/employees
    public function getAreaEmployees(int $id)
    {
        $assignments = AreaEmployee::where('area_id', $id)
            ->get(['employee_id'])
            ->map(function ($row) {
                $emp = EmployeeMasterlist::where('EMPLOYID', $row->employee_id)
                    ->value('EMPNAME');
                return [
                    'employee_id' => $row->employee_id,
                    'emp_name'    => $emp ?? $row->employee_id,
                ];
            });

        return response()->json(['data' => $assignments]);
    }

    // POST /attendance-summary/areas
    public function saveArea(Request $request)
    {
        $request->validate([
            'area_id'        => 'nullable|exists:areas,id',
            'area_name'      => 'required_without:area_id|string|max:255',
            'category' => 'required|string|max:255',
            'employee_ids'   => 'required|array|min:1',
            'employee_ids.*' => 'required|string',
        ]);

        // Get creator info from session
        $createdBy = session('emp_data.emp_id') ?? auth()->id() ?? 'system';
        
        if ($request->filled('area_id')) {
            $area = Area::findOrFail($request->area_id);
            // Update category if provided
            if ($request->filled('category')) {
                $area->category = $request->category;
                $area->save();
            }
        } else {
            $area = Area::firstOrCreate(
                [
                    'name'     => trim($request->area_name),
                    'category' => trim($request->category),
                ],
                ['created_by' => $createdBy]
            );
        }

        // Upsert employees with created_by info
        $rows = array_map(function($eid) use ($area, $createdBy) {
            return [
                'area_id'     => $area->id,
                'employee_id' => (string) $eid,
                'created_by'  => $createdBy,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }, $request->employee_ids);

        AreaEmployee::upsert(
            $rows,
            ['area_id', 'employee_id'],
            ['updated_at', 'created_by'] // Update created_by on conflict if needed
        );

        return response()->json([
            'message' => 'Saved successfully.',
            'area'    => $area->only(['id', 'name', 'category', 'created_by']),
        ]);
    }

}