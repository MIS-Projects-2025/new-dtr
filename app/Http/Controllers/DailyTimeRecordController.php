<?php

namespace App\Http\Controllers;

use App\Http\Requests\DtrRequest;
use App\Services\DailyTimeRecordService;
use Inertia\Inertia;
use Inertia\Response;

class DailyTimeRecordController extends Controller
{
    public function __construct(
        private readonly DailyTimeRecordService $dtrService
    ) {}

    public function index(DtrRequest $request): Response
    {
        $month    = $request->month();
        $employId = session('emp_data.emp_id');

        return Inertia::render('DailyTimeRecord', [
            'tableData'    => $this->dtrService->getTableData($employId, $month),
            'tableFilters' => ['month' => $month],
        ]);
    }
}