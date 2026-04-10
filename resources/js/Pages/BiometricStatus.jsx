import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { router } from "@inertiajs/react";
import {
    ScanOutlined,
    CheckCircleOutlined,
    StopOutlined,
    SearchOutlined,
    TeamOutlined,
    FilterOutlined,
    ExclamationCircleOutlined,
} from "@ant-design/icons";
import { useState, useContext } from "react";
import { ThemeContext } from "@/Components/ThemeContext";
import {
    ConfigProvider,
    Select,
    Button,
    Table,
    Tag,
    Input,
    Space,
    Typography,
    Badge,
    Modal,
    Alert,
    Statistic,
    Card,
    Row,
    Col,
    theme as antTheme,
} from "antd";

const { Text } = Typography;
const { Option } = Select;
const { darkAlgorithm, defaultAlgorithm } = antTheme;

// ── Main Export ───────────────────────────────────────────────────────────────

export default function BiometricStatus({
    employees = [],
    departments = [],
    prodlines = [],
    filters = {},
    counts = { total: 0, enabled: 0, disabled: 0 },
    flash = {},
}) {
    const { theme } = useContext(ThemeContext);
    const isDark = theme === "dark";

    return (
        <AuthenticatedLayout>
            <ConfigProvider
                theme={{
                    algorithm: isDark ? darkAlgorithm : defaultAlgorithm,
                }}
            >
                <BiometricStatusInner
                    employees={employees}
                    departments={departments}
                    prodlines={prodlines}
                    filters={filters}
                    counts={counts}
                    flash={flash}
                    isDark={isDark}
                />
            </ConfigProvider>
        </AuthenticatedLayout>
    );
}

// ── Inner Component ───────────────────────────────────────────────────────────

