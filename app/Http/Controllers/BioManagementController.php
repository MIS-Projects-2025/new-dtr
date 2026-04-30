<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;
use App\Services\EmployeeService;

class BioManagementController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService,
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

    // Get all OB records covering this date
    $obs = \App\Models\ObRecord::whereIn('STATUS', [1, 2])
        ->where('FORM_TYPE', 'ob')
        ->whereDate('DATE_OB_FROM', '<=', $date)
        ->whereDate('DATE_OB_TO',   '>=', $date)
        ->get(['EMPID', 'TIME_FROM', 'TIME_TO']);

    if ($obs->isEmpty()) return response()->json([]);

    $empIds = $obs->pluck('EMPID')->unique()->values()->toArray();

    // Key employees by EMPID for quick lookup
    $obByEmp = $obs->keyBy('EMPID');

    $employees = \App\Models\EmployeeMasterlist::whereIn('EMPLOYID', $empIds)
        ->get(['EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'JOB_TITLE']);

    $result = [];

    foreach ($employees as $emp) {
        $employId = $emp->EMPLOYID;
        $ob       = $obByEmp[$employId] ?? null;
        if (!$ob) continue;

        $obFrom = substr($ob->TIME_FROM, 0, 5); // HH:MM
        $obTo   = substr($ob->TIME_TO,   0, 5);

        // ── Get shift time windows for this date ──────────────────────────
        $tw = $this->resolveTimeWindowsForDate($employId, $date);

        // ── Get actual logs for this date ─────────────────────────────────
        $actualLogs = $this->resolveActualLogs($employId, $date, $tw);

        // ── Build slot analysis ───────────────────────────────────────────
        $slots = $this->analyzeSlots($tw, $actualLogs, $obFrom, $obTo);

        // Only include this employee if at least one slot is missing within OB range
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

    $payrollStart = \Carbon\Carbon::parse($schedule->PAYROLL_DATE_START)->startOfDay();
    $target       = \Carbon\Carbon::parse($date)->startOfDay();
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
    $from = \Carbon\Carbon::parse($date)->subDay()->toDateString();
    $to   = \Carbon\Carbon::parse($date)->addDays(2)->toDateString();

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
        $logs = \Illuminate\Support\Facades\DB::table($table)
            ->where('employid', $employId)
            ->whereBetween('datetime', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('datetime')
            ->get(['datetime', 'punch_type']);

        foreach ($logs as $log) {
            $time      = \Carbon\Carbon::parse($log->datetime)->format('H:i');
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
        ['key' => 'time_in',     'twIdx' => 0, 'label' => 'Time In'],
        ['key' => 'break_out_1', 'twIdx' => 1, 'label' => 'Break Out 1'],
        ['key' => 'break_in_1',  'twIdx' => 2, 'label' => 'Break In 1'],
        ['key' => 'lunch_out',   'twIdx' => 3, 'label' => 'Lunch Out'],
        ['key' => 'lunch_in',    'twIdx' => 4, 'label' => 'Lunch In'],
        ['key' => 'break_out_2', 'twIdx' => 5, 'label' => 'Break Out 2'],
        ['key' => 'break_in_2',  'twIdx' => 6, 'label' => 'Break In 2'],
        ['key' => 'time_out',    'twIdx' => 7, 'label' => 'Time Out'],
    ];

    $result = [];

    foreach ($slotDefs as $slot) {
        $expectedTime = $tw[$slot['twIdx']] ?? null;
        $actualTime   = $actualLogs[$slot['key']] ?? null;
        $expectedMins = $toMins($expectedTime);

        // Is this slot's expected time within the OB range?
        $inObRange = $expectedMins !== null
            && $expectedMins >= $obFromMins
            && $expectedMins <= $obToMins;

        $status = match(true) {
            $actualTime !== null                    => 'has_log',   // already logged
            !$inObRange                             => 'out_of_range', // not covered by OB
            $expectedTime === null                  => 'out_of_range', // no schedule slot
            default                                 => 'missing',   // in OB range, no log
        };

        $result[] = [
            'key'          => $slot['key'],
            'label'        => $slot['label'],
            'expected'     => $expectedTime,
            'actual'       => $actualTime,
            'in_ob_range'  => $inObRange,
            'status'       => $status,  // 'missing' | 'has_log' | 'out_of_range'
        ];
    }

    return $result;
}

// Separate endpoint — just returns the distinct dates that have any OB record
public function getObDates(Request $request)
{
    $minDate = '2026-01-01';
    $today   = now()->toDateString();

    // Get all OB records from Jan 2026 onwards
    $obs = \App\Models\ObRecord::whereIn('STATUS', [1, 2])
        ->where('FORM_TYPE', 'ob')
        ->whereDate('DATE_OB_TO', '>=', $minDate)
        ->whereDate('DATE_OB_FROM', '<=', $today)
        ->get(['EMPID', 'DATE_OB_FROM', 'DATE_OB_TO']);

    if ($obs->isEmpty()) return response()->json([]);

    // Expand all OB records into individual dates
    $datesToCheck = collect();
    foreach ($obs as $ob) {
        $start = \Carbon\Carbon::parse(max($ob->DATE_OB_FROM, $minDate));
        $end   = \Carbon\Carbon::parse(min($ob->DATE_OB_TO, $today));
        while ($start->lte($end)) {
            $datesToCheck->push($start->toDateString());
            $start->addDay();
        }
    }

    $datesToCheck = $datesToCheck->unique()->sort()->values();

    // For each date, check if there is at least one employee with a missing log
    $validDates = [];
    foreach ($datesToCheck as $date) {
        $empIds = \App\Models\ObRecord::whereIn('STATUS', [1, 2])
            ->where('FORM_TYPE', 'ob')
            ->whereDate('DATE_OB_FROM', '<=', $date)
            ->whereDate('DATE_OB_TO',   '>=', $date)
            ->pluck('EMPID')
            ->unique()
            ->values()
            ->toArray();

        if (empty($empIds)) continue;

        $obByEmp = \App\Models\ObRecord::whereIn('STATUS', [1, 2])
            ->where('FORM_TYPE', 'ob')
            ->whereDate('DATE_OB_FROM', '<=', $date)
            ->whereDate('DATE_OB_TO',   '>=', $date)
            ->get(['EMPID', 'TIME_FROM', 'TIME_TO'])
            ->keyBy('EMPID');

        $employees = \App\Models\EmployeeMasterlist::whereIn('EMPLOYID', $empIds)
            ->get(['EMPLOYID']);

        $hasAnyMissing = false;
        foreach ($employees as $emp) {
            $ob = $obByEmp[$emp->EMPLOYID] ?? null;
            if (!$ob) continue;

            $obFrom     = substr($ob->TIME_FROM, 0, 5);
            $obTo       = substr($ob->TIME_TO,   0, 5);
            $tw         = $this->resolveTimeWindowsForDate($emp->EMPLOYID, $date);
            $actualLogs = $this->resolveActualLogs($emp->EMPLOYID, $date, $tw);
            $slots      = $this->analyzeSlots($tw, $actualLogs, $obFrom, $obTo);

            $hasMissing = collect($slots)->contains(
                fn($s) => $s['in_ob_range'] && $s['status'] === 'missing'
            );

            if ($hasMissing) {
                $hasAnyMissing = true;
                break;
            }
        }

        if ($hasAnyMissing) {
            $validDates[] = $date;
        }
    }

    return response()->json(array_values($validDates));
}

public function getNewlyHiredEmployees(Request $request)
{
    $date = $request->get('date', '');
    if (!$date) return response()->json([]);

    // Find employees whose DATEHIRED matches the selected date
    $employees = \App\Models\EmployeeMasterlist::whereDate('DATEHIRED', $date)
        ->where('ACCSTATUS', 1)
        ->get(['EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'JOB_TITLE', 'DATEHIRED']);

    if ($employees->isEmpty()) return response()->json([]);

    $result = [];

    foreach ($employees as $emp) {
        $employId = $emp->EMPLOYID;

        // Fetch actual logs for this date (biometric + manual)
        $from = \Carbon\Carbon::parse($date)->subDay()->toDateString();
        $to   = \Carbon\Carbon::parse($date)->addDay()->toDateString();

        $actualTimes = collect();

        $bioLogs = \Illuminate\Support\Facades\DB::table('biometric_logs')
            ->where('employid', $employId)
            ->whereBetween('datetime', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('datetime')
            ->get(['datetime', 'punch_type']);

        $manualLogs = \Illuminate\Support\Facades\DB::table('biometric_logs_manual')
            ->where('employid', $employId)
            ->whereBetween('datetime', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('datetime')
            ->get(['datetime', 'punch_type']);

        $allLogs = $bioLogs->merge($manualLogs)->sortBy('datetime');

        // Build slot map
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

        $punchTypeMap = [
            '0' => 'check_in',  'check_in'  => 'check_in',
            '1' => 'check_out', 'check_out' => 'check_out',
            '2' => 'break_out', 'break_out' => 'break_out',
            '3' => 'break_in',  'break_in'  => 'break_in',
        ];

        $breakOutOrder = ['break_out_1', 'lunch_out', 'break_out_2'];
        $breakInOrder  = ['break_in_1',  'lunch_in',  'break_in_2'];
        $boIdx = $biIdx = 0;

        foreach ($allLogs as $log) {
            $time = \Carbon\Carbon::parse($log->datetime)->format('H:i');
            $type = $punchTypeMap[$log->punch_type] ?? null;

            match ($type) {
                'check_in'  => ($slots['time_in']  ??= $time),
                'check_out' => ($slots['time_out']   = $time),
                'break_out' => (function () use (&$slots, &$boIdx, $breakOutOrder, $time) {
                    while ($boIdx < count($breakOutOrder) && $slots[$breakOutOrder[$boIdx]] !== null) $boIdx++;
                    if ($boIdx < count($breakOutOrder)) $slots[$breakOutOrder[$boIdx++]] = $time;
                })(),
                'break_in'  => (function () use (&$slots, &$biIdx, $breakInOrder, $time) {
                    while ($biIdx < count($breakInOrder) && $slots[$breakInOrder[$biIdx]] !== null) $biIdx++;
                    if ($biIdx < count($breakInOrder)) $slots[$breakInOrder[$biIdx++]] = $time;
                })(),
                default => null,
            };
        }

        // Build slot analysis — all slots are "in range" since there's no schedule
        $slotDefs = [
            ['key' => 'time_in',     'label' => 'Time In'],
            ['key' => 'break_out_1', 'label' => 'Break Out 1'],
            ['key' => 'break_in_1',  'label' => 'Break In 1'],
            ['key' => 'lunch_out',   'label' => 'Lunch Out'],
            ['key' => 'lunch_in',    'label' => 'Lunch In'],
            ['key' => 'break_out_2', 'label' => 'Break Out 2'],
            ['key' => 'break_in_2',  'label' => 'Break In 2'],
            ['key' => 'time_out',    'label' => 'Time Out'],
        ];

        $slotAnalysis = [];
        $hasMissing   = false;

        foreach ($slotDefs as $def) {
            $actual  = $slots[$def['key']];
            $status  = $actual !== null ? 'has_log' : 'missing';
            if ($status === 'missing') $hasMissing = true;

            $slotAnalysis[] = [
                'key'         => $def['key'],
                'label'       => $def['label'],
                'actual'      => $actual,
                'status'      => $status,
                'in_ob_range' => true, // all slots are eligible for newly hired
            ];
        }

        if (!$hasMissing) continue; // skip if all slots already filled

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


}