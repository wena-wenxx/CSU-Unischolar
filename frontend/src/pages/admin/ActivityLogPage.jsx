import { useEffect, useState } from "react";
import { useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { ROLE_LABELS } from "../../lib/auth";
import { ACTION_GROUPS, actionLabel } from "../../lib/activity";
import { downloadCSV, formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";

/* Who did what and when. Newest first, 50 at a time, with filters and CSV export. */
const stamp = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });

const TONE = {
  auth: "neutral",
  account: "warning",
  settings: "warning",
  application: "success",
  enrollment: "success",
  grantee: "success",
  payroll: "success",
};

export default function ActivityLogPage() {
  const toast = useToast();
  const location = useLocation();

  const [filters, setFilters] = useState(() => {
    const wanted = new URLSearchParams(location.search).get("action") || "";
    return { q: "", action: wanted in ACTION_GROUPS ? wanted : "", role: "", from: "", to: "" };
  });
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(false);

  const clean = (f) => Object.fromEntries(Object.entries(f).filter(([, value]) => value));

  function fetchPage(nextPage, f = filters) {
    return api
      .get("/admin/activity", { params: { ...clean(f), page: nextPage } })
      .then(({ data }) => {
        setRows((current) => (nextPage === 1 ? data.data : [...current, ...data.data]));
        setTotal(data.total);
        setPage(data.page);
        setHasMore(data.has_more);
      })
      .catch((err) => toast.error(errMsg(err, "Unable to load the activity log.")));
  }

  useEffect(() => {
    fetchPage(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function change(key, value) {
    const next = { ...filters, [key]: value };
    setFilters(next);
    if (key === "q") return; // text: wait for Search / Enter
    setLoading(true);
    await fetchPage(1, next);
    setLoading(false);
  }

  async function search(event) {
    event.preventDefault();
    setLoading(true);
    await fetchPage(1);
    setLoading(false);
  }

  async function more() {
    setLoading(true);
    await fetchPage(page + 1);
    setLoading(false);
  }

  async function exportCSV() {
    try {
      const { data } = await api.get("/admin/activity", { params: { ...clean(filters), export: 1 } });
      const ok = downloadCSV(
        `activity-log-${stamp()}.csv`,
        data.data.map((row) => ({
          When: formatDateTime(row.created_at),
          Who: row.user_name || "System",
          Role: ROLE_LABELS[row.role] || "",
          Type: actionLabel(row.action),
          Code: row.action,
          What: row.description,
          "IP address": row.ip_address || "",
        }))
      );
      if (ok) toast.success(`Exported ${data.data.length} entries.`);
      else toast.info("Nothing to export.");
    } catch (err) {
      toast.error(errMsg(err, "Export failed."));
    }
  }

  return (
    <div>
      <PageHeader
        title="Activity Logs"
        subtitle="Who did what and when: logins, accounts, applications, payroll and settings"
        actions={
          <button className="button button-secondary" onClick={exportCSV} disabled={!total}>
            Export CSV ({total})
          </button>
        }
      />

      <section className="card">
        <form className="inline-form table-search" onSubmit={search}>
          <label htmlFor="log-search" className="sr-only">
            Search
          </label>
          <input
            id="log-search"
            type="search"
            value={filters.q}
            onChange={(event) => change("q", event.target.value)}
            placeholder="Search a name or words (e.g. Contiga, payroll)"
          />
          <button className="button button-primary" disabled={loading}>
            Search
          </button>
        </form>

        <div className="inline-form bank-filters">
          <label htmlFor="log-action" className="sr-only">
            Type
          </label>
          <select id="log-action" value={filters.action} onChange={(event) => change("action", event.target.value)}>
            {Object.entries(ACTION_GROUPS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>

          <label htmlFor="log-role" className="sr-only">
            Role
          </label>
          <select id="log-role" value={filters.role} onChange={(event) => change("role", event.target.value)}>
            <option value="">Everyone</option>
            <option value="staff">OAS Staff</option>
            <option value="admin">System Admin</option>
            <option value="student">Students</option>
          </select>

          <label htmlFor="log-from">From</label>
          <input id="log-from" type="date" value={filters.from} onChange={(event) => change("from", event.target.value)} />
          <label htmlFor="log-to">To</label>
          <input id="log-to" type="date" value={filters.to} onChange={(event) => change("to", event.target.value)} />
        </div>

        <p className="muted small">
          Showing {rows.length} of {total} entr{total === 1 ? "y" : "ies"}, newest first.
        </p>

        {rows.length === 0 ? (
          <EmptyState message="No activity matches." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>When</th>
                  <th>Who</th>
                  <th>Type</th>
                  <th>What happened</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="nowrap">{formatDateTime(row.created_at)}</td>
                    <td>
                      {row.user_name || "System"}
                      {row.role && <small className="muted account-note">{ROLE_LABELS[row.role]}</small>}
                    </td>
                    <td>
                      <span className={`status status-${TONE[String(row.action).split(".")[0]] || "neutral"}`}>
                        {actionLabel(row.action)}
                      </span>
                    </td>
                    <td>{row.description}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {hasMore && (
          <div className="show-more">
            <button className="button button-secondary" onClick={more} disabled={loading}>
              {loading ? "Loading..." : `Show more (${total - rows.length} left)`}
            </button>
          </div>
        )}
      </section>
    </div>
  );
}
