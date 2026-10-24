import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { downloadCSV, formatDateTime } from "../../lib/format";
import {
  CHECK_RESULT,
  FIT_LEVELS,
  SCORE_RANGES,
  fitMethod,
  STAFF_STATUS,
  VERDICTS,
  VIEWS,
  appliedLabel,
  gwaText,
  peso,
  studentLabel,
} from "../../lib/recommend";
import { programLabel } from "../../lib/programs";
import { useToast } from "../../lib/toast";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import Modal from "../../components/Modal";
import SheetTabs from "../../components/SheetTabs";

/*
  AI Recommendations (staff).
  For every student and scholarship, the rule engine checks the scholarship's
  eligibility rules and required documents and counts how many are met
  (equal weight). It only SUGGESTS: staff open "Why?" to see every check,
  then Send invitation, Add to scholarship (a draft application), Dismiss,
  or Mark as wrong. Every page shown and every decision is logged.
*/
const EMPTY = { view: "recommended", applied: "", range: "", q: "", sort: "score_desc", checked: false };
const stamp = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });

function Badge({ map, value }) {
  const [label, tone] = map[value] || [value, "neutral"];
  return <span className={`status status-${tone}`}>{label}</span>;
}

function params(filters, programId, extra = {}) {
  const [, min, max] = SCORE_RANGES[filters.range] || SCORE_RANGES[""];
  const out = { view: filters.view, sort: filters.sort, ...extra };
  if (filters.applied) out.applied = filters.applied;
  if (filters.checked) out.checked = 1;
  if (filters.q.trim()) out.q = filters.q.trim();
  if (min !== null) out.min = min;
  if (max !== null) out.max = max;
  if (programId !== "all") out.scholarship_id = programId;
  return out;
}