function BiometricStatusInner({
    employees,
    departments,
    prodlines,
    filters,
    counts,
    flash,
    isDark,
}) {
    // ── Filter state ──────────────────────────────────────────────────────────
    const [search, setSearch]       = useState(filters.search    ?? "");
    const [status, setStatus]       = useState(filters.status    ?? "all");
    const [department, setDept]     = useState(filters.department ?? "all");
    const [prodline, setProdline]   = useState(filters.prodline  ?? "all");

    // ── Selection state ───────────────────────────────────────────────────────
    const [selectedKeys, setSelectedKeys] = useState([]);

    // ── Modal state ───────────────────────────────────────────────────────────
    const [confirmModal, setConfirmModal] = useState({
        open: false,
        type: null,      // 'single' | 'bulk'
        action: null,    // 'enable' | 'disable'
        empId: null,
        empName: null,
    });
    const [submitting, setSubmitting] = useState(false);

    // ── Filter handler ────────────────────────────────────────────────────────
    const handleFilter = () => {
        router.get(
            route("biometric-status.index"),
            { search, status, department, prodline },
            { preserveState: true }
        );
    };

    const handleReset = () => {
        setSearch("");
        setStatus("all");
        setDept("all");
        setProdline("all");
        router.get(route("biometric-status.index"), {}, { preserveState: false });
    };

    // ── Single toggle ─────────────────────────────────────────────────────────
    const openSingleConfirm = (empId, empName, action) => {
        setConfirmModal({ open: true, type: "single", action, empId, empName });
    };

    // ── Bulk toggle ───────────────────────────────────────────────────────────
    const openBulkConfirm = (action) => {
        if (selectedKeys.length === 0) return;
        setConfirmModal({ open: true, type: "bulk", action, empId: null, empName: null });
    };

    // ── Submit toggle ─────────────────────────────────────────────────────────
    const handleConfirm = () => {
        setSubmitting(true);
        const { type, action, empId } = confirmModal;

        const payload =
            type === "single"
                ? { emp_id: empId, action }
                : { bulk_ids: JSON.stringify(selectedKeys), bulk_action: action };

        router.post(route("biometric-status.toggle"), payload, {
            preserveState: false,
            onFinish: () => {
                setSubmitting(false);
                setConfirmModal((m) => ({ ...m, open: false }));
                setSelectedKeys([]);
            },
        });
    };

    // ── Table row selection ───────────────────────────────────────────────────
    const rowSelection = {
        selectedRowKeys: selectedKeys,
        onChange: (keys) => setSelectedKeys(keys),
        selections: [
            Table.SELECTION_ALL,
            Table.SELECTION_NONE,
            {
                key: "enabled",
                text: "Select Enabled",
                onSelect: () =>
                    setSelectedKeys(
                        employees.filter((e) => e.BIOMETRIC_STATUS === "Enabled").map((e) => e.EMPLOYID)
                    ),
            },
            {
                key: "disabled",
                text: "Select Disabled",
                onSelect: () =>
                    setSelectedKeys(
                        employees.filter((e) => e.BIOMETRIC_STATUS === "Disabled").map((e) => e.EMPLOYID)
                    ),
            },
        ],
    };

    // ── Columns ───────────────────────────────────────────────────────────────
    const columns = [
        {
            title: "Employee ID",
            dataIndex: "EMPLOYID",
            key: "EMPLOYID",
            width: 120,
            render: (id) => (
                <Text code style={{ fontSize: "0.8rem", fontWeight: 600 }}>
                    {id}
                </Text>
            ),
        },
        {
            title: "Name",
            dataIndex: "EMPNAME",
            key: "EMPNAME",
            ellipsis: true,
            render: (name) => (
                <Text style={{ fontSize: "0.8rem", fontWeight: 500 }}>{name}</Text>
            ),
        },
        {
            title: "Department",
            dataIndex: "DEPARTMENT",
            key: "DEPARTMENT",
            width: 160,
            ellipsis: true,
            render: (v) => <Text style={{ fontSize: "0.8rem" }}>{v || "—"}</Text>,
        },
        {
            title: "Prodline",
            dataIndex: "PRODLINE",
            key: "PRODLINE",
            width: 120,
            render: (v) => <Text style={{ fontSize: "0.8rem" }}>{v || "—"}</Text>,
        },
        {
            title: "Station",
            dataIndex: "STATION",
            key: "STATION",
            width: 100,
            render: (v) => <Text style={{ fontSize: "0.8rem" }}>{v || "—"}</Text>,
        },
        {
            title: "Biometric Status",
            dataIndex: "BIOMETRIC_STATUS",
            key: "BIOMETRIC_STATUS",
            align: "center",
            width: 140,
            render: (s) => {
                const enabled = s === "Enabled";
                return (
                    <Tag
                        color={enabled ? "success" : "error"}
                        icon={enabled ? <ScanOutlined /> : <StopOutlined />}
                        style={{ fontWeight: 600, fontSize: "0.75rem" }}
                    >
                        {s}
                    </Tag>
                );
            },
        },
        {
            title: "Action",
            key: "action",
            align: "center",
            width: 110,
            render: (_, row) => {
                const enabled = row.BIOMETRIC_STATUS === "Enabled";
                return enabled ? (
                    <Button
                        size="small"
                        danger
                        icon={<StopOutlined />}
                        style={{ fontSize: "0.75rem" }}
                        onClick={() =>
                            openSingleConfirm(row.EMPLOYID, row.EMPNAME, "disable")
                        }
                    >
                        Disable
                    </Button>
                ) : (
                    <Button
                        size="small"
                        icon={<CheckCircleOutlined />}
                        style={{
                            fontSize: "0.75rem",
                            color: "#389e0d",
                            borderColor: "#389e0d",
                        }}
                        onClick={() =>
                            openSingleConfirm(row.EMPLOYID, row.EMPNAME, "enable")
                        }
                    >
                        Enable
                    </Button>
                );
            },
        },
    ];

    // ── Confirm modal config ──────────────────────────────────────────────────
    const isEnable  = confirmModal.action === "enable";
    const modalTitle = confirmModal.type === "bulk"
        ? `${isEnable ? "Enable" : "Disable"} ${selectedKeys.length} Employee(s)`
        : `${isEnable ? "Enable" : "Disable"} Biometric`;

    const modalBody = confirmModal.type === "bulk"
        ? `Are you sure you want to ${isEnable ? "enable" : "disable"} biometric access for ${selectedKeys.length} selected employee(s)?`
        : `Are you sure you want to ${isEnable ? "enable" : "disable"} biometric access for ${confirmModal.empName}?`;

    // ── Render ────────────────────────────────────────────────────────────────
    return (
        <div style={{ padding: 16, height: "100%", display: "flex", flexDirection: "column" }}>
            <div
                style={{
                    border: `1px solid ${isDark ? "rgba(255,255,255,0.15)" : "rgba(0,0,0,0.15)"}`,
                    borderRadius: 8,
                    background: "var(--ant-color-bg-container)",
                    display: "flex",
                    flexDirection: "column",
                    flex: 1,
                    overflow: "hidden",
                }}
            >
                {/* ── Page Header ─────────────────────────────────────── */}
                <div
                    style={{
                        padding: "16px 24px",
                        borderBottom: `1px solid ${isDark ? "rgba(255,255,255,0.15)" : "rgba(0,0,0,0.12)"}`,
                        flexShrink: 0,
                    }}
                >
                    <div
                        style={{
                            display: "flex",
                            flexWrap: "wrap",
                            alignItems: "center",
                            justifyContent: "space-between",
                            gap: 12,
                        }}
                    >
                        <div>
                            <h1
                                style={{
                                    margin: 0,
                                    fontSize: 22,
                                    fontWeight: 600,
                                    display: "flex",
                                    alignItems: "center",
                                    gap: 8,
                                    color: "var(--ant-color-primary)",
                                }}
                            >
                                <ScanOutlined />
                                Biometric Status Management
                            </h1>
                            <Text type="secondary" style={{ fontSize: "0.8rem" }}>
                                Enable or disable biometric access per employee
                            </Text>
                        </div>
                    </div>
                </div>

                <div style={{ flex: 1, overflow: "auto", padding: "16px 24px" }}>
                    {/* ── Flash Messages ─────────────────────────────── */}
                    {flash?.success && (
                        <Alert
                            type="success"
                            showIcon
                            title={<span dangerouslySetInnerHTML={{ __html: flash.success }} />}
                            closable
                            style={{ marginBottom: 16 }}
                        />
                    )}
                    {flash?.error && (
                        <Alert
                            type="error"
                            showIcon
                            title={<span dangerouslySetInnerHTML={{ __html: flash.error }} />}
                            closable
                            style={{ marginBottom: 16 }}
                        />
                    )}

                    {/* ── Summary Cards ──────────────────────────────── */}
                    <Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
                        <Col xs={24} sm={8}>
                            <Card
                                size="small"
                                bordered={false}
                                style={{
                                    boxShadow: "0 1px 4px rgba(0,0,0,0.1)",
                                    borderLeft: "4px solid var(--ant-color-primary)",
                                }}
                            >
                                <Statistic
                                    title={
                                        <Text style={{ fontSize: "0.75rem" }} type="secondary">
                                            Total Employees
                                        </Text>
                                    }
                                    value={counts.total}
                                    prefix={<TeamOutlined style={{ opacity: 0.4 }} />}
                                    valueStyle={{ fontSize: "1.4rem", fontWeight: 700, color: "var(--ant-color-primary)" }}
                                />
                            </Card>
                        </Col>
                        <Col xs={24} sm={8}>
                            <Card
                                size="small"
                                bordered={false}
                                style={{
                                    boxShadow: "0 1px 4px rgba(0,0,0,0.1)",
                                    borderLeft: "4px solid #52c41a",
                                }}
                            >
                                <Statistic
                                    title={
                                        <Text style={{ fontSize: "0.75rem" }} type="secondary">
                                            Biometric Enabled
                                        </Text>
                                    }
                                    value={counts.enabled}
                                    prefix={<ScanOutlined style={{ opacity: 0.4, color: "#52c41a" }} />}
                                    valueStyle={{ fontSize: "1.4rem", fontWeight: 700, color: "#52c41a" }}
                                />
                            </Card>
                        </Col>
                        <Col xs={24} sm={8}>
                            <Card
                                size="small"
                                bordered={false}
                                style={{
                                    boxShadow: "0 1px 4px rgba(0,0,0,0.1)",
                                    borderLeft: "4px solid #ff4d4f",
                                }}
                            >
                                <Statistic
                                    title={
                                        <Text style={{ fontSize: "0.75rem" }} type="secondary">
                                            Biometric Disabled
                                        </Text>
                                    }
                                    value={counts.disabled}
                                    prefix={<StopOutlined style={{ opacity: 0.4, color: "#ff4d4f" }} />}
                                    valueStyle={{ fontSize: "1.4rem", fontWeight: 700, color: "#ff4d4f" }}
                                />
                            </Card>
                        </Col>
                    </Row>

                    {/* ── Filters ────────────────────────────────────── */}
                    <Card
                        size="small"
                        bordered={false}
                        style={{
                            marginBottom: 16,
                            boxShadow: "0 1px 4px rgba(0,0,0,0.08)",
                        }}
                    >
                        <Space wrap size={8} style={{ width: "100%" }}>
                            <Input
                                size="small"
                                prefix={<SearchOutlined />}
                                placeholder="Name or ID..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onPressEnter={handleFilter}
                                style={{ width: 200 }}
                                allowClear
                            />
                            <Select
                                size="small"
                                value={status}
                                onChange={setStatus}
                                style={{ width: 130 }}
                            >
                                <Option value="all">All Status</Option>
                                <Option value="Enabled">Enabled</Option>
                                <Option value="Disabled">Disabled</Option>
                            </Select>
                            <Select
                                size="small"
                                value={department}
                                onChange={setDept}
                                style={{ width: 180 }}
                                showSearch
                                placeholder="All Departments"
                                optionFilterProp="children"
                            >
                                <Option value="all">All Departments</Option>
                                {departments.map((d) => (
                                    <Option key={d} value={d}>{d}</Option>
                                ))}
                            </Select>
                            <Select
                                size="small"
                                value={prodline}
                                onChange={setProdline}
                                style={{ width: 150 }}
                                showSearch
                                placeholder="All Prodlines"
                                optionFilterProp="children"
                            >
                                <Option value="all">All Prodlines</Option>
                                {prodlines.map((p) => (
                                    <Option key={p} value={p}>{p}</Option>
                                ))}
                            </Select>
                            <Button
                                size="small"
                                type="primary"
                                icon={<FilterOutlined />}
                                onClick={handleFilter}
                            >
                                Filter
                            </Button>
                            <Button size="small" onClick={handleReset}>
                                Reset
                            </Button>
                        </Space>
                    </Card>

                    {/* ── Bulk Actions Bar ───────────────────────────── */}
                    <div
                        style={{
                            display: "flex",
                            alignItems: "center",
                            justifyContent: "space-between",
                            marginBottom: 8,
                        }}
                    >
                        <Space size={8}>
                            <Text style={{ fontSize: "0.8rem" }} type="secondary">
                                Showing{" "}
                                <Text strong style={{ fontSize: "0.8rem" }}>
                                    {employees.length}
                                </Text>{" "}
                                employee(s)
                            </Text>
                            {selectedKeys.length > 0 && (
                                <Badge
                                    count={`${selectedKeys.length} selected`}
                                    style={{
                                        backgroundColor: "var(--ant-color-primary)",
                                        fontSize: "0.72rem",
                                    }}
                                />
                            )}
                        </Space>

                        {selectedKeys.length > 0 && (
                            <Space size={8}>
                                <Button
                                    size="small"
                                    icon={<CheckCircleOutlined />}
                                    style={{ color: "#389e0d", borderColor: "#389e0d", fontSize: "0.75rem" }}
                                    onClick={() => openBulkConfirm("enable")}
                                >
                                    Enable Selected
                                </Button>
                                <Button
                                    size="small"
                                    danger
                                    icon={<StopOutlined />}
                                    style={{ fontSize: "0.75rem" }}
                                    onClick={() => openBulkConfirm("disable")}
                                >
                                    Disable Selected
                                </Button>
                            </Space>
                        )}
                    </div>

                    {/* ── Table ──────────────────────────────────────── */}
                    <Table
                        columns={columns}
                        dataSource={employees}
                        rowKey="EMPLOYID"
                        size="small"
                        pagination={{ pageSize: 20, showSizeChanger: true, showQuickJumper: true }}
                        scroll={{ x: "max-content" }}
                        bordered
                        rowSelection={rowSelection}
                        rowClassName={(row) =>
                            row.BIOMETRIC_STATUS === "Disabled" ? "biometric-disabled-row" : ""
                        }
                        locale={{
                            emptyText: (
                                <div style={{ padding: "40px 0", textAlign: "center", opacity: 0.4 }}>
                                    <ScanOutlined style={{ fontSize: 32, marginBottom: 8 }} />
                                    <div style={{ fontSize: 14 }}>
                                        No employees found matching your filters.
                                    </div>
                                </div>
                            ),
                        }}
                        onRow={() => ({ style: { fontSize: "0.8rem" } })}
                    />
                </div>
            </div>

            {/* ── Confirm Modal ────────────────────────────────────────── */}
            <Modal
                open={confirmModal.open}
                title={
                    <Space>
                        <ExclamationCircleOutlined style={{ color: isEnable ? "#52c41a" : "#ff4d4f" }} />
                        {modalTitle}
                    </Space>
                }
                onCancel={() => setConfirmModal((m) => ({ ...m, open: false }))}
                onOk={handleConfirm}
                okText={isEnable ? "Yes, Enable" : "Yes, Disable"}
                okButtonProps={{
                    danger: !isEnable,
                    loading: submitting,
                    style: isEnable ? { background: "#52c41a", borderColor: "#52c41a" } : {},
                }}
                cancelButtonProps={{ disabled: submitting }}
                width={380}
                centered
            >
                <p style={{ fontSize: "0.875rem", margin: 0 }}>{modalBody}</p>
            </Modal>

            {/* ── Row highlight style ──────────────────────────────────── */}
            <style>{`
                .biometric-disabled-row td {
                    background-color: rgba(220, 53, 69, 0.04) !important;
                }
            `}</style>
        </div>
    );
}