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
    private const MATCH_THRESHOLD = 62; // minimum similarity % to accept as a match
    private const HASH_SIZE       = 16; // pixel grid for comparison

    private const FINGER_LABELS = [
        'Right Thumb',  'Right Index',  'Right Middle', 'Right Ring',  'Right Little',
        'Left Thumb',   'Left Index',   'Left Middle',  'Left Ring',   'Left Little',
    ];

    // ── Pages ─────────────────────────────────────────────────────────────────


    public function index()
    {
        return Inertia::render('ScanLogs', [
            'app_name' => env('APP_NAME', ''),
        ]);
    }

    // ── API: verify fingerprint and save log ──────────────────────────────────

    public function verifyAndLog(Request $request)
    {
        $validated = $request->validate([
        'template_data' => 'required|string',
        'fmd_data'      => 'nullable|string',   // raw SDK samples for better matching
        'log_type'      => 'required|string|in:check_in,check_out,break_out1,break_in1,lunch_out,lunch_in,break_out2,break_in2',
        'quality'       => 'required|integer|min:0|max:100',
        'device_type'   => 'required|string|max:100',
    ]);

        // ── Load all active templates ─────────────────────────────────────────
        $templates = FingerprintTemplate::where('is_active', 1)
            ->get(['id', 'employid', 'template_data', 'finger_index', 'quality']);

        if ($templates->isEmpty()) {
            return response()->json([
                'matched' => false,
                'message' => 'No fingerprints registered in the system.',
            ], 404);
        }

        // ── Compare against every stored template ─────────────────────────────
        $bestScore    = 0;
        $bestTemplate = null;

        foreach ($templates as $template) {
            try {
                $score = $this->compareImages($validated['template_data'], $template->template_data);
                if ($score > $bestScore) {
                    $bestScore    = $score;
                    $bestTemplate = $template;
                }
            } catch (\Throwable) {
                continue; // skip corrupted template
            }
        }

        // ── No match ──────────────────────────────────────────────────────────
        // Log top 3 scores to see what it's actually matching against
        $allScores = $templates->map(fn($t) => [
            'employid'     => $t->employid,
            'finger_index' => $t->finger_index,
            'score'        => round($this->compareImages($validated['template_data'], $t->template_data), 2),
        ])->sortByDesc('score')->take(3)->values();

        \Log::info('[ScanLog] Top matches:', $allScores->toArray());

        if (!$bestTemplate || $bestScore < self::MATCH_THRESHOLD) {
            return response()->json([
                'matched' => false,
                'message' => 'Fingerprint not recognised. Please try again.',
                'score'   => $bestScore,
                'debug'   => $allScores,  // remove this in production
            ]);
        }

        // ── Match found — get employee ────────────────────────────────────────
        $employee = EmployeeMasterlist::where('EMPLOYID', $bestTemplate->employid)->first();

        if (!$employee) {
            return response()->json([
                'matched' => false,
                'message' => 'Employee record not found.',
            ], 404);
        }

        // ── Prevent duplicate logs within 2 minutes ───────────────────────────
        $recent = AttendanceLog::where('employid', $bestTemplate->employid)
            ->where('log_type',  $validated['log_type'])
            ->where('logged_at', '>=', Carbon::now()->subMinutes(2))
            ->exists();

        if ($recent) {
            return response()->json([
                'matched'  => true,
                'duplicate'=> true,
                'message'  => 'Duplicate scan — already logged within 2 minutes.',
                'employee' => [
                    'employid'   => $employee->EMPLOYID,
                    'name'       => $employee->EMPNAME,
                    'department' => $employee->DEPARTMENT,
                ],
            ]);
        }

        // ── Save attendance log ───────────────────────────────────────────────
        $log = AttendanceLog::create([
            'employid'      => $bestTemplate->employid,
            'employee_name' => $employee->EMPNAME,
            'department'    => $employee->DEPARTMENT,
            'log_type'      => $validated['log_type'],
            'finger_label'  => self::FINGER_LABELS[$bestTemplate->finger_index] ?? null,
            'finger_index'  => $bestTemplate->finger_index,
            'match_score'   => (int) $bestScore,
            'quality'       => $validated['quality'],
            'matched'       => true,
            'device_type'   => $validated['device_type'],
            'recorded_by'   => 'fingerprint_scanner',
            'logged_at'     => Carbon::now(),
        ]);

        return response()->json([
            'matched'  => true,
            'duplicate'=> false,
            'score'    => (int) $bestScore,
            'log'      => [
                'id'         => $log->id,
                'log_type'   => $log->log_type,
                'logged_at'  => $log->logged_at->format('H:i:s'),
            ],
            'employee' => [
                'employid'    => $employee->EMPLOYID,
                'name'        => $employee->EMPNAME,
                'department'  => $employee->DEPARTMENT,
                'finger'      => self::FINGER_LABELS[$bestTemplate->finger_index] ?? null,
            ],
        ]);
    }

    // ── API: recent logs (last 20) ────────────────────────────────────────────

    public function getRecentLogs()
    {
        $logs = AttendanceLog::whereDate('logged_at', today())
            ->orderByDesc('logged_at')
            ->limit(20)
            ->get(['id', 'employid', 'employee_name', 'department', 'log_type', 'match_score', 'logged_at']);

        return response()->json($logs);
    }

    // ── Fingerprint comparison ────────────────────────────────────────────────

    /**
     * Compare two standard-base64 PNG fingerprint images.
     * Returns a similarity score 0–100.
     *
     * Method: resize both images to a small grid, convert to grayscale,
     * and count pixels within an acceptable brightness tolerance.
     * Not a cryptographic match — suitable for single-reader environments.
     */
    private function compareImages(string $b64A, string $b64B): float
    {
        $imgA = imagecreatefromstring(base64_decode($b64A));
        $imgB = imagecreatefromstring(base64_decode($b64B));

        if (!$imgA || !$imgB) return 0.0;

        $s = self::HASH_SIZE;

        // Resize to uniform grid
        $sA = imagecreatetruecolor($s, $s);
        $sB = imagecreatetruecolor($s, $s);
        imagecopyresampled($sA, $imgA, 0, 0, 0, 0, $s, $s, imagesx($imgA), imagesy($imgA));
        imagecopyresampled($sB, $imgB, 0, 0, 0, 0, $s, $s, imagesx($imgB), imagesy($imgB));

        $total   = $s * $s;
        $matched = 0;

        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                $cA  = imagecolorat($sA, $x, $y);
                $cB  = imagecolorat($sB, $x, $y);
                $gA  = (int)(0.299 * (($cA >> 16) & 0xFF) + 0.587 * (($cA >> 8) & 0xFF) + 0.114 * ($cA & 0xFF));
                $gB  = (int)(0.299 * (($cB >> 16) & 0xFF) + 0.587 * (($cB >> 8) & 0xFF) + 0.114 * ($cB & 0xFF));

                if (abs($gA - $gB) <= 28) $matched++;
            }
        }

        imagedestroy($imgA); imagedestroy($imgB);
        imagedestroy($sA);   imagedestroy($sB);

        return round(($matched / $total) * 100, 2);
    }
}