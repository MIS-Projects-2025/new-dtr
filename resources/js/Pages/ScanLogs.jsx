import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import ScanLogPanel from "@/Components/ScanLogPanel";

export default function ScanLogs() {
    return (
        <AuthenticatedLayout>
            <ScanLogPanel />
        </AuthenticatedLayout>
    );
}
