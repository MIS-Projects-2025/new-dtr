<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;
use App\Services\EmployeeService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class BioManagementController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService,
        private \App\Services\DtrLogService $dtrLogService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('BioManagement', [
            'app_name' => env('APP_NAME', ''),
        ]);
    }

        public function importLogs(Request $request)
        {
            $request->validate([
                'file' => 'required|file|max:51200',
            ]);

            if (!$request->file('file')->isValid()) {
                return response()->json(['error' => true, 'message' => 'Invalid file upload.'], 422);
            }

            $extension = strtolower($request->file('file')->getClientOriginalExtension());
            if ($extension !== 'dat') {
                return response()->json(['error' => true, 'message' => 'Only .dat files are allowed.'], 422);
            }

            $punchTypeMap = [
                '0' => 'check_in',
                '1' => 'check_out',
                '2' => 'break_out',
                '3' => 'break_in',
            ];

            $inserted   = 0;
            $duplicates = 0;
            $errors     = 0;

            $file  = $request->file('file');
            $lines = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            // Parse all lines first
            $parsed = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) < 6) { $errors++; continue; }

                try {
                    $datetime = Carbon::parse(trim($cols[1]) . ' ' . trim($cols[2]))->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    $errors++;
                    continue;
                }

                $parsed[] = [
                    'employid'   => trim($cols[0]),
                    'datetime'   => $datetime,
                    'device_no'  => trim($cols[3]),
                    'punch_type' => $punchTypeMap[trim($cols[4])] ?? 'check_in',
                    'auth_mode'  => trim($cols[5]),
                    'employee_category' => 'import',
                ];
            }

            if (!empty($parsed)) {
                $employIds = array_unique(array_column($parsed, 'employid'));
                $datetimes = array_unique(array_column($parsed, 'datetime'));

                $existingKeys = DB::table('biometric_logs')
                    ->whereIn('employid', $employIds)
                    ->whereIn('datetime', $datetimes)
                    ->select(DB::raw("CONCAT(employid, '|', datetime, '|', punch_type) as rec_key"))
                    ->get()->pluck('rec_key')->flip()->all();

                $existingManualKeys = DB::table('biometric_logs_manual')
                    ->whereIn('employid', $employIds)
                    ->whereIn('datetime', $datetimes)
                    ->select(DB::raw("CONCAT(employid, '|', datetime, '|', punch_type) as rec_key"))
                    ->get()->pluck('rec_key')->flip()->all();

                $toInsert = [];
                foreach ($parsed as $row) {
                    $key = $row['employid'] . '|' . $row['datetime'] . '|' . $row['punch_type'];

                    if (isset($existingKeys[$key]) || isset($existingManualKeys[$key])) {
                        $duplicates++;
                        continue;
                    }

                    $toInsert[] = [
                        ...$row,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($toInsert)) {
                    try {
                        foreach (array_chunk($toInsert, 500) as $chunk) {
                            DB::table('biometric_logs_manual')->insert($chunk);
                            $inserted += count($chunk);
                        }
                    } catch (\Exception $e) {
                        \Log::error('Bio import insert error: ' . $e->getMessage());
                        $errors += count($toInsert);
                    }
                }
            }

            return response()->json([
                'inserted'   => $inserted,
                'duplicates' => $duplicates,
                'errors'     => $errors,
                'message'    => "Import complete: {$inserted} inserted, {$duplicates} skipped (duplicates), {$errors} errors.",
            ]);
        }
    /**
     * Get manual logs with employee names from EmployeeMasterlist
     */
