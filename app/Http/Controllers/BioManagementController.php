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



}