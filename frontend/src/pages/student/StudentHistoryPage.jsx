import { useEffect, useState } from "react";
import api from "../../services/api";
import { formatDate, formatMoney } from "../../lib/format";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

export default function StudentHistoryPage() {
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get("/student/history")
      .then((response) => setHistory(response.data))
      .catch(() => setHistory([]))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader title="Scholarship History" subtitle="Scholarships you have been granted" />

      <div className="card">
        {history.length === 0 ? (
          <EmptyState message="No scholarship history yet. It appears here once OAS tags you as a grantee." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Scholarship</th>
                  <th>Status</th>
                  <th>Enrollment</th>
                  <th>Tagged</th>
                  <th>Payroll</th>
                </tr>
              </thead>

              <tbody>
                {history.map((record) => (
                  <tr key={record.id}>
                    <td>{record.scholarship?.name}</td>

                    <td>
                      <StatusBadge status={record.status} />
                    </td>

                    <td>{record.currently_enrolled ? "Currently enrolled" : "Not enrolled"}</td>

                    <td>{formatDate(record.grantee_tagged_at)}</td>

                    <td>
                      {record.payroll_records?.length
                        ? record.payroll_records
                            .map((item) => `${item.period}: ${formatMoney(item.amount)} (${item.status})`)
                            .join("; ")
                        : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
