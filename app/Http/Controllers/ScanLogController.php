<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\FingerprintTemplate;
use App\Models\AttendanceLog;
use App\Models\EmployeeMasterlist;
use Carbon\Carbon;

class ScanLogController extends Controller
{
    // ── Tune these after checking laravel.log ─────────────────────────────────
    private const MATCH_THRESHOLD = 100;  // SourceAFIS scores are typically 100-500+ for real matches
    private const REJECTION_GAP   = 50;   // best must clearly beat 2nd place from a different employee

    private const FINGER_LABELS = [
        'Right Thumb',  'Right Index',  'Right Middle', 'Right Ring',  'Right Little',
        'Left Thumb',   'Left Index',   'Left Middle',  'Left Ring',   'Left Little',
    ];

    public function index()
    {
        return Inertia::render('ScanLogs', [
            'app_name' => env('APP_NAME', ''),
        ]);
    }

public function verifyAndLog(Request $request)
{
    $validated = $request->validate([
        'template_data' => 'required|string',
        'fmd_data'      => 'nullable|string',
        'log_type'      => 'required|string|in:check_in,check_out,break_out1,break_in1,lunch_out,lunch_in,break_out2,break_in2',
        'quality'       => 'required|integer|min:0|max:100',
        'device_type'   => 'required|string|max:100',
    ]);

    // ── Load all registered FMDs ──────────────────────────────────────────────
    $templates = FingerprintTemplate::where('is_active', 1)
        ->whereNotNull('fmd_data')
        ->get(['id', 'employid', 'fmd_data', 'finger_index']);

    if ($templates->isEmpty()) {
        return response()->json([
            'matched' => false,
            'message' => 'No fingerprints registered in the system.',
        ], 404);
    }

    $candidates = $templates->map(fn($t) => [
        'id'           => $t->id,
        'employid'     => $t->employid,
        'finger_index' => $t->finger_index,
        'fmd'          => $t->fmd_data,
    ])->values()->toArray();

    // ── Extract FMD from probe image first, then match ────────────────────────
    $fingerprintService = app(\App\Services\FingerprintService::class);
    try {
        $cleanImage = strtr(trim($validated['template_data']), '-_', '+/');
        $cleanImage = str_pad($cleanImage, strlen($cleanImage) + (4 - strlen($cleanImage) % 4) % 4, '=');
        $probeFmd   = $fingerprintService->extractFmd($cleanImage);
        $scores     = $fingerprintService->matchWithFmd($probeFmd, $candidates);
    } catch (\Throwable $e) {
        \Log::error('[ScanLog] SourceAFIS match error: ' . $e->getMessage());
        return response()->json([
            'matched' => false,
            'message' => 'Matching engine error: ' . $e->getMessage(),
        ], 500);
    }

    $top10 = array_slice($scores, 0, 10);
    \Log::info('[ScanLog] Top 10 scores:', array_map(fn($s) => [
        'employid'     => $s['employid'],
        'finger_index' => $s['finger_index'],
        'score'        => $s['score'],
    ], $top10));

    if (empty($scores)) {
        return response()->json(['matched' => false, 'message' => 'Comparison failed.']);
    }

    $best       = $scores[0];
    $secondBest = $scores[1] ?? null;

    // ── Reject if best score is too low ──────────────────────────────────────
    if ($best['score'] < self::MATCH_THRESHOLD) {
        \Log::info('[ScanLog] Rejected — best score too low: ' . $best['score']);
        return response()->json([
            'matched' => false,
            'message' => 'Fingerprint not recognised. Please try again.',
            'score'   => round($best['score'], 4),
        ]);
    }

    // ── Reject if another employee's score is too close to the best ───────────
    $bestEmpId = $best['employid'];
    foreach (array_slice($scores, 1) as $other) {
        if ($other['employid'] !== $bestEmpId) {
            $gap = $best['score'] - $other['score'];
            \Log::info("[ScanLog] Gap check: best={$best['score']} other={$other['score']} gap={$gap} other_emp={$other['employid']}");
            if ($gap < self::REJECTION_GAP) {
                return response()->json([
                    'matched' => false,
                    'message' => 'Ambiguous scan — please try again.',
                    'score'   => round($best['score'], 4),
                ]);
            }
            break; // only check the closest competing employee
        }
    }

    // ── Look up employee ──────────────────────────────────────────────────────
    $employee = EmployeeMasterlist::where('EMPLOYID', $best['employid'])->first();

    if (!$employee) {
        return response()->json(['matched' => false, 'message' => 'Employee record not found.'], 404);
    }

    // ── Prevent duplicate logs within 2 minutes ───────────────────────────────
    $recent = AttendanceLog::where('employid', $best['employid'])
        ->where('log_type',  $validated['log_type'])
        ->where('logged_at', '>=', Carbon::now()->subMinutes(2))
        ->exists();

    if ($recent) {
        return response()->json([
            'matched'   => true,
            'duplicate' => true,
            'message'   => 'Duplicate scan — already logged within 2 minutes.',
            'employee'  => [
                'employid'   => $employee->EMPLOYID,
                'name'       => $employee->EMPNAME,
                'department' => $employee->DEPARTMENT,
            ],
        ]);
    }

    // ── Save attendance log ───────────────────────────────────────────────────
    $log = AttendanceLog::create([
        'employid'      => $best['employid'],
        'employee_name' => $employee->EMPNAME,
        'department'    => $employee->DEPARTMENT,
        'log_type'      => $validated['log_type'],
        'finger_label'  => self::FINGER_LABELS[$best['finger_index']] ?? null,
        'finger_index'  => $best['finger_index'],
        'match_score'   => round($best['score'], 4),
        'quality'       => $validated['quality'],
        'matched'       => true,
        'device_type'   => $validated['device_type'],
        'recorded_by'   => 'fingerprint_scanner',
        'logged_at'     => Carbon::now(),
    ]);

    return response()->json([
        'matched'   => true,
        'duplicate' => false,
        'score'     => round($best['score'], 4),
        'log'       => [
            'id'        => $log->id,
            'log_type'  => $log->log_type,
            'logged_at' => $log->logged_at->format('H:i:s'),
        ],
        'employee'  => [
            'employid'   => $employee->EMPLOYID,
            'name'       => $employee->EMPNAME,
            'department' => $employee->DEPARTMENT,
            'finger'     => self::FINGER_LABELS[$best['finger_index']] ?? null,
        ],
    ]);
}
}