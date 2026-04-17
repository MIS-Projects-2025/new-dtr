// resources/js/hooks/useShiftLogs.js
import { useState, useEffect } from 'react';
import { dashboardService } from '../services/dashboardService';

export function useShiftLogs(empId, date) {
    const [logs, setLogs]         = useState(null);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        if (!empId || !date) return;
        setIsLoading(true);
        dashboardService.getShiftLogs(empId, date)
            .then(({ data }) => setLogs(data))
            .catch(console.error)
            .finally(() => setIsLoading(false));
    }, [empId, date]);

    return { logs, isLoading };
}