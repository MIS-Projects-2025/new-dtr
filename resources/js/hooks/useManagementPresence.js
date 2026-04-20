import { useState, useEffect } from 'react';
import { dashboardService } from '../services/dashboardService';

export function useManagementPresence() {
    const [employees, setEmployees]  = useState([]);
    const [isLoading, setIsLoading]  = useState(false);

    useEffect(() => {
        setIsLoading(true);
        dashboardService.getManagementPresence()
            .then(({ data }) => setEmployees(data))
            .catch(() => setEmployees([]))
            .finally(() => setIsLoading(false));
    }, []); // fires once on mount

    return { employees, isLoading };
}