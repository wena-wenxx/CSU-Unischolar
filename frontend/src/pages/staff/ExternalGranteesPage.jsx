import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { downloadCSV, formatDate, formatDateTime } from "../../lib/format";
import { EXTERNAL_CATEGORIES, MATCH_RESULT } from "../../lib/recommend";
import { useToast } from "../../lib/toast";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import SheetTabs from "../../components/SheetTabs";

/*
  External Grantees (staff): students who already hold a scholarship from an
  outside party (LGU, CHED list, private foundation).
    1. Upload the party's list (PDF, Word, Excel, CSV or a photo).
    2. The AI reads names and Student IDs and suggests the matching student
       (Student ID first, then a fuzzy name match of at least 80%).
    3. Staff confirm or correct every line, then Save.
  A saved, matched student "holds an external scholarship": it shows on their
  profile and in the Data Bank, and counts for the one-scholarship rule.
*/
const EMPTY_FORM = { program_name: "", agency: "", category: "lgu", school_year: "" };
const stamp = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });

function Badge({ value }) {
  const [label, tone] = MATCH_RESULT[value] || [value, "neutral"];
  return <span className={`status status-${tone}`}>{label}</span>;
}

// The list filters as API parameters.
function query(f, extra = {}) {
  const out = { match: f.match, status: f.status, ...extra };
  if (f.q.trim()) out.q = f.q.trim();
  if (f.program !== "all") out.program = f.program;
  return out;
}

// Each line's starting decision, taken from the AI's suggestion.
function startDecisions(rows) {
  const out = {};
  (rows || []).forEach((row) => {
    out[row.row] = {
      student_id: row.result === "matched" ? row.student?.id ?? null : null,
      include: row.result !== "error",
      manual: null, // a student found by typing a Student ID
    };
  });
  return out;
}

function linesCSV(upload) {
  return (upload.rows || []).map((row) => ({
    Line: row.row,
    "Name on the list": row.name || "",
    "Student ID on the list": row.student_id || "",
    "Text read": row.raw || "",
    "AI result": (MATCH_RESULT[row.result] || [row.result])[0],
    "Match score": row.score ?? "",
    "Matched by": row.method || "",
    "Suggested student": row.student ? `${row.student.name} (${row.student.student_id})` : "",
    Note: row.note || "",
  }));
}

