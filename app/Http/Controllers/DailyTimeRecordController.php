<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class DailyTimeRecordController extends Controller
{

    public function index(Request $request)
    {
        return Inertia::render('DailyTimeRecord');
    }
}
