<?php
// database/migrations/2024_01_01_000000_add_optimization_indexes.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connections map — mirrors what each Model defines.
     *
     * 'dtr'      => attendance_logs, biometric_logs, biometric_logs_manual, vp_logs
     * 'calendar' => work_scheduler, holidays
     * 'leave'    => employee_leaves
     */

    public function up(): void
    {
        // ── DTR connection ────────────────────────────────────────────────

        // attendance_logs  (connection: dtr)
        Schema::connection('dtr')->table('attendance_logs', function (Blueprint $table) {
            $this->dropIndexIfExists('dtr', 'attendance_logs', 'idx_attendance_optimized');
            $table->index(['employid', 'logged_at', 'log_type'], 'idx_attendance_optimized');
        });

        // biometric_logs  (connection: dtr)
        Schema::connection('dtr')->table('biometric_logs', function (Blueprint $table) {
            $this->dropIndexIfExists('dtr', 'biometric_logs', 'idx_biometric_optimized');
            $table->index(['employid', 'datetime', 'punch_type'], 'idx_biometric_optimized');
        });

        // biometric_logs_manual  (connection: dtr)
        Schema::connection('dtr')->table('biometric_logs_manual', function (Blueprint $table) {
            $this->dropIndexIfExists('dtr', 'biometric_logs_manual', 'idx_biometric_manual_optimized');
            $table->index(['employid', 'datetime', 'punch_type'], 'idx_biometric_manual_optimized');
        });

        // vp_logs  (connection: dtr)
        Schema::connection('dtr')->table('vp_logs', function (Blueprint $table) {
            $this->dropIndexIfExists('dtr', 'vp_logs', 'idx_vp_optimized');
            $table->index(['employee_id', 'log_date', 'log_type'], 'idx_vp_optimized');
        });

        // ── Calendar connection ───────────────────────────────────────────

        // work_scheduler  (connection: calendar)
        Schema::connection('calendar')->table('work_scheduler', function (Blueprint $table) {
            $this->dropIndexIfExists('calendar', 'work_scheduler', 'idx_work_scheduler_optimized');
            $table->index(['EMPID', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END'], 'idx_work_scheduler_optimized');
        });

        // holidays  (connection: calendar)
        Schema::connection('calendar')->table('holidays', function (Blueprint $table) {
            $this->dropIndexIfExists('calendar', 'holidays', 'idx_holiday_date');
            $table->index(['HOLIDAY_DATE'], 'idx_holiday_date');
        });

        // ── Leave connection ──────────────────────────────────────────────

        // employee_leaves  (connection: leave)
        Schema::connection('leave')->table('employee_leaves', function (Blueprint $table) {
            $this->dropIndexIfExists('leave', 'employee_leaves', 'idx_leaves_optimized');
            $table->index(['EMPLOYID', 'DATESTART', 'DATEEND', 'LEAVESTATUS'], 'idx_leaves_optimized');
        });
    }

    private function dropIndexIfExists(string $connection, string $table, string $indexName): void
    {
        $exists = DB::connection($connection)
            ->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        if (!empty($exists)) {
            DB::connection($connection)->statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }

    public function down(): void
    {
        Schema::connection('dtr')->table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_optimized');
        });

        Schema::connection('dtr')->table('biometric_logs', function (Blueprint $table) {
            $table->dropIndex('idx_biometric_optimized');
        });

        Schema::connection('dtr')->table('biometric_logs_manual', function (Blueprint $table) {
            $table->dropIndex('idx_biometric_manual_optimized');
        });

        Schema::connection('dtr')->table('vp_logs', function (Blueprint $table) {
            $table->dropIndex('idx_vp_optimized');
        });

        Schema::connection('calendar')->table('work_scheduler', function (Blueprint $table) {
            $table->dropIndex('idx_work_scheduler_optimized');
        });

        Schema::connection('calendar')->table('holidays', function (Blueprint $table) {
            $table->dropIndex('idx_holiday_date');
        });

        Schema::connection('leave')->table('employee_leaves', function (Blueprint $table) {
            $table->dropIndex('idx_leaves_optimized');
        });
    }
};