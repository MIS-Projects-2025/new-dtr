<?php
// database/migrations/2024_01_01_000000_add_optimization_indexes.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddOptimizationIndexes extends Migration
{
    public function up()
    {
        // Attendance logs indexes
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index(['employid', 'logged_at', 'log_type'], 'idx_attendance_optimized');
        });
        
        // Biometric logs indexes
        Schema::table('biometric_logs', function (Blueprint $table) {
            $table->index(['employid', 'datetime', 'punch_type'], 'idx_biometric_optimized');
        });
        
        // Biometric logs manual indexes
        Schema::table('biometric_logs_manual', function (Blueprint $table) {
            $table->index(['employid', 'datetime', 'punch_type'], 'idx_biometric_manual_optimized');
        });
        
        // VP logs indexes
        Schema::table('vp_logs', function (Blueprint $table) {
            $table->index(['employee_id', 'log_date', 'log_type'], 'idx_vp_optimized');
        });
        
        // Work scheduler indexes
        Schema::table('work_schedulers', function (Blueprint $table) {
            $table->index(['EMPID', 'PAYROLL_DATE_START', 'PAYROLL_DATE_END'], 'idx_work_scheduler_optimized');
        });
        
        // Employee leaves indexes
        Schema::table('employee_leaves', function (Blueprint $table) {
            $table->index(['EMPLOYID', 'DATESTART', 'DATEEND', 'LEAVESTATUS'], 'idx_leaves_optimized');
        });
        
        // Holidays index
        Schema::table('holidays', function (Blueprint $table) {
            $table->index('HOLIDAY_DATE', 'idx_holiday_date');
        });
    }

    public function down()
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_optimized');
        });
        
        Schema::table('biometric_logs', function (Blueprint $table) {
            $table->dropIndex('idx_biometric_optimized');
        });
        
        Schema::table('biometric_logs_manual', function (Blueprint $table) {
            $table->dropIndex('idx_biometric_manual_optimized');
        });
        
        Schema::table('vp_logs', function (Blueprint $table) {
            $table->dropIndex('idx_vp_optimized');
        });
        
        Schema::table('work_schedulers', function (Blueprint $table) {
            $table->dropIndex('idx_work_scheduler_optimized');
        });
        
        Schema::table('employee_leaves', function (Blueprint $table) {
            $table->dropIndex('idx_leaves_optimized');
        });
        
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropIndex('idx_holiday_date');
        });
    }
}