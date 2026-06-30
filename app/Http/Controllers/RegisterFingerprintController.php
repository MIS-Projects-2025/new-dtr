<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Models\EmployeeMasterlist;
use App\Models\FingerprintTemplate;

class RegisterFingerprintController extends Controller
{
    /**
     * Display the fingerprint registration page with all active employees
     * and their existing fingerprint registrations.
     */
    public function index(Request $request)
    {
        $employees = EmployeeMasterlist::where('ACCSTATUS', 1)
            ->orderBy('EMPNAME')
            ->get([
                'EMPLOYID',
                'EMPNAME',
                'DEPARTMENT',
                'JOB_TITLE',
                'STATION',
                'PRODLINE',
            ]);

        // finger_index → which fingers are already registered per employee
        $registrations = FingerprintTemplate::where('is_active', 1)
            ->get(['employid', 'finger_index', 'quality', 'device_type'])
            ->groupBy('employid')
            ->map(fn ($group) => $group->map(fn ($r) => [
                'finger_index' => $r->finger_index,
                'quality'      => $r->quality,
                'device_type'  => $r->device_type,
            ])->values()->toArray());

        return Inertia::render('RegisterFingerprint', [
            'employees'     => $employees,
            'registrations' => $registrations,
            'app_name'      => env('APP_NAME', ''),
        ]);
    }

    /**
     * Store a new fingerprint template.
     * template_data arrives as a base64-encoded string and is stored as binary.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employid'      => 'required|string|max:50',
            'template_data' => 'required|string',   // base64 PNG
            'device_type'   => 'required|string|max:100',
            'finger_index'  => 'required|integer|min:0|max:9',
            'quality'       => 'required|integer|min:0|max:100',
        ]);

        $fingerprintService = app(\App\Services\FingerprintService::class);

        try {
            // Both HID and SecuGen now send image data (PNG/BMP) — same extraction path
            $cleanImage = strtr($validated['template_data'], '-_', '+/');
            $fmd = $fingerprintService->extractFmd($cleanImage);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'FMD extraction failed: ' . $e->getMessage(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            FingerprintTemplate::where('employid', $validated['employid'])
                ->where('finger_index', $validated['finger_index'])
                ->update(['is_active' => 0]);

            $template = FingerprintTemplate::create([
                'employid'      => $validated['employid'],
                'template_data' => $validated['template_data'], // keep PNG for display
                'fmd_data'      => $fmd,                        // SourceAFIS template
                'device_type'   => $validated['device_type'],
                'finger_index'  => $validated['finger_index'],
                'quality'       => $validated['quality'],
                'registered_by' => Auth::user()?->name ?? 'system',
                'is_active'     => 1,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'id' => $template->id]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Return all active fingerprint registrations for one employee.
     */
    public function getRegistrations(string $employId)
    {
        $registrations = FingerprintTemplate::where('employid', $employId)
            ->where('is_active', 1)
            ->get(['id', 'finger_index', 'quality', 'device_type', 'created_at'])
            ->map(fn ($r) => [
                'id'           => $r->id,
                'finger_index' => $r->finger_index,
                'quality'      => $r->quality,
                'device_type'  => $r->device_type,
                'created_at'   => $r->created_at?->format('M d, Y h:i A'),
            ])
            ->keyBy('finger_index');

        return response()->json($registrations);
    }

    /**
     * Soft-delete (deactivate) a specific fingerprint registration.
     */
    public function destroy(string $employId, int $fingerIndex)
    {
        FingerprintTemplate::where('employid', $employId)
            ->where('finger_index', $fingerIndex)
            ->update(['is_active' => 0]);

        return response()->json(['success' => true, 'message' => 'Fingerprint removed.']);
    }

    public function getTemplatesForVerification(string $employId): \Illuminate\Http\JsonResponse
        {
            $templates = FingerprintTemplate::where('employid', $employId)
                ->where('is_active', 1)
                ->get(['id', 'finger_index', 'quality', 'device_type', 'template_data'])
                ->map(fn ($t) => [
                    'id'           => $t->id,
                    'finger_index' => $t->finger_index,
                    'quality'      => $t->quality,
                    'device_type'  => $t->device_type,
                    'template'     => $t->getTemplateBase64(), // base64 PNG
                ]);

            return response()->json($templates);
        }

    public function deviceStatus(): \Illuminate\Http\JsonResponse
    {
        $status = app(\App\Services\FingerprintService::class)->checkSecuGenStatus();
        return response()->json($status);
    }
}