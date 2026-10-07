import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import api from "../../services/api";
import { STATUS_HELP, enrollmentText, formatDate, timeAgo } from "../../lib/format";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const FILTERS = [
  ["all", "All", () => true],
  ["processing", "Being processed", (a) => ["submitted", "under_review", "complete"].includes(a.status)],
  ["needs_action", "Needs action", (a) => a.status === "needs_action"],
  ["approved", "Approved", (a) => a.status === "approved"],
  ["draft", "Drafts", (a) => a.status === "draft"],
  ["rejected", "Not approved", (a) => a.status === "rejected"],
];

export default function MyApplicationsPage() {
  // /student/applications?status=approved opens the list already filtered.
  const location = useLocation();
  const [filter, setFilter] = useState(() => {
    const wanted = new URLSearchParams(location.search).get("status");
    return FILTERS.some(([key]) => key === wanted) ? wanted : "all";
  });
  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get("/my-applications")
      .then((response) => setApplications(response.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loading />;

  const matches = FILTERS.find(([key]) => key === filter)[2];
  const shown = applications.filter(matches);

  return (
    <div>
      <PageHeader title="My Applications" subtitle="Track your scholarship applications" />

      <div className="card">
        {applications.length > 0 && (
          <div className="filter-row" role="group" aria-label="Filter my applications">
            {FILTERS.map(([key, label, test]) => (
              <button
                key={key}
                className={filter === key ? "button button-small button-primary" : "button button-small button-secondary"}
                aria-pressed={filter === key}
                onClick={() => setFilter(key)}
              >
                {label} ({applications.filter(test).length})
              </button>
            ))}
          </div>
        )}

        {applications.length === 0 ? (
          <EmptyState
            message="You have not applied yet."
            action={
              <Link className="button button-primary" to="/student/scholarships">
                Browse scholarships
              </Link>
            }
          />
        ) : shown.length === 0 ? (
          <EmptyState message="No applications in this list." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Scholarship</th>
                  <th>Submitted</th>
                  <th>Status</th>
                  <th>Last update</th>
                  <th>Enrollment</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {shown.map((application) => (
                  <tr key={application.id}>
                    <td>{application.scholarship?.name || "Scholarship"}</td>

                    <td>
                      {application.submitted_at
                        ? formatDate(application.submitted_at)
                        : "Not submitted yet"}
                    </td>

                    <td>
                      <StatusBadge status={application.status} />
                      <div className="muted small status-meaning">{STATUS_HELP[application.status]?.text}</div>
                    </td>

                    <td>{timeAgo(application.updated_at)}</td>

                    <td>{enrollmentText(application)}</td>

                    <td>
                      <Link
                        className="button button-small button-secondary"
                        to={`/student/applications/${application.id}`}
                      >
                        {["draft", "needs_action"].includes(application.status) ? "Continue" : "View"}
                      </Link>
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
