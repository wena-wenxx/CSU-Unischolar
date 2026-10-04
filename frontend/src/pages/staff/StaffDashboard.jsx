import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../../services/api";
import { fullName } from "../../lib/format";
import PageHeader from "../../components/PageHeader";
import StatCard from "../../components/StatCard";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

export default function StaffDashboard() {
  const [stats, setStats] = useState(null);

  useEffect(() => {
    api
      .get("/staff/dashboard")
      .then((response) => setStats(response.data))
      .catch(() => setStats({}));
  }, []);

  if (!stats) return <Loading />;

  return (
    <div>
      <PageHeader title="OAS Staff Dashboard" subtitle="Scholarship management overview" />

      <div className="stats-grid">
        <StatCard title="Total Applicants" value={stats.total_applicants ?? 0} />
        <StatCard title="Applications" value={stats.total_applications ?? 0} />
        <StatCard title="Needs Action" value={stats.needs_action ?? 0} />
        <StatCard title="Approved" value={stats.approved ?? 0} />
        <StatCard title="Active Scholars" value={stats.active_scholars ?? 0} />
        <StatCard title="Payroll Ready" value={stats.payroll_ready ?? 0} />
        <StatCard title="AI Flags" value={stats.ai_flags ?? 0} />
        <StatCard title="Scholarship Programs" value={stats.scholarships ?? 0} />
      </div>

      <div className="quick-actions">
        <Link className="button button-primary" to="/staff/applications">
          Review Applications
        </Link>
        <Link className="button button-secondary" to="/staff/scholars">
          Scholar Records
        </Link>
        <Link className="button button-secondary" to="/staff/payroll">
          Payroll
        </Link>
        <Link className="button button-secondary" to="/staff/data-bank">
          Data Bank
        </Link>
        <Link className="button button-secondary" to="/staff/reports">
          Reports
        </Link>
      </div>

      <section className="card dashboard-recent">
        <h2>Recent Applications</h2>

        {stats.recent_applications?.length ? (
          stats.recent_applications.map((application) => (
            <div className="list-item" key={application.id}>
              <div>
                <strong>{fullName(application.student)}</strong>

                <p className="muted">{application.scholarship?.name}</p>
              </div>

              <StatusBadge status={application.status} />
            </div>
          ))
        ) : (
          <EmptyState message="No applications yet." />
        )}
      </section>
    </div>
  );
}
