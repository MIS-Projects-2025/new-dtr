// resources/js/hooks/useShiftLogs.js
import { useState, useEffect } from 'react';
import { dashboardService } from '../services/dashboardService';

export function useShiftLogs(empId, date, workSchedule) {
    const [logs, setLogs]           = useState(null);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        // Wait for workSchedule to finish loading before firing — avoids duplicate schedule query
        if (!empId || !date || workSchedule === null) return;
        setIsLoading(true);
        dashboardService.getShiftLogs(empId, date)
            .then(({ data }) => setLogs(data))
            .catch(console.error)
            .finally(() => setIsLoading(false));
    }, [empId, date, workSchedule]);

    return { logs, isLoading };
}