export default function ExternalGranteesPage() {
  const toast = useToast();
  const confirm = useConfirm();

  const [form, setForm] = useState(EMPTY_FORM);
  const [file, setFile] = useState(null);
  const [fileKey, setFileKey] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [showUpload, setShowUpload] = useState(false);

  const [review, setReview] = useState(null);
  const [decisions, setDecisions] = useState({});
  const [saving, setSaving] = useState(false);

  const [list, setList] = useState(null);
  const [filters, setFilters] = useState({ q: "", program: "all", match: "matched", status: "all" });
  const [history, setHistory] = useState([]);

  // .then() (not await) so React's lint rule sees the state is set later.
  const loadList = useCallback(
    (f, page = 1) =>
      api
        .get("/staff/external-grantees", { params: query(f, { page }) })
        .then(({ data }) =>
          setList((current) => ({ ...data, data: page === 1 || !current ? data.data : [...current.data, ...data.data] }))
        )
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load the external grantees."));
          setList((current) => current || { data: [], total: 0, programs: [] });
        }),
    [toast]
  );

  const loadHistory = useCallback(
    () =>
      api
        .get("/staff/external-grantees/uploads")
        .then(({ data }) => {
          setHistory(data);
          return data;
        })
        .catch(() => []),
    []
  );

  const openReview = useCallback(
    (id) =>
      api
        .get(`/staff/external-grantees/uploads/${id}`)
        .then(({ data }) => {
          setReview(data);
          setDecisions(startDecisions(data.rows));
        })
        .catch((err) => toast.error(errMsg(err, "Unable to open this upload."))),
    [toast]
  );

  useEffect(() => {
    loadList({ q: "", program: "all", match: "matched", status: "all" });
    // An upload still waiting for review opens automatically.
    loadHistory().then((uploads) => {
      const waiting = (uploads || []).find((u) => u.status === "review");
      if (waiting) openReview(waiting.id);
    });
  }, [loadList, loadHistory, openReview]);

  function changeFilter(key, value) {
    const next = { ...filters, [key]: value };
    setFilters(next);
    loadList(next);
  }

  async function upload(event) {
    event.preventDefault();
    if (!file) {
      toast.error("Choose the list file first.");
      return;
    }

    const body = new FormData();
    Object.entries(form).forEach(([key, value]) => value && body.append(key, value.trim ? value.trim() : value));
    body.append("file", file);

    setUploading(true);
    try {
      const { data } = await api.post("/staff/external-grantees/uploads", body);
      toast.success(data.message);
      setReview(data.upload);
      setDecisions(startDecisions(data.upload.rows));
      setForm(EMPTY_FORM);
      setFile(null);
      setFileKey((k) => k + 1);
      setShowUpload(false);
      await loadHistory();
      setTimeout(() => document.getElementById("review")?.scrollIntoView({ behavior: "smooth", block: "start" }), 100);
    } catch (err) {
      toast.error(errMsg(err, "The list could not be read."));
    } finally {
      setUploading(false);
    }
  }

  function decide(row, patch) {
    setDecisions((current) => ({ ...current, [row]: { ...current[row], ...patch } }));
  }

  async function findStudent(row, studentId) {
    const wanted = studentId.trim();
    if (!wanted) return;
    try {
      const { data } = await api.get("/staff/data-bank", { params: { q: wanted, per_page: 5 } });
      const exact = data.data.find((s) => s.student_id.toLowerCase() === wanted.toLowerCase());
      if (!exact) {
        toast.error(`No student with Student ID ${wanted}.`);
        return;
      }
      decide(row, {
        student_id: exact.id,
        include: true,
        manual: { id: exact.id, student_id: exact.student_id, name: `${exact.first_name} ${exact.last_name}` },
      });
    } catch (err) {
      toast.error(errMsg(err, "Search failed."));
    }
  }

  async function save() {
    const rows = review.rows || [];
    const unchecked = rows.filter((r) => r.result === "check" && decisions[r.row]?.include && !decisions[r.row]?.student_id);
    const linked = rows.filter((r) => decisions[r.row]?.include && decisions[r.row]?.student_id).length;

    const ok = await confirm({
      title: `Save this list for ${review.program_name}?`,
      message:
        `${linked} student(s) will be recorded as holding ${review.program_name}. ` +
        (unchecked.length ? `${unchecked.length} line(s) marked “Check” have no student chosen and will be kept as “did not match”. ` : "") +
        "Lines that are not ticked are left out.",
      confirmLabel: "Save list",
    });
    if (!ok) return;

    setSaving(true);
    try {
      const { data } = await api.post(`/staff/external-grantees/uploads/${review.id}/save`, {
        decisions: rows.map((r) => ({
          row: r.row,
          student_id: decisions[r.row]?.include ? decisions[r.row]?.student_id ?? null : null,
          include: Boolean(decisions[r.row]?.include),
        })),
      });
      toast.success(data.message);
      setReview(null);
      await Promise.all([loadHistory(), loadList(filters)]);
    } catch (err) {
      toast.error(errMsg(err, "Unable to save the list."));
    } finally {
      setSaving(false);
    }
  }

  async function discard() {
    const ok = await confirm({
      title: "Discard this upload?",
      message: "Nothing from this file will be saved. The upload stays in the history as discarded.",
      confirmLabel: "Discard",
      tone: "danger",
    });
    if (!ok) return;

    try {
      const { data } = await api.post(`/staff/external-grantees/uploads/${review.id}/discard`);
      toast.success(data.message);
      setReview(null);
      await loadHistory();
    } catch (err) {
      toast.error(errMsg(err, "Unable to discard."));
    }
  }

  async function setHolding(item, status) {
    if (status === "ended") {
      const ok = await confirm({
        title: "Mark this scholarship as ended?",
        message: `${item.student?.first_name} ${item.student?.last_name} will no longer count as holding ${item.program_name}.`,
        confirmLabel: "Mark ended",
      });
      if (!ok) return;
    }
    try {
      const { data } = await api.patch(`/staff/external-grantees/${item.id}`, { status });
      toast.success(data.message);
      await loadList(filters);
    } catch (err) {
      toast.error(errMsg(err, "Unable to change it."));
    }
  }

  async function exportList() {
    try {
      const { data } = await api.get("/staff/external-grantees", { params: query(filters, { export: 1 }) });
      const ok = downloadCSV(
        `external-grantees-${filters.match}-${stamp()}.csv`,
        data.data.map((g) => ({
          "Student ID": g.student?.student_id || g.extracted_student_id || "",
          "Last name": g.student?.last_name || "",
          "First name": g.student?.first_name || "",
          "Name on the list": g.extracted_name || "",
          Program: g.program_name,
          Agency: g.agency || "",
          Type: EXTERNAL_CATEGORIES[g.category] || g.category || "",
          "School year": g.school_year || "",
          Match: (MATCH_RESULT[g.match_status] || [g.match_status])[0],
          "Matched by": g.match_method || "",
          "Match score": g.match_score ?? "",
          Status: g.status,
          File: g.upload?.file_name || "",
          "Confirmed by": g.confirmer?.name || "",
          Saved: formatDate(g.created_at),
          Note: g.note || "",
        }))
      );
      if (!ok) toast.info("Nothing to export.");
    } catch (err) {
      toast.error(errMsg(err, "Export failed."));
    }
  }

  async function exportLines(item) {
    try {
      const { data } = await api.get(`/staff/external-grantees/uploads/${item.id}`);
      const ok = downloadCSV(`lines-read-${item.id}-${stamp()}.csv`, linesCSV(data));
      if (!ok) toast.info("No lines were read from this file.");
    } catch (err) {
      toast.error(errMsg(err, "Export failed."));
    }
  }

  function exportHistory() {
    const ok = downloadCSV(
      `external-list-uploads-${stamp()}.csv`,
      history.map((u) => ({
        Uploaded: formatDateTime(u.created_at),
        File: u.file_name,
        Program: u.program_name,
        Agency: u.agency || "",
        "School year": u.school_year || "",
        "Read as": u.read_method || "",
        "Lines read": u.total_rows,
        Matched: u.matched_count,
        "Did not match": u.unmatched_count,
        Errors: u.error_count,
        Status: u.status,
        "Uploaded by": u.uploader?.name || "",
        "Saved by": u.saver?.name || "",
        Saved: u.saved_at ? formatDateTime(u.saved_at) : "",
      }))
    );
    if (!ok) toast.info("No uploads yet.");
  }

  async function downloadOriginal(item) {
    try {
      const response = await api.get(`/staff/external-grantees/uploads/${item.id}/file`, { responseType: "blob" });
      const url = URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = item.file_name;
      link.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("The original file is not stored for this upload.");
    }
  }

  if (!list) return <Loading />;

  const tabs = [
    { key: "all", label: "All programs" },
    ...list.programs.map((p) => ({ key: p.program_name, label: p.program_name, count: p.total })),
  ];

  return (
    <div>
      <PageHeader
        title="External Grantees"
        subtitle="Students who already hold a scholarship from an outside party (LGU, CHED list, foundation)"
        actions={
          !showUpload && (
            <button className="button button-primary" onClick={() => setShowUpload(true)}>
              Upload a list
            </button>
          )
        }
      />

      {showUpload && (
      <section className="card">
        <div className="card-header">
          <h2>Upload a list</h2>
          <button className="button button-small button-secondary" onClick={() => setShowUpload(false)}>
            Close
          </button>
        </div>
        <form className="form-grid" onSubmit={upload}>
          <div>
            <label htmlFor="ext-program">Scholarship name</label>
            <input
              id="ext-program"
              list="ext-programs"
              value={form.program_name}
              onChange={(event) => setForm({ ...form, program_name: event.target.value })}
              placeholder="e.g. City Scholarship Program"
              maxLength={255}
              required
            />
            <datalist id="ext-programs">
              {list.programs.map((p) => (
                <option key={p.program_name} value={p.program_name} />
              ))}
            </datalist>
          </div>
          <div>
            <label htmlFor="ext-agency">Given by (agency or office)</label>
            <input
              id="ext-agency"
              value={form.agency}
              onChange={(event) => setForm({ ...form, agency: event.target.value })}
              placeholder="e.g. City Government"
              maxLength={255}
            />
          </div>
          <div>
            <label htmlFor="ext-category">Type</label>
            <select id="ext-category" value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })}>
              {Object.entries(EXTERNAL_CATEGORIES).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="ext-year">School year (optional)</label>
            <input
              id="ext-year"
              value={form.school_year}
              onChange={(event) => setForm({ ...form, school_year: event.target.value })}
              placeholder="2026-2027"
              pattern="\d{4}-\d{4}"
              title="Like 2026-2027"
            />
          </div>
          <div className="full-column">
            <label htmlFor="ext-file">List file</label>
            <input
              key={fileKey}
              id="ext-file"
              type="file"
              accept=".pdf,.docx,.xlsx,.csv,.png,.jpg,.jpeg"
              onChange={(event) => setFile(event.target.files?.[0] || null)}
            />
            <p className="muted small">
              PDF, Word (.docx), Excel (.xlsx), CSV, or a photo (PNG/JPG). The AI reads the names and Student IDs and
              suggests the matching students; you confirm before anything is saved. Photos and scans are read with OCR, so
              check them carefully.
            </p>
          </div>
          <div className="full-column button-row">
            <button className="button button-primary" disabled={uploading}>
              {uploading ? "Reading the file..." : "Upload and read"}
            </button>
          </div>
        </form>
      </section>
      )}

      {review && (
        <ReviewCard
          review={review}
          decisions={decisions}
          decide={decide}
          findStudent={findStudent}
          saving={saving}
          onSave={save}
          onDiscard={discard}
          onExport={() => downloadCSV(`lines-read-${review.id}-${stamp()}.csv`, linesCSV(review))}
        />
      )}

      <section className="card sheet-card">
        <div className="card-header">
          <h2>Students holding an external scholarship</h2>
          <button className="button button-small button-secondary" onClick={exportList} disabled={!list.total}>
            Export CSV ({list.total})
          </button>
        </div>

        <SheetTabs tabs={tabs} value={filters.program} onChange={(key) => changeFilter("program", key)} />

        <div className="sheet-toolbar">
          <form
            className="toolbar-search"
            onSubmit={(event) => {
              event.preventDefault();
              loadList(filters);
            }}
          >
            <label htmlFor="ext-search" className="sr-only">
              Search
            </label>
            <input
              id="ext-search"
              type="search"
              value={filters.q}
              onChange={(event) => setFilters({ ...filters, q: event.target.value })}
              placeholder="Name or Student ID, then Enter"
            />
          </form>
          <label htmlFor="ext-match" className="sr-only">
            Lines
          </label>
          <select id="ext-match" value={filters.match} onChange={(event) => changeFilter("match", event.target.value)}>
            <option value="matched">Matched students</option>
            <option value="unmatched">Lines that did not match</option>
            <option value="error">Lines with errors</option>
            <option value="all">All saved lines</option>
          </select>
          <label htmlFor="ext-status" className="sr-only">
            Status
          </label>
          <select id="ext-status" value={filters.status} onChange={(event) => changeFilter("status", event.target.value)}>
            <option value="all">Active and ended</option>
            <option value="active">Active only</option>
            <option value="ended">Ended only</option>
          </select>
          <span className="sheet-meta muted">{list.total} shown</span>
        </div>

        {list.data.length === 0 ? (
          <EmptyState message="Nothing here yet. Upload a list above." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Name on the list</th>
                  {filters.program === "all" && <th>Scholarship</th>}
                  <th>Matched by</th>
                  <th>Status</th>
                  <th>
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {list.data.map((g) => (
                  <tr key={g.id} className={g.status === "ended" ? "row-muted" : ""}>
                    <td>
                      {g.student ? (
                        <>
                          <strong>
                            {g.student.last_name}, {g.student.first_name}
                          </strong>
                          <small className="muted account-note">{g.student.student_id}</small>
                        </>
                      ) : (
                        <Badge value={g.match_status} />
                      )}
                    </td>
                    <td>
                      {g.extracted_name || "—"}
                      {g.extracted_student_id && <small className="muted account-note">{g.extracted_student_id}</small>}
                    </td>
                    {filters.program === "all" && (
                      <td>
                        {g.program_name}
                        {g.agency && <small className="muted account-note">{g.agency}</small>}
                      </td>
                    )}
                    <td>
                      {g.match_method === "student_id"
                        ? "Student ID"
                        : g.match_method === "name"
                          ? `Name (${g.match_score}%)`
                          : g.match_method === "manual"
                            ? "Chosen by staff"
                            : "—"}
                    </td>
                    <td>
                      <span className={`status status-${g.status === "active" ? "success" : "neutral"}`}>{g.status}</span>
                    </td>
                    <td>
                      {g.student && (
                        <div className="row-actions">
                          <Link className="button button-small button-secondary" to={`/staff/data-bank?student=${g.student.id}`}>
                            Review
                          </Link>
                          <button
                            className="button button-small button-secondary"
                            onClick={() => setHolding(g, g.status === "active" ? "ended" : "active")}
                          >
                            {g.status === "active" ? "Mark ended" : "Re-activate"}
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {list.has_more && (
          <div className="show-more">
            <button className="button button-secondary" onClick={() => loadList(filters, (list.page || 1) + 1)}>
              Show more ({list.total - list.data.length} left)
            </button>
          </div>
        )}
        <p className="muted small">
          A matched, active holding counts for the one-active-scholarship rule: the student cannot apply for another
          scholarship in ScholarGuide until it is marked ended.
        </p>
      </section>

      <section className="card">
        <div className="card-header">
          <h2>Upload history</h2>
          <button className="button button-small button-secondary" onClick={exportHistory} disabled={!history.length}>
            Export CSV
          </button>
        </div>

        {history.length === 0 ? (
          <EmptyState message="No lists uploaded yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Uploaded</th>
                  <th>File</th>
                  <th>Scholarship</th>
                  <th className="numeric">Lines</th>
                  <th>Result</th>
                  <th>Status</th>
                  <th>By</th>
                  <th>
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {history.map((u) => (
                  <tr key={u.id}>
                    <td className="nowrap">{formatDateTime(u.created_at)}</td>
                    <td>{u.file_name}</td>
                    <td>{u.program_name}</td>
                    <td className="numeric">{u.total_rows}</td>
                    <td className="small">
                      {u.matched_count} matched · {u.unmatched_count} did not match · {u.error_count} error
                      {u.error_count === 1 ? "" : "s"}
                      {u.status === "review" && u.check_count ? ` · ${u.check_count} to check` : ""}
                    </td>
                    <td>
                      <span
                        className={`status status-${u.status === "saved" ? "success" : u.status === "review" ? "warning" : "neutral"}`}
                      >
                        {u.status === "review" ? "Waiting for review" : u.status}
                      </span>
                    </td>
                    <td className="small">{u.saver?.name || u.uploader?.name || "—"}</td>
                    <td>
                      <div className="row-actions">
                        {u.status === "review" && review?.id !== u.id && (
                          <button className="button button-small button-primary" onClick={() => openReview(u.id)}>
                            Review
                          </button>
                        )}
                        <button className="button button-small button-secondary" onClick={() => exportLines(u)}>
                          Lines CSV
                        </button>
                        {u.file_path && (
                          <button className="link-button small" onClick={() => downloadOriginal(u)}>
                            Original file
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

/* Step 2: staff confirm or correct each line before saving. */
function ReviewCard({ review, decisions, decide, findStudent, saving, onSave, onDiscard, onExport }) {
  const [typing, setTyping] = useState({});
  const rows = review.rows || [];
  const count = (result) => rows.filter((r) => r.result === result).length;

  return (
    <section className="card review-card" id="review">
      <div className="card-header">
        <div>
          <h2>Check the matches</h2>
          <p className="muted small">
            {review.file_name} · {review.program_name}
            {review.agency ? ` · ${review.agency}` : ""} · read as {review.read_method}
          </p>
        </div>
        <button className="button button-small button-secondary" onClick={onExport}>
          Export lines read (CSV)
        </button>
      </div>

      <div className="match-summary">
        <span className="status status-success">{count("matched")} matched</span>
        <span className="status status-warning">{count("check")} to check</span>
        <span className="status status-neutral">{count("unmatched")} did not match</span>
        <span className="status status-danger">
          {count("error")} error{count("error") === 1 ? "" : "s"}
        </span>
      </div>

      {(review.warnings || []).map((warning) => (
        <p className="alert alert-warning" key={warning}>
          {warning}
        </p>
      ))}

      {rows.length === 0 ? (
        <EmptyState message="No names or Student IDs were found in this file. Discard it and try another copy." />
      ) : (
        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Keep</th>
                <th>Line on the list</th>
                <th>AI result</th>
                <th>Student in ScholarGuide</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const decision = decisions[row.row] || {};
                const options = [...(row.suggestions || [])];
                if (row.student && !options.some((o) => o.id === row.student.id)) options.unshift(row.student);
                if (decision.manual && !options.some((o) => o.id === decision.manual.id)) options.push(decision.manual);

                return (
                  <tr key={row.row} className={decision.include ? (row.result === "check" ? "row-check" : "") : "row-muted"}>
                    <td>
                      <label className="sr-only" htmlFor={`keep-${row.row}`}>
                        Keep line {row.row}
                      </label>
                      <input
                        id={`keep-${row.row}`}
                        type="checkbox"
                        checked={Boolean(decision.include)}
                        onChange={(event) => decide(row.row, { include: event.target.checked })}
                      />
                    </td>
                    <td>
                      <strong>{row.name || "—"}</strong>
                      <small className="muted account-note">
                        {row.student_id || "no Student ID"} · line {row.row}
                      </small>
                    </td>
                    <td>
                      <Badge value={row.result} />
                      {row.score !== null && row.score !== undefined && <small className="muted"> {row.score}%</small>}
                      {row.note && <small className="muted account-note">{row.note}</small>}
                    </td>
                    <td className="pick-cell">
                      <label className="sr-only" htmlFor={`pick-${row.row}`}>
                        Student for line {row.row}
                      </label>
                      <select
                        id={`pick-${row.row}`}
                        value={decision.student_id ?? ""}
                        disabled={!decision.include}
                        onChange={(event) => {
                          if (event.target.value === "find") {
                            setTyping({ ...typing, [row.row]: "" });
                            return;
                          }
                          decide(row.row, { student_id: event.target.value ? Number(event.target.value) : null });
                        }}
                      >
                        <option value="">{row.result === "check" ? "Choose the student…" : "Not in ScholarGuide"}</option>
                        {options.map((o) => (
                          <option key={o.id} value={o.id}>
                            {o.name} ({o.student_id}){o.score ? ` · ${o.score}%` : ""}
                          </option>
                        ))}
                        <option value="find">Find by Student ID…</option>
                      </select>
                      {typing[row.row] !== undefined && (
                        <form
                          className="find-id"
                          onSubmit={(event) => {
                            event.preventDefault();
                            findStudent(row.row, typing[row.row]);
                            const next = { ...typing };
                            delete next[row.row];
                            setTyping(next);
                          }}
                        >
                          <label className="sr-only" htmlFor={`find-${row.row}`}>
                            Student ID
                          </label>
                          <input
                            id={`find-${row.row}`}
                            value={typing[row.row]}
                            onChange={(event) => setTyping({ ...typing, [row.row]: event.target.value })}
                            placeholder="e.g. 2024-10123"
                          />
                          <button className="button button-small button-secondary">Find</button>
                        </form>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <div className="button-row">
        <button className="button button-primary" onClick={onSave} disabled={saving}>
          {saving ? "Saving..." : "Save confirmed list"}
        </button>
        <button className="button button-secondary" onClick={onDiscard} disabled={saving}>
          Discard upload
        </button>
        <span className="muted small">Nothing is saved until you press Save. Untick a line to leave it out.</span>
      </div>
    </section>
  );
}