public function getManualLogs(Request $request)
{
    // Fetch ALL logs (no pagination yet)
    $allLogs = DB::table('biometric_logs_manual')
        ->orderBy('datetime', 'desc')
        ->get();
    
    // Get unique employee IDs from all logs
    $employeeIds = $allLogs->pluck('employid')->unique()->toArray();
    
    // Fetch employee names
    $employees = collect();
    if (!empty($employeeIds)) {
        try {
            $employees = \App\Models\EmployeeMasterlist::whereIn('EMPLOYID', $employeeIds)
                ->get(['EMPLOYID', 'EMPNAME'])
                ->keyBy('EMPLOYID');
        } catch (\Exception $e) {
            \Log::error('Error fetching employees: ' . $e->getMessage());
        }
    }
    
    // Attach employee names to each log
    $allLogs->transform(function ($log) use ($employees) {
        $log->employee_name = isset($employees[$log->employid]) ? $employees[$log->employid]->EMPNAME : 'Unknown Employee';
        return $log;
    });
    
    // If there's a search term, filter the results
    if ($request->filled('search')) {
        $search = strtolower($request->search);
        $filteredItems = [];
        
        foreach ($allLogs as $item) {
            // Convert all searchable fields to lowercase for case-insensitive search
            $employId = strtolower($item->employid);
            $employeeName = strtolower($item->employee_name);
            $punchType = strtolower($item->punch_type);
            $category = strtolower($item->employee_category);
            $authMode = strtolower($item->auth_mode);
            $datetime = strtolower($item->datetime);
            
            // Check if search term exists in any field
            if (strpos($employId, $search) !== false ||
                strpos($employeeName, $search) !== false ||
                strpos($punchType, $search) !== false ||
                strpos($category, $search) !== false ||
                strpos($authMode, $search) !== false ||
                strpos($datetime, $search) !== false) {
                $filteredItems[] = $item;
            }
        }
        
        $collection = collect($filteredItems);
    } else {
        $collection = $allLogs;
    }
    
    // Apply pagination to the filtered/complete collection
    $total = $collection->count();
    $perPage = 50;
    $currentPage = $request->get('page', 1);
    $offset = ($currentPage - 1) * $perPage;
    
    $logs = new \Illuminate\Pagination\LengthAwarePaginator(
        $collection->slice($offset, $perPage)->values(),
        $total,
        $perPage,
        $currentPage,
        ['path' => $request->url(), 'query' => $request->query()]
    );
    
    return response()->json($logs);
}

