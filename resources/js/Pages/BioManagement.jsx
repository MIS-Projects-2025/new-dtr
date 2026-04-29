import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import { useState, useEffect, useRef } from "react";
import axios from 'axios';

const LoadingModal = ({ show, message = 'Preparing your export...' }) => {
    if (!show) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black/40 dark:bg-black/60 backdrop-blur-sm" />
            <div className="relative z-10 flex flex-col items-center gap-4 bg-white dark:bg-zinc-900 rounded-2xl shadow-2xl border border-zinc-200 dark:border-zinc-700 px-10 py-8 min-w-[280px]">
                <div className="relative w-14 h-14">
                    <svg
                        className="animate-spin w-14 h-14 text-zinc-300 dark:text-zinc-700"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                    >
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2.5" />
                        <path
                            className="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                        />
                    </svg>
                    <div className="absolute inset-0 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5 text-zinc-500 dark:text-zinc-400">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                    </div>
                </div>
                <div className="flex flex-col items-center gap-1 text-center">
                    <p className="text-[13px] font-semibold text-zinc-700 dark:text-zinc-200">
                        {message}
                    </p>
                    <p className="text-[10px] text-zinc-400 dark:text-zinc-500">
                        This may take a moment for large date ranges.
                    </p>
                </div>
                <div className="flex items-center gap-1.5">
                    {[0, 1, 2].map(i => (
                        <span
                            key={i}
                            className="w-1.5 h-1.5 rounded-full bg-zinc-400 dark:bg-zinc-500 animate-bounce"
                            style={{ animationDelay: `${i * 0.15}s` }}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
};

const TABS = ['Biometric Logs', 'Data Management'];

export default function BioManagement() {
    const { app_name } = usePage().props;

    const [activeTab,     setActiveTab]     = useState('Biometric Logs');
    const [importFile,    setImportFile]    = useState(null);
    const [importLoading, setImportLoading] = useState(false);
    const [importResult,  setImportResult]  = useState(null);
    const [exportLoading, setExportLoading] = useState(false);
    const [exportMessage, setExportMessage] = useState('Preparing your export...');
    const [exportDateFrom, setExportDateFrom] = useState('');
    const [exportDateTo,   setExportDateTo]   = useState('');
    const exportPollRef = useRef(null);

    useEffect(() => {
        return () => {
            if (exportPollRef.current) clearInterval(exportPollRef.current);
        };
    }, []);

    const waitForDownload = (cookieName) => {
        return new Promise((resolve) => {
            const poll = setInterval(() => {
                if (document.cookie.split(';').some(c => c.trim().startsWith(`${cookieName}=`))) {
                    document.cookie = `${cookieName}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
                    clearInterval(poll);
                    exportPollRef.current = null;
                    resolve();
                }
            }, 500);
            exportPollRef.current = poll;
        });
    };

    const handleImport = async () => {
        if (!importFile) return;
        setImportLoading(true);
        setImportResult(null);
        try {
            const formData = new FormData();
            formData.append('file', importFile);
            const { data } = await axios.post(route('bio.import'), formData);
            setImportResult(data);
            setImportFile(null);
            document.getElementById('bio-import-input').value = '';
        } catch (err) {
            const message = err.response?.data?.message ?? err.message;
            setImportResult({ error: true, message });
        } finally {
            setImportLoading(false);
        }
    };

    const handleExport = async (type) => {
        if (!exportDateFrom || !exportDateTo || exportLoading) return;

        setExportLoading(true);
        setExportMessage(
            type === 'with_breaks'
                ? 'Preparing export with breaks...'
                : 'Preparing export without breaks...'
        );

        const token      = Date.now();
        const cookieName = `bio_export_ready_${token}`;

        try {
            const params = new URLSearchParams({
                date_from:      exportDateFrom,
                date_to:        exportDateTo,
                type,
                download_token: token,
            });

            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = route('bio.export') + '?' + params.toString();
            document.body.appendChild(iframe);

            await waitForDownload(cookieName);

            setTimeout(() => {
                setExportLoading(false);
                if (document.body.contains(iframe)) {
                    document.body.removeChild(iframe);
                }
            }, 500);

        } catch (err) {
            console.error('Export error:', err);
            setExportLoading(false);
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Biometric Management" />

            <LoadingModal show={exportLoading} message={exportMessage} />

            <div className="flex flex-col h-full gap-3 p-4 overflow-hidden">

                {/* Header */}
                <div className="flex items-center justify-between gap-3 flex-wrap flex-shrink-0">
                    <h1 className="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                        Biometric Management
                    </h1>
                </div>

                {/* Main Container */}
                <div className="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden flex-1 min-h-0">

                    {/* Tabs */}
                    <div className="flex items-center gap-0 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
                        {TABS.map(tab => (
                            <button
                                key={tab}
                                onClick={() => setActiveTab(tab)}
                                className={`px-4 py-2.5 text-[11px] font-medium transition-colors border-b-2 -mb-px whitespace-nowrap
                                    ${activeTab === tab
                                        ? 'border-zinc-700 dark:border-zinc-300 text-zinc-800 dark:text-zinc-100'
                                        : 'border-transparent text-zinc-400 dark:text-zinc-500 hover:text-zinc-600 dark:hover:text-zinc-300'
                                    }`}
                            >
                                {tab}
                            </button>
                        ))}
                    </div>

                    {/* Tab Content */}
                    <div className="flex-1 overflow-auto min-h-0 p-4">

                        {/* ── Biometric Logs Tab ── */}
                        {activeTab === 'Biometric Logs' && (
                            <div className="flex flex-col gap-3 h-full min-h-0">

                                {/* Toolbar */}
                                <div className="flex items-center justify-between gap-2 flex-shrink-0">
                                    <div className="relative">
                                        <svg className="absolute left-2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 text-zinc-400 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
                                        </svg>
                                        <input
                                            type="text"
                                            placeholder="Search employee..."
                                            className="text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 pl-6 pr-3 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400 w-48"
                                        />
                                    </div>
                                    <button className="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900 text-[10px] font-medium hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Add Log
                                    </button>
                                </div>

                                {/* Table */}
                                <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 overflow-hidden flex-1 min-h-0">
                                    <div className="flex-1 overflow-auto min-h-0">
                                        <table className="w-full text-left" style={{ tableLayout: 'fixed', fontSize: 'clamp(8px, 0.8vw, 10px)' }}>
                                            <colgroup>
                                                <col style={{ width: '15%' }} />
                                                <col style={{ width: '25%' }} />
                                                <col style={{ width: '15%' }} />
                                                <col style={{ width: '12%' }} />
                                                <col style={{ width: '13%' }} />
                                                <col style={{ width: '20%' }} />
                                            </colgroup>
                                            <thead className="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                                <tr>
                                                    {['Employee ID', 'Employee Name', 'Date', 'Time', 'Flag', 'Category'].map(h => (
                                                        <th key={h} className="px-3 py-1.5 font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider truncate">
                                                            {h}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                                                <tr>
                                                    <td colSpan={6} className="px-3 py-6 text-center text-zinc-400 dark:text-zinc-500 italic text-[10px]">
                                                        No records found.
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    {/* Pagination */}
                                    <div className="flex items-center justify-between px-3 py-2 border-t border-zinc-100 dark:border-zinc-800 flex-shrink-0">
                                        <span className="text-[9px] text-zinc-400">
                                            0 record(s) &nbsp;·&nbsp; Page 1 of 1
                                        </span>
                                        <div className="flex items-center gap-1">
                                            <button disabled className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                                Prev
                                            </button>
                                            <button disabled className="px-2 py-1 text-[9px] rounded border border-zinc-200 dark:border-zinc-700 disabled:opacity-40 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                                Next
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* ── Data Management Tab ── */}
                        {activeTab === 'Data Management' && (
                            <div className="grid grid-cols-2 gap-4 h-full">

                                {/* Import Biometric Logs */}
                                <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 overflow-hidden">
                                    <div className="px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
                                        <h3 className="text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
                                            Import Biometric Logs
                                        </h3>
                                    </div>
                                    <div className="flex-1 flex flex-col gap-4 p-4">

                                        {/* Guidelines */}
                                        <div className="rounded-md bg-zinc-100 dark:bg-zinc-700/50 px-3 py-2.5 text-[10px] text-zinc-500 dark:text-zinc-400 space-y-1">
                                            <p className="font-semibold text-zinc-600 dark:text-zinc-300">Import Guidelines</p>
                                            <ul className="list-disc list-inside space-y-0.5">
                                                <li>Only <span className="font-medium">.dat</span> files are accepted</li>
                                                <li>Each row must follow this format:</li>
                                                <li className="font-mono bg-zinc-200 dark:bg-zinc-700 px-1.5 py-0.5 rounded list-none">
                                                    EmployeeID &nbsp; Date &nbsp; Time &nbsp; DeviceNo &nbsp; PunchType &nbsp; AuthMode
                                                </li>
                                                <li>Punch types: <span className="font-medium">0</span> = Check In, <span className="font-medium">1</span> = Check Out, <span className="font-medium">2</span> = Break Out, <span className="font-medium">3</span> = Break In</li>
                                                <li>Duplicate records will be automatically skipped</li>
                                            </ul>
                                        </div>

                                        {/* Drop zone */}
                                        <div
                                            onClick={() => document.getElementById('bio-import-input').click()}
                                            onDragOver={(e) => e.preventDefault()}
                                            onDrop={(e) => {
                                                e.preventDefault();
                                                const file = e.dataTransfer.files[0];
                                                if (file) setImportFile(file);
                                            }}
                                            className="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-600 p-8 cursor-pointer hover:border-zinc-400 dark:hover:border-zinc-500 transition-colors"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-8 h-8 text-zinc-300 dark:text-zinc-600">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                            </svg>
                                            <p className="text-[10px] text-zinc-400 dark:text-zinc-500">
                                                {importFile ? importFile.name : 'Click or drag & drop a .dat file here'}
                                            </p>
                                            {importFile && (
                                                <p className="text-[9px] text-zinc-400">
                                                    {(importFile.size / 1024).toFixed(1)} KB
                                                </p>
                                            )}
                                        </div>
                                        <input
                                            id="bio-import-input"
                                            type="file"
                                            accept=".dat"
                                            className="hidden"
                                            onChange={(e) => setImportFile(e.target.files[0] ?? null)}
                                        />

                                        {/* Result message */}
                                        {importResult && (
                                            <div className={`rounded-md px-3 py-2 text-[10px] ${importResult.error ? 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400' : 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400'}`}>
                                                {importResult.message}
                                                {!importResult.error && (
                                                    <div className="mt-1 flex gap-3 text-[9px] text-zinc-500 dark:text-zinc-400">
                                                        <span>✅ Inserted: {importResult.inserted}</span>
                                                        <span>⏭️ Skipped: {importResult.duplicates}</span>
                                                        <span>❌ Errors: {importResult.errors}</span>
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {/* Import button */}
                                        <button
                                            onClick={handleImport}
                                            disabled={!importFile || importLoading}
                                            className="self-end inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900 text-[10px] font-medium hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            {importLoading ? 'Importing...' : 'Import'}
                                        </button>
                                    </div>
                                </div>

                                {/* Export Biometric Logs */}
                                <div className="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 overflow-hidden">
                                    <div className="px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 flex-shrink-0">
                                        <h3 className="text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
                                            Export Biometric Logs
                                        </h3>
                                    </div>
                                    <div className="flex-1 flex flex-col gap-4 p-4">

                                        {/* Guidelines */}
                                        <div className="rounded-md bg-zinc-100 dark:bg-zinc-700/50 px-3 py-2.5 text-[10px] text-zinc-500 dark:text-zinc-400 space-y-1">
                                            <p className="font-semibold text-zinc-600 dark:text-zinc-300">Export Guidelines</p>
                                            <ul className="list-disc list-inside space-y-0.5">
                                                <li>Select a date range to export biometric logs</li>
                                                <li><span className="font-medium">With Breaks</span> — includes all break and lunch time slots</li>
                                                <li><span className="font-medium">Without Breaks</span> — only Time In and Time Out columns</li>
                                                <li>Only employees with actual punch records will be included</li>
                                            </ul>
                                        </div>

                                        {/* Date range */}
                                        <div className="flex flex-col gap-2">
                                            <div className="flex items-center gap-2">
                                                <label className="text-[10px] text-zinc-500 dark:text-zinc-400 w-16 flex-shrink-0">Date From</label>
                                                <input
                                                    type="date"
                                                    value={exportDateFrom}
                                                    onChange={(e) => setExportDateFrom(e.target.value)}
                                                    disabled={exportLoading}
                                                    className="flex-1 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400 disabled:opacity-40"
                                                />
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <label className="text-[10px] text-zinc-500 dark:text-zinc-400 w-16 flex-shrink-0">Date To</label>
                                                <input
                                                    type="date"
                                                    value={exportDateTo}
                                                    onChange={(e) => setExportDateTo(e.target.value)}
                                                    disabled={exportLoading}
                                                    className="flex-1 text-[10px] rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-zinc-400 disabled:opacity-40"
                                                />
                                            </div>
                                        </div>

                                        {/* Export buttons */}
                                        <div className="flex items-center gap-2 self-end mt-auto">
                                            <button
                                                onClick={() => handleExport('without_breaks')}
                                                disabled={!exportDateFrom || !exportDateTo || exportLoading}
                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 text-[10px] font-medium hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                            >
                                                {exportLoading ? (
                                                    <>
                                                        <svg className="animate-spin w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2" />
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                                        </svg>
                                                        Exporting...
                                                    </>
                                                ) : 'Without Breaks'}
                                            </button>
                                            <button
                                                onClick={() => handleExport('with_breaks')}
                                                disabled={!exportDateFrom || !exportDateTo || exportLoading}
                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900 text-[10px] font-medium hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                            >
                                                {exportLoading ? (
                                                    <>
                                                        <svg className="animate-spin w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2" />
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                                        </svg>
                                                        Exporting...
                                                    </>
                                                ) : 'With Breaks'}
                                            </button>
                                        </div>

                                    </div>
                                </div>

                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}