import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import { ScanOutlined } from "@ant-design/icons"; // ✅ added import

export default function DailyTimeRecord({ tableData, tableFilters }) {
    const props = usePage().props;

    return (
        <AuthenticatedLayout>
            <div className="p-4 h-full flex flex-col">
                <div className="border border-base-300 rounded-lg bg-base-100 shadow-sm flex flex-col flex-1 overflow-hidden">

                    {/* ── Header ─────────────────────────────────────────────── */}
                    <div className="flex-shrink-0 px-6 py-4 border-b border-base-300">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <h1 className="text-2xl font-bold text-base-content flex items-center gap-2">
                                <ScanOutlined className="text-primary" />
                                Daily Time Record
                            </h1>
                        </div>
                    </div>
                </div>
            </div>
                       
        </AuthenticatedLayout>
    );
}