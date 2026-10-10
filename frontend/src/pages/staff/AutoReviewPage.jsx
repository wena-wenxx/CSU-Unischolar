import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { formatDate } from "../../lib/format";
import { useToast } from "../../lib/toast";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import SheetTabs from "../../components/SheetTabs";
import { programTabs } from "../../lib/programs";

/*
  Auto-Review (staff). Sorts every submitted / under-review application:
    Ready to forward      complete, AI-checked with no flag, nothing expired
    Needs manual review   AI flag, unreadable or unchecked file, expired document
    Probably incomplete   missing required document, or already holds a scholarship
  It changes nothing by itself. Staff tick and press Forward / Send back.
  The agency still decides; its answer is recorded in Approved Lists.
*/
const GROUPS = [
  ["ready", "Ready to forward", "success"],
  ["manual", "Needs manual review", "warning"],
  ["incomplete", "Probably incomplete", "danger"],
];

export default function AutoReviewPage() {
  const toast = useToast();
  const confirm = useConfirm();

  const [programId, setProgramId] = useState("all");
  const [result, setResult] = useState(null);
  const [tab, setTab] = useState("ready");
  const [picked, setPicked] = useState([]);
  const [remarks, setRemarks] = useState({}); // id -> edited remark
  const [busy, setBusy] = useState(false);

  // .then() (not await) so React's lint rule sees the state is set later.
  const run = useCallback(
    () =>
      api
        .post("/staff/auto-review", {})
        .then(({ data }) => {
          setResult(data);
          setPicked([]);
        })
        .catch((err) => {
          toast.error(errMsg(err, "Auto-Review could not run."));
          setResult({ summary: { ready: 0, manual: 0, incomplete: 0 }, rows: [] });
        }),
    [toast]
  );

  useEffect(() => {
    run();
  }, [run]);

  async function rerun() {
    setBusy(true);
    await run();
    setBusy(false);
  }

  async function forward(ids) {
    const ok = await confirm({
      title: `Forward ${ids.length} application${ids.length === 1 ? "" : "s"} to the agency?`,
      message: "They are marked Complete (forwarded). The agency decides; record its answer later in Approved Lists.",
      confirmLabel: "Forward",
    });
    if (!ok) return;

    setBusy(true);
    try {
      const { data } = await api.post("/staff/applications/forward", { ids });
      toast.success(data.message);
      (data.errors || []).forEach((message) => toast.error(message));
      await run();
    } catch (err) {
      toast.error(errMsg(err, "Unable to forward."));
    } finally {
      setBusy(false);
    }
  }

  async function sendBack(rows) {
    const items = rows.map((row) => ({ id: row.id, remarks: (remarks[row.id] ?? row.suggested_remarks ?? "").trim() }));
    if (items.some((item) => !item.remarks)) {
      toast.error("Every application needs a remark telling the student what to fix.");
      return;
    }

    const ok = await confirm({
      title: `Send ${items.length} back to the student${items.length === 1 ? "" : "s"}?`,
      message: "They become Needs action. Each student sees your remark and gets a notification.",
      confirmLabel: "Send back",
    });
    if (!ok) return;

    setBusy(true);
    try {
      const { data } = await api.post("/staff/applications/needs-action", { items });
      toast.success(data.message);
      await run();
    } catch (err) {
      toast.error(errMsg(err, "Unable to send back."));
    } finally {
      setBusy(false);
    }
  }

  if (!result) return <Loading />;

  // Program tabs (like sheets in Excel); each list is A to Z by last name.
  const tabs = programTabs(result.rows, (row) => ({ id: row.scholarship_id, name: row.scholarship, short_name: row.short_name }));
  const activeProgram = tabs.some((t) => t.key === programId) ? programId : "all";
  const inProgram = result.rows.filter((row) => activeProgram === "all" || String(row.scholarship_id) === activeProgram);
  const groupCount = (key) => inProgram.filter((row) => row.group === key).length;
  const shown = inProgram
    .filter((row) => row.group === tab)
    .sort((a, b) => String(a.student).localeCompare(String(b.student)));
  const pickedRows = shown.filter((row) => picked.includes(row.id));
  const allPicked = shown.length > 0 && pickedRows.length === shown.length;

  return (
    <div>
      <PageHeader
        back={{ to: "/staff/applications", label: "Applications" }}
        title="Auto-Review"
        subtitle="Sorts submitted applications so you can forward the complete ones in one step. It only suggests; you decide."
      />

      <section className="card sheet-card">
        <SheetTabs
          tabs={tabs}
          value={activeProgram}
          onChange={(key) => {
            setProgramId(key);
            setPicked([]);
          }}
        />

        <div className="sheet-toolbar">
          <span className="sheet-meta muted">
            {inProgram.length} application{inProgram.length === 1 ? "" : "s"} waiting for review · A–Z
          </span>
          <button className="button button-small button-secondary" onClick={() => rerun()} disabled={busy}>
            {busy ? "Checking..." : "Run again"}
          </button>
        </div>

        <div className="auto-groups">
          {GROUPS.map(([key, label, tone]) => (
            <button
              key={key}
              className={`auto-group tone-${tone}${tab === key ? " active" : ""}`}
              aria-pressed={tab === key}
              onClick={() => {
                setTab(key);
                setPicked([]);
              }}
            >
              <strong>{groupCount(key)}</strong>
              <span>{label}</span>
            </button>
          ))}
        </div>

        {shown.length === 0 ? (
          <EmptyState message="Nothing in this list." />
        ) : (
          <>
            <div className="bulk-bar">
              <span>
                <strong>{pickedRows.length}</strong> of {shown.length} selected
              </span>
              <div className="button-row">
                {tab === "ready" && (
                  <button
                    className="button button-small button-primary"
                    disabled={busy || !pickedRows.length}
                    onClick={() => forward(pickedRows.map((row) => row.id))}
                  >
                    Forward selected to the agency
                  </button>
                )}
                {tab === "incomplete" && (
                  <button
                    className="button button-small button-warning"
                    disabled={busy || !pickedRows.length}
                    onClick={() => sendBack(pickedRows)}
                  >
                    Send selected back (Needs action)
                  </button>
                )}
                {tab === "manual" && (
                  <span className="muted small">Open each one in Applications → Review, then decide.</span>
                )}
              </div>
            </div>

            <div className="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>
                      <label className="sr-only" htmlFor="ar-all">
                        Select all
                      </label>
                      <input
                        id="ar-all"
                        type="checkbox"
                        checked={allPicked}
                        onChange={() => setPicked(allPicked ? [] : shown.map((row) => row.id))}
                      />
                    </th>
                    <th>Student</th>
                    {activeProgram === "all" && <th>Program</th>}
                    <th>Submitted</th>
                    <th>{tab === "ready" ? "Check" : "Why"}</th>
                    {tab === "incomplete" && <th>Remark to the student</th>}
                  </tr>
                </thead>
                <tbody>
                  {shown.map((row) => (
                    <tr key={row.id} className={picked.includes(row.id) ? "row-selected" : ""}>
                      <td>
                        <label className="sr-only" htmlFor={`ar-${row.id}`}>
                          Select {row.student}
                        </label>
                        <input
                          id={`ar-${row.id}`}
                          type="checkbox"
                          checked={picked.includes(row.id)}
                          onChange={() =>
                            setPicked((current) =>
                              current.includes(row.id) ? current.filter((x) => x !== row.id) : [...current, row.id]
                            )
                          }
                        />
                      </td>
                      <td>
                        <strong>{row.student}</strong>
                        <small className="muted account-note">{row.student_id}</small>
                      </td>
                      {activeProgram === "all" && <td title={row.scholarship}>{row.short_name || row.scholarship}</td>}
                      <td>{formatDate(row.submitted_at)}</td>
                      <td>
                        {row.reasons.length ? (
                          <ul className="reason-list">
                            {row.reasons.map((reason) => (
                              <li key={reason}>{reason}</li>
                            ))}
                          </ul>
                        ) : (
                          <span className="text-success-soft">✓ All required documents uploaded and checked</span>
                        )}
                        <Link className="link-button small" to={`/staff/applications?review=${row.id}`}>
                          Open review
                        </Link>
                      </td>
                      {tab === "incomplete" && (
                        <td>
                          <label className="sr-only" htmlFor={`ar-remark-${row.id}`}>
                            Remark for {row.student}
                          </label>
                          <textarea
                            id={`ar-remark-${row.id}`}
                            className="remark-box"
                            rows={3}
                            value={remarks[row.id] ?? row.suggested_remarks ?? ""}
                            onChange={(event) => setRemarks((current) => ({ ...current, [row.id]: event.target.value }))}
                          />
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}

        <p className="muted small">
          Forwarded applications appear in <Link to="/staff/forwarded">Forwarded to Agency</Link>. The AI only points
          things out; OAS staff and the agency decide.
        </p>
      </section>
    </div>
  );
}
