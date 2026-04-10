<?php

namespace App\Http\Controllers;

use App\Services\DailyTimeRecordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DailyTimeRecordController extends Controller
{
    public function __construct(
        private readonly DailyTimeRecordService $dtrService
    ) {}

    public function index(Request $request)
    {
        $month    = $request->get('month', now()->format('Y-m'));
        $employId = session('emp_data.emp_id'); 

        $tableData = $this->dtrService->getTableData($employId, $month);

        return Inertia::render('DailyTimeRecord', [
            'tableData'    => $tableData,
            'tableFilters' => ['month' => $month],
        ]);
    }
}