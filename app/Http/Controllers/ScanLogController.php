<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use App\Services\EmployeeService;
use App\Models\FingerprintTemplate;
use App\Models\EmployeeMasterlist;
use App\Models\AttendanceLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ScanLogController extends Controller
{
    private const MATCH_THRESHOLD = 50;
    private const QUALITY_GATE    = 40;
    private const HIGH_CONFIDENCE = 150;
    private const CHUNK_SIZE      = 20;

    private const FINGER_LABELS = [
        0 => 'Right Thumb',  1 => 'Right Index',  2 => 'Right Middle',
        3 => 'Right Ring',   4 => 'Right Pinky',
        5 => 'Left Thumb',   6 => 'Left Index',   7 => 'Left Middle',
        8 => 'Left Ring',    9 => 'Left Pinky',
    ];

    protected $employeeService;

    public function __construct(EmployeeService $employeeService)
    {
        $this->employeeService = $employeeService;
    }

    public function index(Request $request)
    {
        $employees     = $this->employeeService->getEmployeesWithScheduleData($request->search, 50);
        $shiftCodesMap = $this->employeeService->getShiftCodesMap();
        $todayHoliday  = $this->employeeService->getTodayHoliday();

        return Inertia::render('ScanLogs', [
            'initialEmployees' => $employees,
            'shiftCodesMap'    => $shiftCodesMap,
            'todayHoliday'     => $todayHoliday,
        ]);
    }

    // ── match() — receives captured template, runs 1:N server-side ───────────

    public function match(Request $request): JsonResponse
    {
        $request->validate([
            'template' => 'required|string',
            'quality'  => 'nullable|integer|min:0|max:100',
        ]);

        $quality     = (int) ($request->quality ?? 0);
        $templateB64 = preg_replace('/\s+/', '', $request->template);
        $probeBytes  = base64_decode($templateB64, true);

        if ($probeBytes === false || strlen($probeBytes) < 32) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid fingerprint template.',
            ], 422);
        }

        if ($quality < self::QUALITY_GATE) {
            return response()->json([
                'success' => false,
                'message' => "Fingerprint quality too low ({$quality}/100). Please re-scan.",
            ], 422);
        }

        // Load all active templates
        $templates = FingerprintTemplate::where('is_active', 1)
            ->where('device_type', 'secugen')
            ->where(fn($q) => $q->whereNull('quality')->orWhere('quality', '>=', 20))
            ->get();

        if ($templates->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No registered fingerprints found in the database.',
            ]);
        }

        // Run 1:N match
        $probeMinutiae = $this->parseISO19794($probeBytes);
        $bestScore     = 0;
        $bestTemplate  = null;

        foreach ($templates->sortByDesc(fn($t) => $t->quality ?? 0)->values()->chunk(self::CHUNK_SIZE) as $chunk) {
            foreach ($this->matchTemplatesBatch($probeMinutiae, $chunk) as $id => $score) {
                if ($score > $bestScore) {
                    $bestScore    = $score;
                    $bestTemplate = $chunk->firstWhere('id', $id);
                }
            }
            if ($bestScore >= self::HIGH_CONFIDENCE) break;
        }

        if ($bestScore < self::MATCH_THRESHOLD || !$bestTemplate) {
            Log::channel('daily')->warning('[SCAN_LOG] ❌ No match', [
                'best_score' => $bestScore,
                'quality'    => $quality,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No matching fingerprint found.',
            ]);
        }

        $emp = EmployeeMasterlist::where('EMPLOYID', $bestTemplate->employid)
                   ->select('EMPID', 'EMPNAME', 'EMPLOYID', 'DEPARTMENT', 'JOB_TITLE')
                   ->first();

        Log::channel('daily')->info('[SCAN_LOG] ✅ Match found', [
            'employee' => $emp?->EMPNAME,
            'employid' => $bestTemplate->employid,
            'score'    => $bestScore,
            'quality'  => $quality,
            'finger'   => self::FINGER_LABELS[$bestTemplate->finger_index] ?? null,
        ]);

        return response()->json([
            'success'      => true,
            'employid'     => $bestTemplate->employid,
            'score'        => $bestScore,
            'finger_label' => self::FINGER_LABELS[$bestTemplate->finger_index] ?? null,
            'employee'     => $emp ? [
                'EMPID'      => $emp->EMPID,
                'EMPNAME'    => $emp->EMPNAME,
                'EMPLOYID'   => $emp->EMPLOYID,
                'DEPARTMENT' => $emp->DEPARTMENT,
                'JOB_TITLE'  => $emp->JOB_TITLE,
            ] : null,
        ]);
    }

    // ── Matching helpers (same as VerifyFingerprintController) ────────────────

    private function matchTemplatesBatch(array $probeMinutiae, \Illuminate\Support\Collection $templates): array
    {
        $scores = [];
        foreach ($templates as $tpl) {
            $raw = is_resource($tpl->template_data)
                ? stream_get_contents($tpl->template_data)
                : (string) $tpl->template_data;
            $scores[$tpl->id] = $this->matchMinutiae($probeMinutiae, $this->parseISO19794($raw));
        }
        return $scores;
    }

    private function parseISO19794(string $bytes): array
    {
        if (strlen($bytes) < 32) return [];
        $count    = ord($bytes[27]);
        $minutiae = [];
        $offset   = 28;
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 6 > strlen($bytes)) break;
            $typeX      = (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
            $typeY      = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
            $minutiae[] = [
                'x'     => $typeX & 0x3FFF,
                'y'     => $typeY & 0x3FFF,
                'angle' => ord($bytes[$offset + 4]),
                'type'  => ($typeX >> 14) & 0x03,
            ];
            $offset += 6;
        }
        return $minutiae;
    }

    private function matchMinutiae(array $probe, array $stored): int
    {
        if (empty($probe) || empty($stored)) return 0;

        $spatialTol     = 10;
        $angularTol     = 12;
        $bestMatchCount = 0;
        $probeAnchors   = array_slice($probe,  0, 10);
        $storedAnchors  = array_slice($stored, 0, 10);

        foreach ($probeAnchors as $p) {
            foreach ($storedAnchors as $s) {
                $rad = ($p['angle'] - $s['angle']) * (2 * M_PI / 255);
                $cos = cos($rad); $sin = sin($rad);
                $tx  = $p['x'] - ($s['x'] * $cos - $s['y'] * $sin);
                $ty  = $p['y'] - ($s['x'] * $sin + $s['y'] * $cos);

                $matchCount = 0;
                $usedStored = [];
                foreach ($probe as $pp) {
                    foreach ($stored as $si => $ss) {
                        if (isset($usedStored[$si])) continue;
                        $dx = abs($pp['x'] - ($ss['x'] * $cos - $ss['y'] * $sin + $tx));
                        $dy = abs($pp['y'] - ($ss['x'] * $sin + $ss['y'] * $cos + $ty));
                        $da = abs($pp['angle'] - $ss['angle']);
                        if ($da > 127) $da = 255 - $da;
                        if ($dx <= $spatialTol && $dy <= $spatialTol && $da <= $angularTol) {
                            $matchCount++;
                            $usedStored[$si] = true;
                            break;
                        }
                    }
                }
                if ($matchCount > $bestMatchCount) $bestMatchCount = $matchCount;
            }
        }

        $denom = min(count($probe), count($stored));
        return $denom === 0 ? 0 : min(200, (int) round(($bestMatchCount / $denom) * 200));
    }

    public function store(Request $request): JsonResponse
{
    $request->validate([
        'employid' => 'required|string',
        'log_type' => 'required|string|in:time_in,break_out_1,break_in_1,lunch_out,lunch_in,break_out_2,break_in_2,time_out',
    ]);

    $employid  = $request->employid;
    $logType   = $request->log_type;
    $loggedAt  = now();

    $emp = EmployeeMasterlist::where('EMPLOYID', $employid)
               ->select('EMPID', 'EMPNAME', 'EMPLOYID', 'DEPARTMENT')
               ->first();

    if (!$emp) {
        return response()->json(['success' => false, 'message' => 'Employee not found.'], 404);
    }

$dbLogType = $logType; // DB ENUM already matches frontend values exactly

    try {
        $saved = AttendanceLog::create([
            'employid'      => $employid,
            'employee_name' => $emp->EMPNAME,
            'department'    => $emp->DEPARTMENT,
            'log_type'      => $dbLogType,
            'matched'       => true,
            'device_type'   => 'secugen',
            'recorded_by'   => Auth::user()?->name ?? 'system',
            'logged_at'     => $loggedAt,
        ]);

    } catch (\Illuminate\Database\QueryException $e) {
        // Duplicate — find the existing one and return it
        if ($e->errorInfo[1] === 1062) {
            $existing = AttendanceLog::where('employid', $employid)
                ->where('log_type', $dbLogType)
                ->whereDate('logged_at', $loggedAt->toDateString())
                ->first();

            // time_out always overwrites
            if ($dbLogType === 'time_out' && $existing) {
                $existing->update(['logged_at' => $loggedAt]);
                return response()->json([
                    'success'   => true,
                    'updated'   => true,
                    'log_type'  => $dbLogType,
                    'logged_at' => $loggedAt->format('Y-m-d H:i:s'),
                    'employee'  => ['EMPNAME' => $emp->EMPNAME, 'EMPLOYID' => $emp->EMPLOYID],
                    'message'   => "Time Out updated to " . $loggedAt->format('h:i A') . " for {$emp->EMPNAME}.",
                ]);
            }

            return response()->json([
                'success'   => false,
                'duplicate' => true,
                'log_type'  => $dbLogType,
                'logged_at' => $existing?->logged_at?->format('Y-m-d H:i:s'),
                'message'   => "{$emp->EMPNAME} already logged {$logType}" .
                    ($existing?->logged_at ? " at " . $existing->logged_at->format('h:i A') : "") . ".",
            ], 409);
        }

        throw $e;
    }

    Log::channel('daily')->info('[SCAN_LOG] ✅ Log saved', [
        'employee' => $emp->EMPNAME,
        'employid' => $emp->EMPLOYID,
        'log_type' => $dbLogType,
    ]);

    return response()->json([
        'success'   => true,
        'log_type'  => $dbLogType,
        'logged_at' => $loggedAt->format('Y-m-d H:i:s'),
        'employee'  => ['EMPNAME' => $emp->EMPNAME, 'EMPLOYID' => $emp->EMPLOYID],
    ]);
}
}