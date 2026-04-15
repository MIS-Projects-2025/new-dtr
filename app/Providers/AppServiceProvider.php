<?php

// Add these bindings to your AppServiceProvider::register() method.
// Laravel will auto-resolve constructor dependencies, so you only need these
// if you want to swap implementations (e.g. for testing).

namespace App\Providers;

use App\Repositories\AttendanceRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\HolidayRepository;
use App\Repositories\ScheduleRepository;
use App\Services\AttendanceCountService;
use App\Services\DailyTimeRecordService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Repositories (stateless, safe to singleton) ───────────────────
        $this->app->singleton(EmployeeRepository::class);
        $this->app->singleton(AttendanceRepository::class);
        $this->app->singleton(ScheduleRepository::class);
        $this->app->singleton(HolidayRepository::class);

        // ── Services ─────────────────────────────────────────────────────
        // Laravel resolves all constructor repository arguments automatically.
        $this->app->singleton(DailyTimeRecordService::class);
        $this->app->singleton(AttendanceCountService::class);
        
    }

    public function boot(): void
    {
        //
    }
}