export default function RecommendationsPage() {
  const toast = useToast();

  const [filters, setFilters] = useState(EMPTY);
  const [programId, setProgramId] = useState("all");
  const [data, setData] = useState(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [busy, setBusy] = useState(false);
  const [openId, setOpenId] = useState(null);

  // .then() (not await) so React's lint rule sees the state is set later.
  const fetchRows = useCallback(
    (f, program, page = 1) =>
      api
        .get("/staff/recommendations", { params: params(f, program, { page }) })
        .then(({ data: result }) =>
          setData((current) => ({
            ...result,
            data: page === 1 || !current ? result.data : [...current.data, ...result.data],
          }))
        )
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load the recommendations."));
          setData((current) => current || { data: [], total: 0, tabs: [], all_count: 0, unscored: [] });
        }),
    [toast]
  );

  useEffect(() => {
    fetchRows(EMPTY, "all");
  }, [fetchRows]);

  function change(key, value) {
    const next = { ...filters, [key]: value };
    setFilters(next);
    fetchRows(next, programId);
  }

  function changeProgram(key) {
    setProgramId(key);
    fetchRows(filters, key);
  }

  async function showMore() {
    setLoadingMore(true);
    await fetchRows(filters, programId, (data?.page || 1) + 1);
    setLoadingMore(false);
  }

  async function recalculate() {
    setBusy(true);
    try {
      const { data: result } = await api.post("/staff/recommendations/recalculate");
      toast.success(result.message);
      await fetchRows(filters, programId);
    } catch (err) {
      toast.error(errMsg(err, "Unable to recalculate."));
    } finally {
      setBusy(false);
    }
  }

  async function exportCSV() {
    setBusy(true);
    try {
      const { data: result } = await api.get("/staff/recommendations", { params: params(filters, programId, { export: 1 }) });
      const names = (checks, result_) => checks.filter((c) => c.result === result_).map((c) => c.label).join("; ");
      const ok = downloadCSV(
        `ai-recommendations-${filters.view}-${stamp()}.csv`,
        result.data.map((r) => ({
          "Student ID": r.student?.student_id,
          "Last name": r.student?.last_name,
          "First name": r.student?.first_name,
          Course: r.student?.course || "",
          "Year level": r.student?.year_level || "",
          Scholarship: r.scholarship?.name,
          "Score (%)": r.score,
          "Checks met": r.met_count,
          "All checks": r.total_count,
          Verdict: (VERDICTS[r.verdict] || [r.verdict])[0],
          Met: names(r.checks, "met"),
          "Not met": names(r.checks, "not_met"),
          "No data": names(r.checks, "no_data"),
          Applied: appliedLabel(r.applied_status),
          "Staff decision": (STAFF_STATUS[r.staff_status] || [r.staff_status])[0],
          "GWA / income verified": r.data_verified ? "Yes" : "No",
          Calculation: r.calculation,
        }))
      );
      if (!ok) toast.info("Nothing to export.");
    } catch (err) {
      toast.error(errMsg(err, "Export failed."));
    } finally {
      setBusy(false);
    }
  }

  // A decision in the window changes one row; update it without reloading.
  function replaceRow(updated) {
    setData((current) => ({
      ...current,
      data: current.data.map((row) => (row.id === updated.id ? { ...row, ...updated } : row)),
    }));
  }

  if (!data) return <Loading />;

  const tabs = [
    { key: "all", label: "All programs", count: data.all_count },
    ...data.tabs.map((t) => ({ key: String(t.id), label: programLabel(t), title: t.name, count: t.count })),
  ];
  const sortIcon = filters.sort === "score_asc" ? "▲" : filters.sort === "score_desc" ? "▼" : "";

  return (
    <div>
      <PageHeader
        title="AI Recommendations"
        subtitle="Students the scholarship rules say may be eligible, even if they have not applied. The AI only suggests; you decide."
        actions={
          <div className="button-row">
            <button className="button button-secondary" onClick={recalculate} disabled={busy}>
              {busy ? "Working..." : "Recalculate"}
            </button>
            <button className="button button-secondary" onClick={exportCSV} disabled={busy || !data.total}>
              Export CSV ({data.total})
            </button>
          </div>
        }
      />

      <details className="card how-it-works">
        <summary>
          How the score works <span className="muted small">· last calculated {data.computed_at ? formatDateTime(data.computed_at) : "never"}</span>
        </summary>
        <ol className="how-list">
          <li>
            Each program&apos;s <strong>eligibility rules</strong> (GWA, family income, year level, college, one active
            scholarship) and <strong>required documents</strong> are checked for every student.
          </li>
          <li>
            Every check counts the same. <strong>Score = checks met ÷ all checks × 100.</strong> A document counts only after
            the AI check found no problem; a file not checked yet does not count.
          </li>
          <li>
            A must-have rule that is not met shows <strong>Fails a rule</strong>, even with a high score. A missing GWA or
            income shows <strong>Not enough data</strong>. <strong>Meets the rules</strong> with a low score means the student
            qualifies but has not uploaded the documents yet.
          </li>
          <li>Open “Why?” to see each check, the student&apos;s data used, and the result. Nothing changes until you act.</li>
        </ol>
        {data.unscored?.length > 0 && (
          <p className="muted small">
            Not scored (no rules or required documents yet): {data.unscored.join(", ")}. Add rules on the Scholarships page.
          </p>
        )}
      </details>

      <section className="card sheet-card">
        <SheetTabs tabs={tabs} value={programId} onChange={changeProgram} />

        <div className="sheet-toolbar">
          <form
            className="toolbar-search"
            onSubmit={(event) => {
              event.preventDefault();
              fetchRows(filters, programId);
            }}
          >
            <label htmlFor="rec-search" className="sr-only">
              Search
            </label>
            <input
              id="rec-search"
              type="search"
              value={filters.q}
              onChange={(event) => setFilters({ ...filters, q: event.target.value })}
              placeholder="Name or Student ID, then Enter"
            />
          </form>

          <label htmlFor="rec-view" className="sr-only">
            Show
          </label>
          <select id="rec-view" value={filters.view} onChange={(event) => change("view", event.target.value)}>
            {Object.entries(VIEWS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>

          <label htmlFor="rec-applied" className="sr-only">
            Applied or not
          </label>
          <select id="rec-applied" value={filters.applied} onChange={(event) => change("applied", event.target.value)}>
            <option value="">Applied or not</option>
            <option value="no">Not applied yet</option>
            <option value="yes">Already applied</option>
          </select>

          <label htmlFor="rec-range" className="sr-only">
            Score
          </label>
          <select id="rec-range" value={filters.range} onChange={(event) => change("range", event.target.value)}>
            {Object.entries(SCORE_RANGES).map(([key, [label]]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>

          <label className="choice toolbar-check">
            <input type="checkbox" checked={filters.checked} onChange={(event) => change("checked", event.target.checked)} />
            Only GWA/income checked by OAS
          </label>

          <span className="sheet-meta muted">
            {data.total} student{data.total === 1 ? "" : "s"} ·{" "}
            {filters.sort === "name" ? "A–Z" : filters.sort === "score_asc" ? "lowest score first" : "highest score first"}
          </span>
        </div>

        {data.data.length === 0 ? (
          <EmptyState message="No students in this list. Try another program tab or “Show”." />
        ) : (
          <div className="table-wrapper">
            <table className="rec-table">
              <thead>
                <tr>
                  <th>
                    <button type="button" className="th-sort" onClick={() => change("sort", "name")}>
                      Student {filters.sort === "name" ? "▲" : ""}
                    </button>
                  </th>
                  {programId === "all" && <th>Program</th>}
                  <th>
                    <button
                      type="button"
                      className="th-sort"
                      onClick={() => change("sort", filters.sort === "score_desc" ? "score_asc" : "score_desc")}
                      title="Sort by score"
                    >
                      Score {sortIcon}
                    </button>
                  </th>
                  <th>Met</th>
                  <th>Not met</th>
                  <th>Applied</th>
                  <th>Decision</th>
                  <th>
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((row) => {
                  const met = row.checks.filter((c) => c.result === "met");
                  const missed = row.checks.filter((c) => c.result !== "met");
                  return (
                    <tr key={row.id}>
                      <td>
                        <strong>{studentLabel(row.student)}</strong>
                        <small className="muted account-note">
                          {row.student?.student_id} · {row.student?.year_level || "—"}
                        </small>
                        {!row.data_verified && (
                          <small className="unchecked-tag" title="The GWA and income were typed by the student and not yet checked by OAS">
                            GWA/income not checked
                          </small>
                        )}
                      </td>
                      {programId === "all" && <td title={row.scholarship?.name}>{programLabel(row.scholarship)}</td>}
                      <td className="nowrap">
                        <div className="score">
                          <strong>{row.score}%</strong>
                          <span className="score-bar" aria-hidden="true">
                            <span style={{ width: `${row.score}%` }} className={`tone-${(VERDICTS[row.verdict] || [])[1]}`} />
                          </span>
                          <small className="muted">
                            {row.met_count} of {row.total_count}
                          </small>
                        </div>
                        {row.verdict !== "eligible" && <Badge map={VERDICTS} value={row.verdict} />}
                        {row.fit?.level === "high" && (
                          <small className="fit-tag" title="AI field fit: this program is for the student's field of study">
                            Field: {row.fit.fields.join(", ")}
                          </small>
                        )}
                      </td>
                      <td className="check-cell" title={met.map((c) => c.label).join(", ")}>
                        <span className="text-success-soft">
                          ✓ {met.slice(0, 3).map((c) => c.label).join(", ")}
                          {met.length > 3 ? ` +${met.length - 3}` : ""}
                          {met.length === 0 ? "None" : ""}
                        </span>
                      </td>
                      <td className="check-cell">
                        {missed.length === 0 ? (
                          <span className="muted">—</span>
                        ) : (
                          <span className={missed.some((c) => c.must) ? "text-danger" : "text-warning"}>
                            ✗ {missed.map((c) => c.label).join(", ")}
                          </span>
                        )}
                      </td>
                      <td>{row.applied_status ? appliedLabel(row.applied_status) : <span className="muted">Not yet</span>}</td>
                      <td>
                        <Badge map={STAFF_STATUS} value={row.staff_status} />
                      </td>
                      <td>
                        <div className="row-actions">
                          <button className="button button-small button-primary" onClick={() => setOpenId(row.id)}>
                            Why?
                          </button>
                          <Link className="button button-small button-secondary" to={`/staff/data-bank?student=${row.student?.id}`}>
                            Review
                          </Link>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {data.has_more && (
          <div className="show-more">
            <button className="button button-secondary" onClick={showMore} disabled={loadingMore}>
              {loadingMore ? "Loading..." : `Show more (${data.total - data.data.length} left)`}
            </button>
          </div>
        )}

        <p className="muted small">
          Recommendations never approve anyone. “Add to scholarship” only starts a draft application; OAS still reviews it
          and the agency decides.
        </p>
      </section>

      {openId && <RecommendationWindow id={openId} onClose={() => setOpenId(null)} onChanged={replaceRow} />}
    </div>
  );
}

/* "Why?": every check, the student's data, the decision buttons and the history. */
function RecommendationWindow({ id: firstId, onClose, onChanged }) {
  // "Other programs" can switch the window to another program for the same student.
  const [id, setId] = useState(firstId);
  const toast = useToast();
  const confirm = useConfirm();

  const [rec, setRec] = useState(null);
  const [failed, setFailed] = useState(false);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(
    () =>
      api
        .get(`/staff/recommendations/${id}`)
        .then(({ data }) => setRec(data))
        .catch((err) => {
          toast.error(errMsg(err, "Unable to open this recommendation."));
          setFailed(true);
        }),
    [id, toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  async function act(action, confirmText) {
    if (confirmText && !(await confirm(confirmText))) return;

    setBusy(true);
    try {
      const { data } = await api.post(`/staff/recommendations/${id}/${action}`, { note: note.trim() || null });
      toast.success(data.message);
      if (data.recommendation) onChanged(data.recommendation);
      setNote("");
      await load();
    } catch (err) {
      toast.error(errMsg(err, "That did not work."));
    } finally {
      setBusy(false);
    }
  }

  async function verify(verified) {
    setBusy(true);
    try {
      const { data } = await api.post(`/staff/students/${rec.student.id}/eligibility`, { verified });
      toast.success(data.message);
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to change the verification."));
    } finally {
      setBusy(false);
    }
  }

  if (!rec) {
    return (
      <Modal size="large" title="AI recommendation" onClose={onClose}>
        {failed ? <p className="muted">This recommendation could not be opened. Close this window and try again.</p> : <Loading />}
      </Modal>
    );
  }

  const student = rec.student_data;
  const program = rec.scholarship;
  const againstAI = rec.verdict !== "eligible";
  const canAdd = !program.agency_direct && program.is_open && !rec.applied_status;
  const addBlock = program.agency_direct
    ? "Students apply for this program at the agency."
    : !program.is_open
      ? "Applications for this program are not open now."
      : rec.applied_status
        ? "The student already has an application for this program."
        : "";

  return (
    <Modal
      size="large"
      title={`${rec.student.first_name} ${rec.student.last_name}`}
      subtitle={
        <div className="rec-head">
          <span className="muted">
            {rec.student.student_id} · {program.name}
          </span>
          <span className="rec-head-badges">
            <strong className="rec-score">{rec.score}%</strong>
            <Badge map={VERDICTS} value={rec.verdict} />
            <Badge map={STAFF_STATUS} value={rec.staff_status} />
          </span>
        </div>
      }
      onClose={onClose}
    >
      {rec.next_steps && (
        <div className={`next-box tone-${rec.next_steps.tone}`}>
          <span className="field-label">What to do next</span>
          <strong>{rec.next_steps.title}</strong>
          <ol>
            {rec.next_steps.steps.map((step) => (
              <li key={step}>{step}</li>
            ))}
          </ol>
        </div>
      )}

      {rec.fit && (
        <div className="fit-box">
          <span className="field-label">AI field fit ({fitMethod(rec.fit.method)})</span>
          <span>
            <span className={`status status-${(FIT_LEVELS[rec.fit.level] || [])[1] || "neutral"}`}>{rec.fit.label}</span>
            {rec.fit.fields.length > 0 && <> · {rec.fit.fields.join(", ")}</>}
            {rec.fit.words.length > 0 && <span className="muted"> · shared words: {rec.fit.words.join(", ")}</span>}
          </span>
          {rec.field_matches?.filter((m) => m.agency_direct).length > 0 && (
            <small className="muted">
              Also for this student&apos;s field (apply at the agency):{" "}
              {rec.field_matches
                .filter((m) => m.agency_direct)
                .map((m) => m.program)
                .join(", ")}
            </small>
          )}
          <small className="muted">It only ranks and explains; the rules above decide eligibility.</small>
        </div>
      )}

      {rec.other_matches?.length > 0 && (
        <details className="other-matches">
          <summary>
            Other programs for this student ({rec.other_matches.filter((o) => o.verdict === "eligible").length} meet the
            rules)
          </summary>
          <ul>
            {rec.other_matches.map((other) => (
              <li key={other.id}>
                <button className="link-button" onClick={() => setId(other.id)}>
                  {other.program}
                </button>
                <strong>{other.score}%</strong>
                <Badge map={VERDICTS} value={other.verdict} />
                {other.staff_status !== "new" && <Badge map={STAFF_STATUS} value={other.staff_status} />}
                {other.applied_status && <span className="muted small">Applied: {appliedLabel(other.applied_status)}</span>}
              </li>
            ))}
          </ul>
        </details>
      )}

      <div className="calc-box">
        <span className="field-label">How the score was calculated</span>
        <strong>{rec.calculation}</strong>
        <small className="muted">
          Calculated {formatDateTime(rec.computed_at)} · shown to staff {rec.times_shown} time{rec.times_shown === 1 ? "" : "s"}
        </small>
      </div>

      <div className="table-wrapper">
        <table className="check-table">
          <thead>
            <tr>
              <th>Result</th>
              <th>Check</th>
              <th>Rule</th>
              <th>Student&apos;s data used</th>
            </tr>
          </thead>
          <tbody>
            {rec.checks.map((check) => {
              const [icon, label, tone] = CHECK_RESULT[check.result] || ["", check.result, "neutral"];
              return (
                <tr key={check.key}>
                  <td>
                    <span className={`status status-${tone}`}>
                      {icon} {label}
                    </span>
                  </td>
                  <td>
                    <strong>{check.label}</strong>
                    {check.must && <small className="must-tag">must-have</small>}
                  </td>
                  <td>{check.rule}</td>
                  <td>{check.value}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div className="data-strip">
        <span>
          <span className="field-label">GWA</span> {gwaText(student.gwa)}
        </span>
        <span>
          <span className="field-label">Family income</span> {peso(student.family_income)}
        </span>
        <span>
          <span className="field-label">Year level</span> {student.year_level || "—"}
        </span>
        <span>
          {student.verified ? (
            <span className="status status-success">✓ Checked by OAS</span>
          ) : (
            <span className="status status-warning">Typed by the student, not checked</span>
          )}
        </span>
        {student.gwa !== null && student.family_income !== null && (
          <button className="link-button small" onClick={() => verify(!student.verified)} disabled={busy}>
            {student.verified ? "Undo check" : "Mark GWA and income as checked"}
          </button>
        )}
      </div>
      <p className="muted small">
        Check the GWA against the Certificate of Grades and the income against the Parents&apos; ITR before marking them as
        checked.
      </p>

      <h3>Your decision</h3>
      <label htmlFor="rec-note">
        Note {againstAI ? "(required: the AI did not mark this student eligible)" : "(optional, sent with the invitation)"}
      </label>
      <textarea
        id="rec-note"
        rows={2}
        maxLength={1000}
        value={note}
        onChange={(event) => setNote(event.target.value)}
        placeholder={againstAI ? "Why you are going against the AI" : "e.g. Please apply before the deadline."}
      />

      <div className="button-row decision-buttons">
        <button
          className="button button-primary"
          disabled={busy || (rec.applied_status && rec.applied_status !== "draft")}
          onClick={() => act("invite")}
        >
          Send invitation
        </button>
        <button
          className="button button-success"
          disabled={busy || !canAdd}
          title={addBlock}
          onClick={() =>
            act("add", {
              title: "Add this student to the scholarship?",
              message: `A DRAFT application for ${program.name} will be started for ${rec.student.first_name} ${rec.student.last_name}, and they get an e-mail. They still attach documents and submit; OAS reviews it and the agency decides.`,
              confirmLabel: "Start draft application",
            })
          }
        >
          Add to scholarship
        </button>
        <button className="button button-secondary" disabled={busy || rec.staff_status === "dismissed"} onClick={() => act("dismiss")}>
          Dismiss
        </button>
        <button
          className="button button-danger"
          disabled={busy || rec.staff_status === "wrong"}
          onClick={() => {
            if (note.trim().length < 5) {
              toast.error("Write what the AI got wrong in the note first.");
              document.getElementById("rec-note")?.focus();
              return;
            }
            act("wrong");
          }}
        >
          Mark as wrong
        </button>
        {rec.staff_status !== "new" && (
          <button className="link-button" disabled={busy} onClick={() => act("restore")}>
            Restore
          </button>
        )}
      </div>
      {addBlock && <p className="muted small">Add to scholarship: {addBlock}</p>}
      {rec.application_id && (
        <p className="small">
          <Link to={`/staff/applications?review=${rec.application_id}`}>Open the student&apos;s application →</Link>
        </p>
      )}

      {rec.history.length > 0 && (
        <>
          <h3>History</h3>
          <ul className="rec-history">
            {rec.history.map((item, index) => (
              <li key={index}>
                <strong>{item.label}</strong> · {item.by || "System"} · {formatDateTime(item.at)} · score then {item.score}%
                {item.note && <span className="muted"> — {item.note}</span>}
              </li>
            ))}
          </ul>
        </>
      )}
    </Modal>
  );
}
