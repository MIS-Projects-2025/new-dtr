import { usePage } from "@inertiajs/react";
import SidebarLink from "@/Components/sidebar/SidebarLink";
import { 
  LayoutDashboard, 
  CalendarDays, 
  Users, 
  ScanLine, 
  Fingerprint,
  Clock 
} from "lucide-react";

export default function NavLinks({ isSidebarOpen }) {
    const { emp_data } = usePage().props;

    return (
        <nav
            className="flex flex-col flex-grow space-y-1 overflow-y-auto"
            style={{ scrollbarWidth: "none" }}
        >
            <SidebarLink
                href={route("dashboard")}
                icon={<LayoutDashboard size={20} />}
                label="Dashboard"
                isSidebarOpen={isSidebarOpen}
            />
            <SidebarLink
                href={route("dtr.index")}
                icon={<CalendarDays size={20} />}
                label="Daily Time Record"
                isSidebarOpen={isSidebarOpen}
            />
            <SidebarLink
                href={route("biometric-status.index")}
                icon={<Users size={20} />}
                label="Employee Biometric Management"
                isSidebarOpen={isSidebarOpen}
            />
            <SidebarLink
                href={route("scan-logs.index")}
                icon={<Clock size={20} />}
                label="Scan Log"
                isSidebarOpen={isSidebarOpen}
            />
            <SidebarLink
                href={route("register-fingerprint.index")}
                icon={<Fingerprint size={20} />}
                label="Register Fingerprint"
                isSidebarOpen={isSidebarOpen}
            />
        </nav>
    );
}