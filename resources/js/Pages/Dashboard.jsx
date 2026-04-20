import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";

import EmployeeDashboard from "@/Components/EmployeeDashboard";
import AdminDashboard from "@/Components/AdminDashboard";

export default function Dashboard() {
    const { emp_data } = usePage().props;

    const emp_position = Number(emp_data?.emp_position);

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            {emp_position === 1 ? (
                <EmployeeDashboard emp_data={emp_data} />
            ) : (
                <AdminDashboard emp_data={emp_data} />
            )}
        </AuthenticatedLayout>
    );
}