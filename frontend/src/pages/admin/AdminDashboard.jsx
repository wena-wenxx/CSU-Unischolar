import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { timeAgo } from "../../lib/format";
import { useToast } from "../../lib/toast";
import DashboardHero from "../../components/DashboardHero";
import QuickActions from "../../components/QuickActions";
import StatCard from "../../components/StatCard";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import { actionLabel } from "../../lib/activity";

/* System Admin: accounts at a glance, the current term and the latest activity. */
export default function AdminDashboard() {
  const toast = useToast();
  const [data, setData] = useState(null);

  // .then() (not await) so React's lint rule sees the state is set later.
  const load = useCallback(
    () =>
      api
        .get("/admin/dashboard")
        .then((response) => setData(response.data))
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load the dashboard."));
          setData({ accounts: {}, recent: [], settings: {} });
        }),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  if (!data) return <Loading />;

  const a = data.accounts || {};
  const missing = ["oas_email", "oas_phone"].filter((key) => !data.settings?.[key]);

  return (
    <div>
      <DashboardHero
        title="System Admin Dashboard"
        subtitle="Accounts, activity and settings. OAS staff handle applications, grantees and payroll."
      />

      {missing.length > 0 && (
        <div className="alert alert-warning">
          The OAS {missing.map((key) => (key === "oas_email" ? "e-mail" : "phone number")).join(" and ")}{" "}
          {missing.length === 1 ? "is" : "are"} not set yet, so students do not see {missing.length === 1 ? "it" : "them"} on
          Contact OAS. <Link to="/admin/settings">Open System Settings</Link>
        </div>
      )}

      <div className="stats-grid">
        <StatCard title="OAS Staff" value={a.staff ?? 0} to="/admin/staff" hint="Active accounts" />
        <StatCard title="Admins" value={a.admins ?? 0} to="/admin/staff" hint="Active accounts" />
        <StatCard title="Students" value={a.students ?? 0} to="/admin/students" hint="Active accounts" />
        <StatCard title="Deactivated" value={a.inactive ?? 0} to="/admin/staff?status=inactive" hint="Cannot log in" />
        <StatCard title="Temporary Passwords" value={a.must_change_password ?? 0} to="/admin/staff" hint="Not yet changed" />
        <StatCard title="Logins Today" value={data.logins_today ?? 0} to="/admin/activity?action=auth" />
        <StatCard title="Actions (7 days)" value={data.actions_7_days ?? 0} to="/admin/activity" />
        <StatCard title="Current Term" value={data.term || "—"} to="/admin/settings" hint="Change" />
      </div>

      <QuickActions
        actions={[
          { to: "/admin/staff?new=1", icon: "👤", label: "New Staff Account", text: "Create an OAS staff or admin login" },
          { to: "/admin/students?new=1", icon: "🎓", label: "New Student Account", text: "Create a student login" },
          { to: "/admin/activity", icon: "🕘", label: "Activity Logs", text: "Who did what and when" },
          { to: "/admin/settings", icon: "⚙️", label: "System Settings", text: "School year, semester, OAS contact" },
        ]}
      />

      <section className="card">
        <div className="card-header">
          <h2>Latest activity</h2>
          <Link className="button button-small button-secondary" to="/admin/activity">
            All activity
          </Link>
        </div>

        {data.recent?.length ? (
          data.recent.map((row) => (
            <div className="list-item" key={row.id}>
              <div>
                <strong>{row.user_name || "System"}</strong>
                <p className="muted">{row.description}</p>
              </div>
              <div className="activity-meta">
                <span className="status status-neutral">{actionLabel(row.action)}</span>
                <small className="muted">{timeAgo(row.created_at)}</small>
              </div>
            </div>
          ))
        ) : (
          <EmptyState message="No activity yet." />
        )}
      </section>
    </div>
  );
}
