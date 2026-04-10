<?php

namespace App\Http\Controllers;

use App\Models\EmployeeMasterlist;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BiometricStatusController extends Controller
{
    // ── INDEX ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $search     = trim($request->input('search', ''));
        $status     = $request->input('status', 'all');
        $department = $request->input('department', 'all');
        $prodline   = $request->input('prodline', 'all');

        // ── Employees query ───────────────────────────────────────────────────
        $employees = EmployeeMasterlist::where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->when($search !== '', fn($q) =>
                $q->where(fn($q2) =>
                    $q2->where('EMPNAME',   'like', "%{$search}%")
                       ->orWhere('EMPLOYID', 'like', "%{$search}%")
                )
            )
            ->when($status !== 'all',     fn($q) => $q->where('BIOMETRIC_STATUS', $status))
            ->when($department !== 'all', fn($q) => $q->where('DEPARTMENT', $department))
            ->when($prodline !== 'all',   fn($q) => $q->where('PRODLINE', $prodline))
            ->select('EMPLOYID', 'EMPNAME', 'DEPARTMENT', 'PRODLINE', 'STATION', 'BIOMETRIC_STATUS')
            ->orderBy('EMPNAME')
            ->get();

        // ── Filter dropdown options ───────────────────────────────────────────
        $departments = EmployeeMasterlist::where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->whereNotNull('DEPARTMENT')
            ->where('DEPARTMENT', '!=', '')
            ->distinct()
            ->orderBy('DEPARTMENT')
            ->pluck('DEPARTMENT');

        $prodlines = EmployeeMasterlist::where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->whereNotNull('PRODLINE')
            ->where('PRODLINE', '!=', '')
            ->distinct()
            ->orderBy('PRODLINE')
            ->pluck('PRODLINE');

        // ── Summary counts ────────────────────────────────────────────────────
        $countRows = EmployeeMasterlist::where('ACCSTATUS', 1)
            ->whereIn('EMPPOSITION', [1, 2])
            ->selectRaw('BIOMETRIC_STATUS, COUNT(*) as cnt')
            ->groupBy('BIOMETRIC_STATUS')
            ->pluck('cnt', 'BIOMETRIC_STATUS');

        $counts = [
            'enabled'  => (int) ($countRows['Enabled']  ?? 0),
            'disabled' => (int) ($countRows['Disabled'] ?? 0),
            'total'    => (int) $countRows->sum(),
        ];

        return Inertia::render('BiometricStatus', [
            'employees'   => $employees,
            'departments' => $departments,
            'prodlines'   => $prodlines,
            'filters'     => compact('search', 'status', 'department', 'prodline'),
            'counts'      => $counts,
            'flash'       => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
        ]);
    }

    // ── TOGGLE (single + bulk) ────────────────────────────────────────────────

    public function toggle(Request $request)
    {
        // ── Bulk toggle ───────────────────────────────────────────────────────
        if ($request->filled('bulk_ids')) {
            $ids    = json_decode($request->input('bulk_ids'), true);
            $action = $request->input('bulk_action');

            if (empty($ids) || ! in_array($action, ['enable', 'disable'])) {
                return back()->with('error', 'Invalid bulk request.');
            }

            $newStatus = $action === 'enable' ? 'Enabled' : 'Disabled';

            $count = EmployeeMasterlist::whereIn('EMPLOYID', $ids)
                ->update(['BIOMETRIC_STATUS' => $newStatus]);

            return back()->with(
                'success',
                "Bulk update successful: <strong>{$count}</strong> employee(s) set to <strong>{$newStatus}</strong>."
            );
        }

        // ── Single toggle ─────────────────────────────────────────────────────
        $empId  = trim($request->input('emp_id', ''));
        $action = $request->input('action');

        if (empty($empId) || ! in_array($action, ['enable', 'disable'])) {
            return back()->with('error', 'Invalid request.');
        }

        $newStatus = $action === 'enable' ? 'Enabled' : 'Disabled';

        $updated = EmployeeMasterlist::where('EMPLOYID', $empId)
            ->update(['BIOMETRIC_STATUS' => $newStatus]);

        if ($updated) {
            return back()->with(
                'success',
                "Employee <strong>{$empId}</strong> biometric status updated to <strong>{$newStatus}</strong>."
            );
        }

        return back()->with('error', "Failed to update employee {$empId}.");
    }
}