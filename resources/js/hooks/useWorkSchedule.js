// resources/js/hooks/useWorkSchedule.js
import { useState, useEffect } from 'react';
import { dashboardService } from '../services/dashboardService';

export function useWorkSchedule(empId, selectedDate) {
    const [workSchedule, setWorkSchedule] = useState(null);
    const [isLoading, setIsLoading]       = useState(false);

    useEffect(() => {
        if (!empId || !selectedDate) return;
        setIsLoading(true);
        dashboardService.getWorkSchedule(empId, selectedDate)
            .then(({ data }) => setWorkSchedule(data))
            .catch(console.error)
            .finally(() => setIsLoading(false));
    }, [empId, selectedDate]);

    return { workSchedule, isLoading };
}