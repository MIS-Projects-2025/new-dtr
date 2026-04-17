// resources/js/services/dashboardService.js
import axios from 'axios';

const appName = import.meta.env.VITE_APP_NAME || '';
const base    = `/${appName}/dashboard`;

export const dashboardService = {
    getWorkSchedule: (empId, selectedDate) =>
        axios.get(`${base}/work-schedule`, { params: { emp_id: empId, selected_date: selectedDate } }),

    getShiftLogs: (empId, date) =>
        axios.get(`${base}/shift-logs`, { params: { emp_id: empId, date } }),

    getAttendanceCounter: (empId, startDate, endDate) =>
        axios.get(`${base}/attendance-counter`, { params: { emp_id: empId, start_date: startDate, end_date: endDate } }),
};