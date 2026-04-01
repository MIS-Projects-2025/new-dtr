import { usePage } from "@inertiajs/react";
import SidebarLink from "@/Components/sidebar/SidebarLink";
import { LayoutDashboard, ScanLine, Fingerprint } from "lucide-react";
import Dropdown from "./DropDown";

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
                href={route("scanlogs")}
                icon={<ScanLine size={20} />}
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