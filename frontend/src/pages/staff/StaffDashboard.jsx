import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { formatDate, fullName, stepLabel, timeAgo } from "../../lib/format";
import { useToast } from "../../components/Toast";
import Modal from "../../components/Modal";
import PageHeader from "../../components/PageHeader";
import StatCard from "../../components/StatCard";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const FIELD_LABELS = {
  first_name: "First name",
  middle_name: "Middle name",
  last_name: "Last name",
  student_id: "Student ID",
  course: "Course",
  year_level: "Year level",
  college: "College",
};

export default function StaffDashboard() {
  const toast = useToast();

  const [stats, setStats] = useState(null);
  const [requests, setRequests] = useState([]);
  const [resolving, setResolving] = useState(null);

  const load = useCallback(() => {
    Promise.all([api.get("/staff/dashboard"), api.get("/staff/profile-requests", { params: { status: "pending" } })])
      .then(([dashboard, pending]) => {
        setStats(dashboard.data);
        setRequests(pending.data);
      })
      .catch((err) => {
        toast.error(errMsg(err, "Unable to load the dashboard."));
        setStats({});
      });
  }, [toast]);

  useEffect(() => {
    load();
  }, [load]);

  if (!stats) return <Loading />;

  return (
    <div>
      <PageHeader back={false} title="OAS Staff Dashboard" subtitle="Scholarship management overview" />

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
        <Link className="button button-secondary" to="/staff/agency-lists">
          Upload Agency List
        </Link>
        <Link className="button button-secondary" to="/staff/reports">
          Reports
        </Link>
      </div>

      <div className="dashboard-grid">
        <section className="card dashboard-recent">
          <div className="card-header">
            <h2>Recent activity</h2>
            <Link className="button button-small button-secondary" to="/staff/applications">
              All applications
            </Link>
          </div>

          {stats.recent_applications?.length ? (
            stats.recent_applications.map((application) => (
              <div className="list-item" key={application.id}>
                <div>
                  <strong>{fullName(application.student)}</strong>

                  <p className="muted">
                    {application.scholarship?.name}
                    <br />
                    {application.latest_log ? stepLabel(application.latest_log.to_status) : "Updated"}{" "}
                    {timeAgo(application.updated_at)}
                  </p>
                </div>

                <StatusBadge status={application.status} />
              </div>
            ))
          ) : (
            <EmptyState message="No activity yet." />
          )}
        </section>

        <section className="card">
          <div className="card-header">
            <h2>Profile correction requests</h2>
            <span className="muted">{requests.length} pending</span>
          </div>

          {requests.length === 0 ? (
            <EmptyState message="No pending requests." />
          ) : (
            requests.map((item) => (
              <div className="list-item" key={item.id}>
                <div>
                  <strong>
                    {fullName(item.student)} ({item.student?.student_id})
                  </strong>
                  <p className="muted">
                    {FIELD_LABELS[item.field] || item.field}: “{item.student?.[item.field] || "—"}” → “
                    {item.requested_value}”
                    <br />
                    {item.reason ? `${item.reason} · ` : ""}sent {formatDate(item.created_at)}
                  </p>
                </div>

                <button className="button button-small button-secondary" onClick={() => setResolving(item)}>
                  Mark resolved
                </button>
              </div>
            ))
          )}
        </section>
      </div>

      {resolving && (
        <ResolveModal
          request={resolving}
          onClose={() => setResolving(null)}
          onDone={() => {
            setResolving(null);
            load();
          }}
        />
      )}
    </div>
  );
}

function ResolveModal({ request, onClose, onDone }) {
  const toast = useToast();
  const [remarks, setRemarks] = useState("");
  const [apply, setApply] = useState(false);
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);

    try {
      const { data } = await api.patch(`/staff/profile-requests/${request.id}`, {
        staff_remarks: remarks.trim() || null,
        apply_change: apply,
      });
      toast.success(data.message);
      onDone();
    } catch (err) {
      toast.error(errMsg(err, "Unable to update the request."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Resolve correction request"
      onClose={onClose}
      footer={
        <>
          <button className="button button-secondary" onClick={onClose}>
            Cancel
          </button>
          <button className="button button-primary" onClick={save} disabled={saving}>
            {saving ? "Saving..." : "Mark resolved"}
          </button>
        </>
      }
    >
      <p>
        Check this with the Registrar's records first. The student asked to change{" "}
        <strong>{FIELD_LABELS[request.field] || request.field}</strong> from “{request.student?.[request.field] || "—"}” to “
        {request.requested_value}”.
      </p>

      <label className="choice">
        <input type="checkbox" checked={apply} onChange={(event) => setApply(event.target.checked)} />
        The Registrar confirmed it: apply this change to the student's record
      </label>

      <label htmlFor="resolve-remarks">Remarks for the student (optional)</label>
      <textarea
        id="resolve-remarks"
        value={remarks}
        onChange={(event) => setRemarks(event.target.value)}
        placeholder="e.g. Confirmed with the Registrar; your year level will be updated."
      />
    </Modal>
  );
}