public function searchEmployees(Request $request)
{
    $search = $request->get('search');
    $employees = \App\Models\EmployeeMasterlist::where('ACCSTATUS', 1)
        ->whereIn('EMPPOSITION', [1, 2])
        ->where(function($q) use ($search) {
            $q->where('EMPNAME', 'LIKE', "%{$search}%")
              ->orWhere('EMPLOYID', 'LIKE', "%{$search}%");
        })
        ->limit(10)
        ->get(['EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'JOB_TITLE']);
    
    return response()->json($employees);
}

public function addManualLog(Request $request)
{
    try {
        \Log::info('Add manual log request received:', $request->all());
        
        DB::connection('dtr')->table('biometric_logs_manual')->insert([
            'employid' => $request->employid,
            'datetime' => $request->datetime,
            'punch_type' => $request->punch_type,
            'employee_category' => 'manual',  // Note: three 'e's
            'auth_mode' => $request->auth_mode ?? null,
            'device_number' => $request->device_number ?? null,
            'device_ip' => $request->device_ip ?? null,
            'work_code' => $request->work_code ?? null,
            'state' => $request->state ?? null,
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Manual log added successfully'
        ]);
    } catch (\Exception $e) {
        \Log::error('Add manual log error: ' . $e->getMessage());
        return response()->json([
            'error' => true,
            'message' => $e->getMessage()
        ], 500);
    }
}

public function getObEmployees(Request $request)
{
    $date = $request->get('date', '');
    if (!$date) return response()->json([]);

    $obs = \App\Models\ObRecord::whereIn('STATUS', [1, 2])
        ->where('FORM_TYPE', 'ob')
        ->whereDate('DATE_OB_FROM', '<=', $date)
        ->whereDate('DATE_OB_TO',   '>=', $date)
        ->get(['EMPID', 'TIME_FROM', 'TIME_TO']);

    if ($obs->isEmpty()) return response()->json([]);

    $empIds    = $obs->pluck('EMPID')->unique()->values()->toArray();
    $obByEmp   = $obs->keyBy('EMPID');
    $employees = \App\Models\EmployeeMasterlist::whereIn('EMPLOYID', $empIds)
        ->get(['EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'JOB_TITLE']);

    // ── Batch: resolve time windows for all employees at once ─────────────
    $timeWindowsMap  = [];
    $scheduleTypeMap = [];
    foreach ($employees as $emp) {
        $timeWindowsMap[$emp->EMPLOYID]  = $this->resolveTimeWindowsForDate($emp->EMPLOYID, $date);
        $scheduleTypeMap[$emp->EMPLOYID] = 'Normal';
    }

    // ── Single bulk log resolution for ALL employees ───────────────────────
    $actualLogsAll = $this->dtrLogService->resolveLogsForEmployees(
        $empIds,
        $timeWindowsMap,
        $scheduleTypeMap,
        $date
    );

    $result = [];

    foreach ($employees as $emp) {
        $employId = $emp->EMPLOYID;
        $ob       = $obByEmp[$employId] ?? null;
        if (!$ob) continue;

        $obFrom     = substr($ob->TIME_FROM, 0, 5);
        $obTo       = substr($ob->TIME_TO,   0, 5);
        $slots      = $this->analyzeSlots(
            $timeWindowsMap[$employId],
            $actualLogsAll[$employId] ?? [],
            $obFrom,
            $obTo
        );

        $hasMissingInObRange = collect($slots)->contains(
            fn($s) => $s['in_ob_range'] && $s['status'] === 'missing'
        );

        if (!$hasMissingInObRange) continue;

        $result[] = [
            'EMPLOYID'   => $employId,
            'EMPNAME'    => $emp->EMPNAME,
            'DEPARTMENT' => $emp->DEPARTMENT,
            'JOB_TITLE'  => $emp->JOB_TITLE,
            'ob_from'    => $obFrom,
            'ob_to'      => $obTo,
            'slots'      => $slots,
        ];
    }

    return response()->json($result);
}

// ── Resolve TIME_WINDOWS for employee on a given date ────────────────────────
private function resolveTimeWindowsForDate(string $employId, string $date): array
{
    $schedule = \App\Models\WorkScheduler::where('EMPID', $employId)
        ->whereDate('PAYROLL_DATE_START', '<=', $date)
        ->whereDate('PAYROLL_DATE_END',   '>=', $date)
        ->first(['SCHEDULE', 'PAYROLL_DATE_START']);

    if (!$schedule) return [];

    $payrollStart = Carbon::parse($schedule->PAYROLL_DATE_START)->startOfDay();
    $target       = Carbon::parse($date)->startOfDay();
    $dayIndex     = (int) $payrollStart->diffInDays($target) + 1;

    $scheduleArray = is_string($schedule->SCHEDULE)
        ? (json_decode($schedule->SCHEDULE, true) ?? [])
        : (array) $schedule->SCHEDULE;

    $shiftId = $scheduleArray[(string) $dayIndex] ?? $scheduleArray[$dayIndex] ?? null;
    if (!$shiftId) return [];

    $shiftCode = \App\Models\ShiftCode::find($shiftId);
    if (!$shiftCode?->TIME_WINDOWS) return [];

    $tw = $shiftCode->TIME_WINDOWS;
    return is_array($tw) ? $tw : (json_decode($tw, true) ?? []);
}

// ── Resolve actual logs using biometric + manual tables ───────────────────────
private function resolveActualLogs(string $employId, string $date, array $tw): array
{
    $from = Carbon::parse($date)->subDay()->toDateString();
    $to   = Carbon::parse($date)->addDays(2)->toDateString();

    $isNight = !empty($tw[0]) && (int) explode(':', $tw[0])[0] >= 18;

    $punchMap = [
        'check_in'  => 'time_in',
        'check_out' => 'time_out',
        'break_out' => null, // assigned by proximity
        'break_in'  => null,
    ];

    $slots = [
        'time_in'     => null,
        'break_out_1' => null,
        'break_in_1'  => null,
        'lunch_out'   => null,
        'lunch_in'    => null,
        'break_out_2' => null,
        'break_in_2'  => null,
        'time_out'    => null,
    ];

    $breakOutSlots = ['break_out_1' => 1, 'lunch_out' => 3, 'break_out_2' => 5];
    $breakInSlots  = ['break_in_1'  => 2, 'lunch_in'  => 4, 'break_in_2'  => 6];

    $toMins = fn($t) => (int) explode(':', $t)[0] * 60 + (int) explode(':', $t)[1];

    $assignToSlot = function (string $time, array $candidateSlots) use (&$slots, $tw, $toMins) {
        $best     = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($candidateSlots as $slotKey => $twIdx) {
            if ($slots[$slotKey] !== null) continue;
            $expected = $tw[$twIdx] ?? null;
            $diff     = $expected ? abs($toMins($time) - $toMins($expected)) : 9999;
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = $slotKey; }
        }
        if ($best) $slots[$best] = $time;
    };

    foreach (['biometric_logs' => 'auto', 'biometric_logs_manual' => 'manual'] as $table => $source) {
        $logs = DB::table($table)
            ->where('employid', $employId)
            ->whereBetween('datetime', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('datetime')
            ->get(['datetime', 'punch_type']);

        foreach ($logs as $log) {
            $time      = Carbon::parse($log->datetime)->format('H:i');
            $punchType = $log->punch_type;

            match ($punchType) {
                'check_in', '0'  => ($slots['time_in']  ??= $time),
                'check_out', '1' => ($slots['time_out']   = $time),
                'break_out', '2' => $assignToSlot($time, $breakOutSlots),
                'break_in',  '3' => $assignToSlot($time, $breakInSlots),
                default          => null,
            };
        }
    }

    return $slots;
}

// ── Analyze each slot against OB range ───────────────────────────────────────
private function analyzeSlots(array $tw, array $actualLogs, string $obFrom, string $obTo): array
{
    $toMins = fn(?string $t) => $t ? (int) explode(':', $t)[0] * 60 + (int) explode(':', $t)[1] : null;

    $obFromMins = $toMins($obFrom);
    $obToMins   = $toMins($obTo);

    $slotDefs = [
        ['key' => 'time_in',  'twIdx' => 0, 'label' => 'Time In',  'fallback' => $obFrom],
        ['key' => 'time_out', 'twIdx' => 7, 'label' => 'Time Out', 'fallback' => $obTo],
    ];

    $result = [];

    foreach ($slotDefs as $slot) {
        $expectedTime = $tw[$slot['twIdx']] ?? $slot['fallback'];
        $actualTime   = $actualLogs[$slot['key']] ?? null;
        $expectedMins = $toMins($expectedTime);

        // A slot is "in OB range" if:
        // 1. The expected time falls directly within the OB window, OR
        // 2. The OB overlaps with the shift (employee was supposed to be present
        //    during OB hours, so missing time_in/out is still attributable to OB)
        $inObRange = $expectedMins !== null
            && $expectedMins >= $obFromMins
            && $expectedMins <= $obToMins;
        $status = match(true) {
            $actualTime !== null   => 'has_log',
            !$inObRange            => 'out_of_range',
            $expectedTime === null => 'out_of_range',
            default                => 'missing',
        };

        $result[] = [
            'key'         => $slot['key'],
            'label'       => $slot['label'],
            'expected'    => $expectedTime,
            'actual'      => $actualTime,
            'in_ob_range' => $inObRange,
            'status'      => $status,
        ];
    }

    return $result;
}
public function getObDates(Request $request)
{
    $minDate = '2026-01-01';
    $today   = now()->toDateString();

    $obs = \App\Models\ObRecord::whereIn('STATUS', [1, 2])
        ->where('FORM_TYPE', 'ob')
        ->whereDate('DATE_OB_TO', '>=', $minDate)
        ->whereDate('DATE_OB_FROM', '<=', $today)
        ->get(['DATE_OB_FROM', 'DATE_OB_TO']);

    if ($obs->isEmpty()) return response()->json([]);

    $dates = collect();
    foreach ($obs as $ob) {
        $start = Carbon::parse(max(substr($ob->DATE_OB_FROM, 0, 10), $minDate));
        $end   = Carbon::parse(min(substr($ob->DATE_OB_TO,   0, 10), $today));
        while ($start->lte($end)) {
            $dates->push($start->toDateString());
            $start->addDay();
        }
    }

    return response()->json($dates->unique()->sort()->values());
}

public function getNewlyHiredEmployees(Request $request)
{
    $date = $request->get('date', '');
    if (!$date) return response()->json([]);

    $employees = \App\Models\EmployeeMasterlist::whereDate('DATEHIRED', $date)
        ->where('ACCSTATUS', 1)
        ->get(['EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'JOB_TITLE', 'DATEHIRED']);

    if ($employees->isEmpty()) return response()->json([]);

    $employIds = $employees->pluck('EMPLOYID')->toArray();

    // Use DtrLogService — same logic as admin dashboard and DTR page
    // This correctly handles night shifts, date windowing, deduplication, etc.
    $timeWindowsMap  = array_fill_keys($employIds, []);
    $scheduleTypeMap = array_fill_keys($employIds, 'Normal');

    $resolvedLogs = $this->dtrLogService->resolveLogsForEmployees(
        $employIds, $timeWindowsMap, $scheduleTypeMap, $date
    );

    $result = [];

    foreach ($employees as $emp) {
        $employId   = $emp->EMPLOYID;
        $actualLogs = $resolvedLogs[$employId] ?? [];

        $timeIn  = !empty($actualLogs['time_in'])  && $actualLogs['time_in']  !== '--:--'
            ? $actualLogs['time_in']  : null;
        $timeOut = !empty($actualLogs['time_out']) && $actualLogs['time_out'] !== '--:--'
            ? $actualLogs['time_out'] : null;

        $slotAnalysis = [
            [
                'key'         => 'time_in',
                'label'       => 'Time In',
                'actual'      => $timeIn,
                'status'      => $timeIn !== null ? 'has_log' : 'missing',
                'in_ob_range' => true,
            ],
            [
                'key'         => 'time_out',
                'label'       => 'Time Out',
                'actual'      => $timeOut,
                'status'      => $timeOut !== null ? 'has_log' : 'missing',
                'in_ob_range' => true,
            ],
        ];

        $hasMissing = collect($slotAnalysis)->contains(fn($s) => $s['status'] === 'missing');
        if (!$hasMissing) continue;

        $result[] = [
            'EMPLOYID'   => $employId,
            'EMPNAME'    => $emp->EMPNAME,
            'DEPARTMENT' => $emp->DEPARTMENT,
            'JOB_TITLE'  => $emp->JOB_TITLE,
            'date_hired' => $emp->DATEHIRED ? Carbon::parse($emp->DATEHIRED)->format('Y-m-d') : null,
            'slots'      => $slotAnalysis,
        ];
    }

    return response()->json($result);
}

public function getNewlyHiredDates(Request $request)
{
    $minDate = '2026-01-01';
    $today   = now()->toDateString();

    // Get distinct DATEHIRED values that fall within our range
    $dates = \App\Models\EmployeeMasterlist::where('ACCSTATUS', 1)
        ->whereNotNull('DATEHIRED')
        ->whereDate('DATEHIRED', '>=', $minDate)
        ->whereDate('DATEHIRED', '<=', $today)
        ->distinct()
        ->pluck('DATEHIRED')
        ->map(fn($d) => substr($d, 0, 10))
        ->unique()
        ->sort()
        ->values()
        ->toArray();

    return response()->json($dates);
}

public function getFtwDates(Request $request)
{
    $minDate = '2026-01-01';
    $today   = now()->toDateString();

    $dates = \App\Models\FtwTbl::whereIn('recommendation', [1, 2, 3])
        ->whereNotNull('date_created')
        ->whereDate('date_created', '>=', $minDate)
        ->whereDate('date_created', '<=', $today)
        ->distinct()
        ->pluck('date_created')
        ->map(fn($d) => Carbon::parse($d)->toDateString())
        ->unique()
        ->sort()
        ->values();

    return response()->json($dates);
}

public function getFtwEmployees(Request $request)
{
    $date = $request->get('date', '');
    if (!$date) return response()->json([]);

    $records = \App\Models\FtwTbl::whereIn('recommendation', [1, 2, 3])
        ->whereDate('date_created', $date)
        ->get(['emp_no', 'recommendation', 'emp_time_in', 'emp_diagnose']);

    if ($records->isEmpty()) return response()->json([]);

    $empIds    = $records->pluck('emp_no')->unique()->values()->toArray();
    $employees = \App\Models\EmployeeMasterlist::whereIn('EMPLOYID', $empIds)
        ->get(['EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'JOB_TITLE'])
        ->keyBy('EMPLOYID');
    $byEmp     = $records->groupBy('emp_no');

    // ── Single bulk query for existing logs across all employees ──────────
    $from = Carbon::parse($date)->startOfDay()->toDateTimeString();
    $to   = Carbon::parse($date)->endOfDay()->toDateTimeString();

    $existingSet = [];
    foreach (['biometric_logs', 'biometric_logs_manual'] as $table) {
        DB::table($table)
            ->whereIn('employid', $empIds)
            ->whereBetween('datetime', [$from, $to])
            ->select('employid', 'punch_type')
            ->get()
            ->each(fn($r) => $existingSet[$r->employid][$r->punch_type] = true);
    }

    $result = [];

    foreach ($byEmp as $empId => $empRecords) {
        $emp = $employees[$empId] ?? null;
        if (!$emp) continue;

        $slots = [];

        foreach ($empRecords as $rec) {
            $recommendation = (int) $rec->recommendation;
            $time           = $rec->emp_time_in?->format('H:i');
            $punchType      = $recommendation === 1 ? 'check_in' : 'check_out';
            $slotKey        = $recommendation === 1 ? 'time_in'  : 'time_out';
            $slotLabel      = $recommendation === 1 ? 'Time In'  : 'Time Out';
            $hasLog         = $time && isset($existingSet[$empId][$punchType]);

            $slots[] = [
                'key'         => $slotKey,
                'label'       => $slotLabel,
                'expected'    => $time,
                'actual'      => $hasLog ? $time : null,
                'status'      => $hasLog ? 'has_log' : ($time ? 'missing' : 'out_of_range'),
                'in_ob_range' => true,
                'punch_type'  => $punchType,
                'time_value'  => $time,
            ];
        }

        $hasMissing = collect($slots)->contains(fn($s) => $s['status'] === 'missing');
        if (!$hasMissing) continue;

        $result[] = [
            'EMPLOYID'       => $empId,
            'EMPNAME'        => $emp->EMPNAME,
            'DEPARTMENT'     => $emp->DEPARTMENT,
            'JOB_TITLE'      => $emp->JOB_TITLE,
            'slots'          => $slots,
            'recommendation' => $empRecords->first()->recommendation,
            'diagnose'       => $empRecords->first()->emp_diagnose ?? null,
        ];
    }

    return response()->json($result);
}

// ── Check if a specific punch_type log already exists for an employee on a date ──
private function hasExistingLog(string $empId, string $date, string $punchType): bool
{
    $from = Carbon::parse($date)->startOfDay()->toDateTimeString();
    $to   = Carbon::parse($date)->endOfDay()->toDateTimeString();

    $inBio = DB::table('biometric_logs')
        ->where('employid', $empId)
        ->where('punch_type', $punchType)
        ->whereBetween('datetime', [$from, $to])
        ->exists();

    if ($inBio) return true;

    return DB::table('biometric_logs_manual')
        ->where('employid', $empId)
        ->where('punch_type', $punchType)
        ->whereBetween('datetime', [$from, $to])
        ->exists();
}

// ── Helper: check if a single FTW employee+date combo has a missing log ──
private function ftwEmployeeHasMissingLog(string $empId, string $date, int $recommendation, $timeIn): bool
{
    if (!$timeIn) return false;

    $punchType = $recommendation === 1 ? 'check_in' : 'check_out'; // 2 & 3 → check_out

    return !$this->hasExistingLog($empId, $date, $punchType);
}

public function exportLogs(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'type'      => 'required|in:with_breaks,without_breaks',
        ]);

        $dateFrom = $request->date_from;
        $dateTo   = $request->date_to;
        $type     = $request->type;

        if (\Carbon\Carbon::parse($dateFrom)->diffInDays(\Carbon\Carbon::parse($dateTo)) > 31) {
            return response()->json(['error' => 'Date range cannot exceed 31 days.'], 422);
        }

        // Generate a unique job ID for polling
        $jobId = (string) \Illuminate\Support\Str::uuid();

        // Set initial cache state immediately so frontend can start polling
        \Illuminate\Support\Facades\Cache::put("export_{$jobId}", [
            'status'   => 'processing',
            'progress' => 0,
            'message'  => 'Queued...',
            'filename' => null,
        ], now()->addMinutes(10));

        \App\Jobs\ExportBiometricLogs::dispatch($jobId, $dateFrom, $dateTo, $type);

        return response()->json(['job_id' => $jobId]);
    }

    public function exportProgress(Request $request)
    {
        $jobId  = $request->get('job_id');
        $state  = \Illuminate\Support\Facades\Cache::get("export_{$jobId}");

        if (!$state) {
            return response()->json([
                'status'   => 'not_found',
                'progress' => 0,
                'message'  => 'Job not found or expired.',
                'filename' => null,
            ]);
        }

        return response()->json($state);
    }

    public function exportDownload(Request $request)
    {
        $jobId = $request->get('job_id');
        $state = \Illuminate\Support\Facades\Cache::get("export_{$jobId}");

        if (!$state || $state['status'] !== 'done' || empty($state['filename'])) {
            abort(404, 'Export file not ready or not found.');
        }

        $path = storage_path('app/exports/' . $state['filename']);

        if (!file_exists($path)) {
            abort(404, 'Export file missing from disk.');
        }

        // Clean up cache after download
        \Illuminate\Support\Facades\Cache::forget("export_{$jobId}");

        return response()->download($path, $state['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function exportTiming(Request $request)
{
    $jobId  = $request->get('job_id');
    $timing = \Illuminate\Support\Facades\Cache::get("export_timing_{$jobId}");
 
    if (!$timing) {
        return response()->json(['error' => 'No timing data found for this job_id. Run an export first.'], 404);
    }
 
    return response()->json($timing);
}


/**
     * Merge a sorted list of row indices into contiguous range strings.
     * e.g. [2,3,4,7,8,10] → ["A2:E4", "A7:E8", "A10:E10"]
     */
    private function buildContiguousRanges(array $rowIndices, string $lastCol): array
    {
        if (empty($rowIndices)) return [];

        sort($rowIndices);
        $ranges = [];
        $start  = $rowIndices[0];
        $prev   = $rowIndices[0];

        for ($i = 1; $i < count($rowIndices); $i++) {
            if ($rowIndices[$i] === $prev + 1) {
                $prev = $rowIndices[$i];
            } else {
                $ranges[] = "A{$start}:{$lastCol}{$prev}";
                $start    = $rowIndices[$i];
                $prev     = $rowIndices[$i];
            }
        }

        $ranges[] = "A{$start}:{$lastCol}{$prev}";
        return $ranges;
    }
}