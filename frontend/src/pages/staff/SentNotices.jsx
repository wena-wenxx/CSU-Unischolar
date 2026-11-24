import { useEffect, useState } from "react";
import api, { errMsg } from "../../services/api";
import { formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

/* Sent invitations and scholarship notices: whom the OAS invited, for which program, when, and where
   each one stands now. Read-only: invitations are sent from AI Recommendations. */
const TABS = [
  ["all", "All"],
  ["sent", "Sent, not opened"],
  ["read", "Opened"],
  ["applied", "Applied"],
  ["closed", "Program closed"],
];
const LABEL = { sent: "Sent, not opened", read: "Opened", applied: "Applied", closed: "Program closed" };

export default function SentNotices() {
  const toast = useToast();
  const [status, setStatus] = useState("all");
  const [query, setQuery] = useState("");
  const [data, setData] = useState(null);
  const [open, setOpen] = useState(null);

  function load(nextStatus = status, q = query) {
    return api
      .get("/staff/notices", { params: { status: nextStatus, q: q || undefined } })
      .then((response) => setData(response.data))
      .catch((err) => {
        toast.error(errMsg(err, "Unable to load the sent invitations."));
        setData({ counts: {}, notices: [] });
      });
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!data) return <Loading />;
  const counts = data.counts || {};

  return (
    <section className="card">
      <p className="muted small">
        Invitations and notices sent from AI Recommendations. Each one is saved in the system and shows under the student&apos;s bell.
      </p>
      <div className="filter-row" role="group" aria-label="Show invitations">
        {TABS.map(([key, label]) => (
          <button
            key={key}
            className={status === key ? "button button-small button-primary" : "button button-small button-secondary"}
            aria-pressed={status === key}
            onClick={() => {
              setStatus(key);
              load(key);
            }}
          >
            {label} ({counts[key] ?? 0})
          </button>
        ))}
      </div>

      <form
        className="inline-form table-search"
        onSubmit={(event) => {
          event.preventDefault();
          load();
        }}
      >
        <label htmlFor="notice-search" className="sr-only">
          Search invitations
        </label>
        <input
          id="notice-search"
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search a student, Student ID or program"
        />
        <button className="button button-primary">Search</button>
      </form>

      {data.notices.length === 0 ? (
        <EmptyState message="No invitations here." />
      ) : (
        <div className="table-wrap">
          <table className="table numbered" id="sent-notices-table">
            <thead>
              <tr>
                <th>Student</th>
                <th>Program</th>
                <th>Type</th>
                <th>Sent</th>
                <th>By</th>
                <th>Status</th>
                <th>Message</th>
              </tr>
            </thead>
            <tbody>
              {data.notices.map((n) => (
                <tr key={n.id}>
                  <td>
                    <strong className="student-name">{n.student}</strong>
                    <br />
                    <small className="muted">{n.student_number}</small>
                  </td>
                  <td>{n.scholarship}</td>
                  <td>{n.kind}</td>
                  <td>{formatDateTime(n.sent_at)}</td>
                  <td>{n.sender || "OAS"}</td>
                  <td>
                    {LABEL[n.status] || n.status}
                    {n.status === "read" && n.read_at ? <small className="muted"> · {formatDateTime(n.read_at)}</small> : null}
                  </td>
                  <td>
                    <button className="link-button" onClick={() => setOpen(open === n.id ? null : n.id)}>
                      {open === n.id ? "Hide" : "View"}
                    </button>
                    {open === n.id && <p className="message-body small">{n.message}</p>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
