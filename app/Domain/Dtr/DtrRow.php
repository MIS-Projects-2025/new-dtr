<?php

namespace App\Domain\Dtr;

readonly class DtrRow
{
    public function __construct(
        // Identity
        public string  $date,
        public string  $day,
        public string  $code,
        public string  $shiftType,
        public bool    $isNight,

        // Actual punches
        public string  $timeIn,
        public string  $breakOut1,
        public string  $breakIn1,
        public string  $lunchOut,
        public string  $lunchIn,
        public string  $breakOut2,
        public string  $breakIn2,
        public string  $timeOut,

        // Expected times (shown as "exp:" labels in the UI)
        public string  $expTimeIn,
        public string  $expBreakOut1,
        public string  $expBreakIn1,
        public string  $expLunchOut,
        public string  $expLunchIn,
        public string  $expBreakOut2,
        public string  $expBreakIn2,
        public string  $expTimeOut,

        // Remarks / status
        public string  $remarks,

        // Extra info (nullable)
        public ?array  $leaveInfo,
        public ?array  $holidayInfo,
        public ?array  $obInfo,
        public array   $obCovered,
        public bool    $isFullOb,
    ) {}

    /**
     * Serialize to the plain array shape the Inertia/React front-end expects.
     * Keeps the domain object decoupled from the JSON key naming convention.
     */
    public function toArray(): array
    {
        return [
            'date'           => $this->date,
            'day'            => $this->day,
            'code'           => $this->code,
            'shift_type'     => $this->shiftType,
            'is_night'       => $this->isNight,

            'time_in'        => $this->timeIn,
            'break_out_1'    => $this->breakOut1,
            'break_in_1'     => $this->breakIn1,
            'lunch_out'      => $this->lunchOut,
            'lunch_in'       => $this->lunchIn,
            'break_out_2'    => $this->breakOut2,
            'break_in_2'     => $this->breakIn2,
            'time_out'       => $this->timeOut,

            'exp_time_in'    => $this->expTimeIn,
            'exp_break_out_1'=> $this->expBreakOut1,
            'exp_break_in_1' => $this->expBreakIn1,
            'exp_lunch_out'  => $this->expLunchOut,
            'exp_lunch_in'   => $this->expLunchIn,
            'exp_break_out_2'=> $this->expBreakOut2,
            'exp_break_in_2' => $this->expBreakIn2,
            'exp_time_out'   => $this->expTimeOut,

            'remarks'        => $this->remarks,
            'leave_info'     => $this->leaveInfo,
            'holiday_info'   => $this->holidayInfo,
            'ob_info'        => $this->obInfo,
            'ob_covered'     => $this->obCovered,
            'is_full_ob'     => $this->isFullOb,
        ];
    }
}