export default function AdminDashboard({ emp_data }) {
    return (
        <div>
            <h2 className="text-xl font-semibold mb-2">
                Admin Dashboard
            </h2>

            <div className="p-4 border rounded shadow">
                <p><strong>Position:</strong> {emp_data?.emp_position}</p>
            </div>
        </div>
    );
}