<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\EmployeeService;

class DailyTimeRecordController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService,
    ) {}

    public function index(Request $request)
    {
        $emp_data = session('emp_data');

        return Inertia::render('DailyTimeRecord', [
            'emp_data' => $emp_data,
            'app_name' => env('APP_NAME', ''),
        ]);
    }

    public function getDtrRows(Request $request)
{
    try {
        $emp_data = session('emp_data');
        $employId = $emp_data['emp_id'] ?? null;

        if (!$employId) {
            return response()->json(['error' => 'No employee session found.'], 401);
        }

        $month        = $request->get('month', now()->format('Y-m'));
        $page         = (int) $request->get('page', 1);
        $perPage      = 25;
        $shiftFilter  = (string) $request->get('shift_filter', '');
        $statusFilter = (string) $request->get('status_filter', '');

        [$year, $mon] = explode('-', $month);
        $startDate    = \Carbon\Carbon::create((int)$year, (int)$mon, 1)->toDateString();
        $endDate      = \Carbon\Carbon::create((int)$year, (int)$mon, 1)->endOfMonth()->toDateString();

        // Keep the cap — we don't want future dates
        $today = now()->toDateString();
        if ($endDate > $today) $endDate = $today;

        $result = $this->employeeService->getDtrRowsForEmployee(
            $employId, $startDate, $endDate, $page, $perPage, $shiftFilter, $statusFilter
        );

        return response()->json($result);
    } catch (\Exception $e) {
        \Log::error('Personal DTR error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}
}