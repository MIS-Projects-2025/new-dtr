// resources/js/hooks/useAttendanceCounter.js
import { useState, useEffect } from 'react';
import { dashboardService } from '../services/dashboardService';

const emptyCounter = {
    present: 0, absent: 0, late: 0, restday: 0,
    present_dates: [], absent_dates: [], late_dates: [], restday_dates: [],
};

export function useAttendanceCounter(empId, startDate, endDate) {
    const [counter, setCounter]     = useState(emptyCounter);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        if (!empId || !startDate || !endDate) return;
        setIsLoading(true);
        dashboardService.getAttendanceCounter(empId, startDate, endDate)
            .then(({ data }) => setCounter(data))
            .catch(() => setCounter(emptyCounter))
            .finally(() => setIsLoading(false));
    }, [empId, startDate, endDate]);

    return { counter, isLoading };
}