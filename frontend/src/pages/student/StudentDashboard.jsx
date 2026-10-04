import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../../services/api";
import { formatDate, formatMoney } from "../../lib/format";
import PageHeader from "../../components/PageHeader";
import StatCard from "../../components/StatCard";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

export default function StudentDashboard() {
  const [applications, setApplications] = useState([]);
  const [scholarships, setScholarships] = useState([]);
  const [profile, setProfile] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([api.get("/my-applications"), api.get("/scholarships"), api.get("/profile")])
      .then(([apps, scholarshipsResponse, profileResponse]) => {
        setApplications(apps.data);
        setScholarships(scholarshipsResponse.data);
        setProfile(profileResponse.data);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loading />;

  const count = (status) => applications.filter((a) => a.status === status).length;

  return (
    <div>
      <PageHeader
        title="Student Dashboard"
        subtitle={
          profile?.student
            ? `Welcome, ${profile.student.first_name}`
            : "Manage your scholarship applications"
        }
      />

      <div className="stats-grid">
        <StatCard title="Available Scholarships" value={scholarships.length} />
        <StatCard title="My Applications" value={applications.length} />
        <StatCard title="Under Review" value={count("under_review") + count("submitted")} />
        <StatCard title="Approved" value={count("approved")} />
      </div>

      <div className="dashboard-grid">
        <section className="card">
          <div className="card-header">
            <h2>Available Scholarships</h2>

            <Link className="button button-secondary" to="/student/scholarships">
              View all
            </Link>
          </div>

          {scholarships.length === 0 ? (
            <EmptyState message="No scholarships available." />
          ) : (
            scholarships.slice(0, 5).map((scholarship) => (
              <div className="list-item" key={scholarship.id}>
                <div>
                  <Link to={`/student/scholarships/${scholarship.id}`}>
                    <strong>{scholarship.name}</strong>
                  </Link>

                  <p className="muted">{scholarship.provider || "CSU"}</p>
                </div>

                <strong>{formatMoney(scholarship.amount)}</strong>
              </div>
            ))
          )}
        </section>

        <section className="card">
          <div className="card-header">
            <h2>Recent Applications</h2>

            <Link className="button button-secondary" to="/student/applications">
              View all
            </Link>
          </div>

          {applications.length === 0 ? (
            <EmptyState message="You have no applications yet." />
          ) : (
            applications.slice(0, 5).map((application) => (
              <div className="list-item" key={application.id}>
                <div>
                  <Link to={`/student/applications/${application.id}`}>
                    <strong>{application.scholarship?.name || "Scholarship"}</strong>
                  </Link>

                  <p className="muted">
                    {application.submitted_at
                      ? `Submitted ${formatDate(application.submitted_at)}`
                      : "Not submitted yet"}
                  </p>
                </div>

                <StatusBadge status={application.status} />
              </div>
            ))
          )}
        </section>
      </div>
    </div>
  );
}
