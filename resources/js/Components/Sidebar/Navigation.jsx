import { usePage } from "@inertiajs/react";
import SidebarLink from "@/Components/Sidebar/SidebarLink";
import { 
  LayoutDashboard, 
  CalendarDays, 
  Users, 
  Fingerprint,
  Clock,
  Award,
  BarChart3
} from "lucide-react";

export default function NavLinks({ isSidebarOpen }) {
    const { emp_data } = usePage().props;

    const empPosition = Number(emp_data?.emp_position);
    const isHR = emp_data?.emp_dept === "Human Resource";
    const isPosition1 = empPosition === 1;
    const isPosition2 = empPosition === 2;
    const isPosition3 = empPosition === 3;
    const isPosition4 = empPosition === 4;
    const isPosition5 = empPosition === 5;

    return (
        <nav
            className="flex flex-col flex-grow space-y-1 overflow-y-auto"
            style={{ scrollbarWidth: "none" }}
        >
            {/* Always visible */}
            <SidebarLink
                href={route("dashboard")}
                icon={<LayoutDashboard size={20} />}
                label="Dashboard"
                isSidebarOpen={isSidebarOpen}
            />

            {/* Position 1, 2 or HR */}
            {(isHR || isPosition1 || isPosition2) && (
                <SidebarLink
                    href={route("dtr.index")}
                    icon={<CalendarDays size={20} />}
                    label="Daily Time Record"
                    isSidebarOpen={isSidebarOpen}
                />
            )}

            {/* HR only */}
            {isHR && (
                <SidebarLink
                    href={route("BioManagement")}
                    icon={<CalendarDays size={20} />}
                    label="Biometric Management"
                    isSidebarOpen={isSidebarOpen}
                />
            )}

            {/* Position 2, 3 or HR */}
            {(isHR || isPosition2 || isPosition3) && (
                <SidebarLink
                    href={route("biometric-status.index")}
                    icon={<Users size={20} />}
                    label="Employee Biometric Management"
                    isSidebarOpen={isSidebarOpen}
                />
            )}

            {/* Position 2, 3, 4, 5 or HR */}
            {(isHR || isPosition2 || isPosition3 || isPosition4 || isPosition5) && (
                <SidebarLink
                    href={route("attendance.summary")}
                    icon={<BarChart3 size={20} />}
                    label="Attendance Summary"
                    isSidebarOpen={isSidebarOpen}
                />
            )}

            {/* Position 2, 3, 4, 5 or HR */}
            {(isHR || isPosition2 || isPosition3 || isPosition4 || isPosition5) && (
                <SidebarLink
                    href={route("perfect-attendance.index")}
                    icon={<Award size={20} />}
                    label="Perfect Attendance"
                    isSidebarOpen={isSidebarOpen}
                />
            )}

            {/* Position 1, 2 or HR */}
            {(isHR || isPosition1 || isPosition2) && (
                <SidebarLink
                    href={route("scan-logs.index")}
                    icon={<Clock size={20} />}
                    label="Scan Log"
                    isSidebarOpen={isSidebarOpen}
                />
            )}

            {/* HR only */}
            {isHR && (
                <SidebarLink
                    href={route("register-fingerprint")}
                    icon={<Fingerprint size={20} />}
                    label="Register Fingerprint"
                    isSidebarOpen={isSidebarOpen}
                />
            )}
        </nav>
    );
}