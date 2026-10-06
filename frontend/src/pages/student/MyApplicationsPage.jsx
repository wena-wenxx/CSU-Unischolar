import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../../services/api";
import { STATUS_HELP, enrollmentText, formatDate, timeAgo } from "../../lib/format";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

export default function MyApplicationsPage() {
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

  return (
    <div>
      <PageHeader title="My Applications" subtitle="Track your scholarship applications" />

      <div className="card">
        {applications.length === 0 ? (
          <EmptyState
            message="You have not applied yet."
            action={
              <Link className="button button-primary" to="/student/scholarships">
                Browse scholarships
              </Link>
            }
          />
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
                {applications.map((application) => (
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
