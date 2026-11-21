import { useCallback, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { formatDate, formatDateTime, formatMoney } from "../../lib/format";
import { useToast } from "../../lib/toast";
import { lastFirst } from "../../lib/programs";
import Modal from "../../components/Modal";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import StatusBadge from "../../components/StatusBadge";

/*
  Processing Batches (staff): one batch = one processing cycle of one program,
  e.g. "TES - 1st Semester AY 2026-2027".
  New applications and approved lists go into the open batch of their program and
  period (one is made automatically when there is none). When a cycle is finished,
  staff mark its batch completed: nothing is deleted, the records stay in
  Applications, Scholar Records, Payroll and the Data Bank, and the next cycle
  starts in a new batch, so cycles never mix.
*/
const batchBadge = (batch) => (
  <StatusBadge status={batch.status === "open" ? "processing" : "completed"} label={batch.status === "open" ? "Open" : "Completed"} />
);

function yearChoices(current) {
  const start = Number(String(current || "").slice(0, 4)) || new Date().getFullYear();
  return [start - 2, start - 1, start, start + 1].map((y) => `${y}-${y + 1}`);
}

export default function ProcessingBatchesPage() {
  const toast = useToast();

  const [batches, setBatches] = useState(null);
  const [options, setOptions] = useState({ scholarships: [], semesters: [] });
  const [filters, setFilters] = useState({ scholarship_id: "", status: "", school_year: "" });
  const [creating, setCreating] = useState(false);
  const [openId, setOpenId] = useState(null);

  const load = useCallback(
    () =>
      api
        .get("/staff/processing-batches", {
          params: Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== "")),
        })
        .then(({ data }) => {
          setBatches(data.data || []);
          setOptions(data.options || { scholarships: [], semesters: [] });
        })
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load the processing batches."));
          setBatches([]);
        }),
    [filters, toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  if (!batches) return <Loading />;

  const setFilter = (key, value) => setFilters((old) => ({ ...old, [key]: value }));
  const openCount = batches.filter((b) => b.status === "open").length;

  return (
    <div>
      <PageHeader
        title="Processing Batches"
        subtitle="Each scholarship cycle in its own batch, so a new cycle never mixes with an earlier one"
        actions={
          <button className="button button-primary" onClick={() => setCreating(true)}>
            New batch
          </button>
        }
      />

      <section className="card sheet-card">
        <div className="sheet-toolbar">
          <label htmlFor="pb-program" className="sr-only">
            Program
          </label>
          <select id="pb-program" value={filters.scholarship_id} onChange={(event) => setFilter("scholarship_id", event.target.value)}>
            <option value="">All programs</option>
            {options.scholarships.map((s) => (
              <option key={s.id} value={String(s.id)}>
                {s.name}
              </option>
            ))}
          </select>

          <label htmlFor="pb-status" className="sr-only">
            Status
          </label>
          <select id="pb-status" value={filters.status} onChange={(event) => setFilter("status", event.target.value)}>
            <option value="">Open and completed</option>
            <option value="open">Open</option>
            <option value="completed">Completed</option>
          </select>

          <label htmlFor="pb-year" className="sr-only">
            Academic year
          </label>
          <select id="pb-year" value={filters.school_year} onChange={(event) => setFilter("school_year", event.target.value)}>
            <option value="">All academic years</option>
            {yearChoices(options.current_school_year).map((year) => (
              <option key={year} value={year}>
                AY {year}
              </option>
            ))}
          </select>

          <span className="sheet-meta muted">
            {batches.length} batch{batches.length === 1 ? "" : "es"} · {openCount} open
          </span>
        </div>

        {batches.length === 0 ? (
          <EmptyState message="No batches yet. One is made automatically with the first application or approved list of a program and period." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Batch</th>
                  <th>Status</th>
                  <th className="numeric">Applications</th>
                  <th className="numeric">Pending</th>
                  <th className="numeric">Approved</th>
                  <th className="numeric">Rejected</th>
                  <th className="numeric">Unmatched rows</th>
                  <th className="numeric">Grantees</th>
                  <th className="numeric">Payroll</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {batches.map((b) => (
                  <tr key={b.id}>
                    <td>
                      <strong>{b.name}</strong>
                      <div className="muted small">
                        {b.scholarship?.name} · created {formatDate(b.created_at)}
                        {b.creator ? ` by ${b.creator.name}` : " automatically"}
                      </div>
                    </td>
                    <td>{batchBadge(b)}</td>
                    <td className="numeric">{b.counts.applications}</td>
                    <td className="numeric">{b.counts.pending ? <span className="text-warning">{b.counts.pending}</span> : 0}</td>
                    <td className="numeric">{b.counts.approved}</td>
                    <td className="numeric">{b.counts.rejected}</td>
                    <td className="numeric">{b.counts.unmatched ? <span className="text-warning">{b.counts.unmatched}</span> : 0}</td>
                    <td className="numeric">{b.counts.grantees}</td>
                    <td className="numeric">
                      {b.counts.payroll_entries}
                      {b.counts.payroll_entries ? <div className="muted small">{b.counts.payroll_processed} processed</div> : null}
                    </td>
                    <td>
                      <button className="button button-small button-secondary" onClick={() => setOpenId(b.id)}>
                        Open
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <p className="muted small">
          Completing a batch deletes nothing. Its applications, lists, grantees and payroll stay in their pages and in the
          Data Bank; new work of the same program and period then goes into a new batch.
        </p>
      </section>

      {creating && (
        <NewBatchModal
          options={options}
          onClose={() => setCreating(false)}
          onCreated={() => {
            setCreating(false);
            load();
          }}
        />
      )}

      {openId && <BatchModal id={openId} onClose={() => setOpenId(null)} onChanged={load} />}
    </div>
  );
}

function NewBatchModal({ options, onClose, onCreated }) {
  const toast = useToast();
  const [form, setForm] = useState({
    scholarship_id: "",
    school_year: options.current_school_year || "",
    semester: options.current_semester || "",
    name: "",
    notes: "",
  });
  const [saving, setSaving] = useState(false);

  const program = options.scholarships.find((s) => String(s.id) === form.scholarship_id);
  const suggested = program
    ? `${program.short_name || program.name} - ${[form.semester, form.school_year && `AY ${form.school_year}`].filter(Boolean).join(" ")}`
    : "";

  async function save() {
    setSaving(true);
    try {
      const { data } = await api.post("/staff/processing-batches", {
        scholarship_id: Number(form.scholarship_id),
        school_year: form.school_year,
        semester: form.semester || null,
        name: form.name.trim() || null,
        notes: form.notes.trim() || null,
      });
      toast.success(data.message);
      onCreated();
    } catch (err) {
      toast.error(errMsg(err, "Unable to create the batch."));
    } finally {
      setSaving(false);
    }
  }

  const set = (key, value) => setForm((old) => ({ ...old, [key]: value }));

  return (
    <Modal
      title="New processing batch"
      onClose={onClose}
      footer={
        <div className="button-row">
          <button className="button button-secondary" onClick={onClose}>
            Cancel
          </button>
          <button className="button button-primary" onClick={save} disabled={saving || !form.scholarship_id || !form.school_year}>
            {saving ? "Creating..." : "Create batch"}
          </button>
        </div>
      }
    >
      <div className="form-grid">
        <div>
          <label htmlFor="nb-program">Program</label>
          <select id="nb-program" value={form.scholarship_id} onChange={(event) => set("scholarship_id", event.target.value)}>
            <option value="">Choose a program</option>
            {options.scholarships.map((s) => (
              <option key={s.id} value={String(s.id)}>
                {s.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="nb-year">Academic year</label>
          <select id="nb-year" value={form.school_year} onChange={(event) => set("school_year", event.target.value)}>
            <option value="">Choose academic year</option>
            {yearChoices(options.current_school_year).map((year) => (
              <option key={year} value={year}>
                {year}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="nb-semester">Semester</label>
          <select id="nb-semester" value={form.semester} onChange={(event) => set("semester", event.target.value)}>
            <option value="">Whole year / not set</option>
            {options.semesters.map((semester) => (
              <option key={semester}>{semester}</option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="nb-name">Name (optional)</label>
          <input id="nb-name" value={form.name} maxLength={140} placeholder={suggested} onChange={(event) => set("name", event.target.value)} />
        </div>
        <div className="full-column">
          <label htmlFor="nb-notes">Notes (optional)</label>
          <textarea id="nb-notes" rows={2} value={form.notes} onChange={(event) => set("notes", event.target.value)} />
        </div>
      </div>
      <p className="muted small">
        New applications and approved lists of this program and period go into the open batch. Only one batch per period
        is needed; make another only for a separate cycle (for example TES Batch 2 billing).
      </p>
    </Modal>
  );
}

function BatchModal({ id, onClose, onChanged }) {
  const toast = useToast();
  const [data, setData] = useState(null);
  const [tab, setTab] = useState("applications");
  const [completing, setCompleting] = useState(false);
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  // Kept in a ref so a new onClose from the parent does not load the batch again.
  const closeRef = useRef(onClose);
  useEffect(() => {
    closeRef.current = onClose;
  });

  const load = useCallback(
    () =>
      api
        .get(`/staff/processing-batches/${id}`)
        .then(({ data: body }) => setData(body))
        .catch((err) => {
          toast.error(errMsg(err, "Unable to open the batch."));
          closeRef.current();
        }),
    [id, toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  async function complete() {
    const open = data.batch.open_items || [];
    setSaving(true);
    try {
      const { data: body } = await api.post(`/staff/processing-batches/${id}/complete`, {
        confirm_open_items: open.length > 0,
        note: note.trim() || null,
      });
      toast.success(body.message);
      setCompleting(false);
      setNote("");
      load();
      onChanged();
    } catch (err) {
      toast.error(errMsg(err, "Unable to complete the batch."));
    } finally {
      setSaving(false);
    }
  }

  async function reopen() {
    setSaving(true);
    try {
      const { data: body } = await api.post(`/staff/processing-batches/${id}/reopen`);
      toast.success(body.message);
      load();
      onChanged();
    } catch (err) {
      toast.error(errMsg(err, "Unable to reopen the batch."));
    } finally {
      setSaving(false);
    }
  }

  if (!data) {
    return (
      <Modal size="large" title="Processing batch" onClose={onClose}>
        <Loading />
      </Modal>
    );
  }

  const { batch } = data;
  const c = batch.counts;
  const openItems = batch.open_items || [];

  return (
    <Modal
      size="large"
      title={batch.name}
      subtitle={
        <p className="muted small">
          {batch.scholarship?.name} · {[batch.semester, batch.school_year && `AY ${batch.school_year}`].filter(Boolean).join(" ") || "No period"} ·{" "}
          {batchBadge(batch)}
          {batch.status === "completed" && batch.completed_at
            ? ` Completed ${formatDateTime(batch.completed_at)}${batch.completer ? ` by ${batch.completer.name}` : ""}`
            : ""}
        </p>
      }
      onClose={onClose}
      footer={
        <div className="button-row">
          <Link className="button button-secondary" to={`/staff/applications?batch=${batch.id}`}>
            Open in Applications
          </Link>
          <Link className="button button-secondary" to={`/staff/data-bank?batch=${batch.id}`}>
            Students in Data Bank
          </Link>
          {batch.status === "open" ? (
            <button className="button button-primary" onClick={() => setCompleting(true)} disabled={saving}>
              Mark completed
            </button>
          ) : (
            <button className="button button-secondary" onClick={reopen} disabled={saving}>
              Reopen
            </button>
          )}
        </div>
      }
    >
      <div className="result-summary">
        {[
          ["Applications", c.applications, "neutral"],
          ["Pending OAS action", c.pending, c.pending ? "warning" : "neutral"],
          ["Forwarded", c.forwarded, "neutral"],
          ["Approved", c.approved, "success"],
          ["Rejected", c.rejected, "neutral"],
          ["Unmatched list rows", c.unmatched, c.unmatched ? "warning" : "neutral"],
          ["Grantees", c.grantees, "success"],
          ["Payroll entries", c.payroll_entries, "neutral"],
        ].map(([label, value, tone]) => (
          <div key={label} className={`result-box tone-${tone}`}>
            <strong>{value}</strong>
            <span>{label}</span>
          </div>
        ))}
      </div>

      {batch.status === "open" && openItems.length > 0 && (
        <div className="alert alert-warning">
          <strong>Still open in this batch:</strong>
          <ul className="plain-list">
            {openItems.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        </div>
      )}
      {batch.notes && <p className="muted small" style={{ whiteSpace: "pre-line" }}>{batch.notes}</p>}

      <div className="button-row">
        {[
          ["applications", `Applications (${data.applications.length})`],
          ["lists", `Approved lists (${data.lists.length})`],
          ["grantees", `Grantees (${data.grantees.length})`],
          ["payroll", "Payroll"],
        ].map(([key, label]) => (
          <button
            key={key}
            className={`button button-small ${tab === key ? "button-primary" : "button-secondary"}`}
            onClick={() => setTab(key)}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === "applications" &&
        (data.applications.length === 0 ? (
          <EmptyState message="No applications in this batch." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Student ID</th>
                  <th>Status</th>
                  <th>Enrollment</th>
                  <th>Last change</th>
                </tr>
              </thead>
              <tbody>
                {data.applications.map((a) => (
                  <tr key={a.id}>
                    <td>
                      <strong className="student-name">{lastFirst(a.student)}</strong>
                    </td>
                    <td>{a.student?.student_id}</td>
                    <td>
                      <StatusBadge status={a.status} />
                    </td>
                    <td>
                      {a.enrollment_verified
                        ? `Verified${a.enrollment_method === "registrar_list" ? " (Registrar's list)" : a.enrollment_method === "manual" ? " (by staff)" : ""}`
                        : "—"}
                    </td>
                    <td>{formatDate(a.updated_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}

      {tab === "lists" &&
        (data.lists.length === 0 ? (
          <EmptyState message="No approved lists in this batch." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Uploaded</th>
                  <th>File</th>
                  <th>By</th>
                  <th className="numeric">Rows</th>
                  <th className="numeric">Matched</th>
                  <th className="numeric">To resolve</th>
                </tr>
              </thead>
              <tbody>
                {data.lists.map((u) => (
                  <tr key={u.id}>
                    <td>{formatDateTime(u.created_at)}</td>
                    <td>{u.file_name}</td>
                    <td>{u.uploader?.name || "—"}</td>
                    <td className="numeric">{u.total_rows}</td>
                    <td className="numeric">{u.approved_count + u.created_count + u.already_count}</td>
                    <td className="numeric">{u.open_rows ? <span className="text-warning">{u.open_rows}</span> : 0}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="muted small">
              Resolve unmatched rows in <Link to="/staff/agency-lists">Approved Lists</Link> (Upload history → Details).
            </p>
          </div>
        ))}

      {tab === "grantees" &&
        (data.grantees.length === 0 ? (
          <EmptyState message="No grantees tagged in this batch yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Student ID</th>
                  <th>School year</th>
                  <th>Status</th>
                  <th>Enrolled</th>
                  <th>Tagged</th>
                </tr>
              </thead>
              <tbody>
                {data.grantees.map((r) => (
                  <tr key={r.id}>
                    <td>
                      <strong className="student-name">{lastFirst(r.student)}</strong>
                    </td>
                    <td>{r.student?.student_id}</td>
                    <td>{[r.semester, r.school_year].filter(Boolean).join(" ") || "—"}</td>
                    <td>
                      <StatusBadge status={r.status} />
                    </td>
                    <td>{r.currently_enrolled ? "Yes" : "No"}</td>
                    <td>{formatDate(r.grantee_tagged_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}

      {tab === "payroll" &&
        (data.payroll.length === 0 ? (
          <EmptyState message="No payroll entries in this batch yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Period</th>
                  <th>Status</th>
                  <th className="numeric">Entries</th>
                  <th className="numeric">Total</th>
                </tr>
              </thead>
              <tbody>
                {data.payroll.map((p) => (
                  <tr key={`${p.period}|${p.status}`}>
                    <td>{p.period}</td>
                    <td>
                      <StatusBadge status={p.status} />
                    </td>
                    <td className="numeric">{p.entries}</td>
                    <td className="numeric">{formatMoney(p.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}

      {completing && (
        <Modal
          title={`Complete "${batch.name}"?`}
          onClose={() => setCompleting(false)}
          footer={
            <div className="button-row">
              <button className="button button-secondary" onClick={() => setCompleting(false)}>
                Cancel
              </button>
              <button
                className="button button-primary"
                onClick={complete}
                disabled={saving || (openItems.length > 0 && !note.trim())}
              >
                {saving ? "Saving..." : "Mark completed"}
              </button>
            </div>
          }
        >
          <p>
            Nothing is deleted. The batch is closed for new work; its records stay in Applications, Scholar Records,
            Payroll and the Data Bank. You can reopen it later.
          </p>
          {openItems.length > 0 && (
            <>
              <div className="alert alert-warning">
                <strong>This batch still has open work:</strong>
                <ul className="plain-list">
                  {openItems.map((item) => (
                    <li key={item}>{item}</li>
                  ))}
                </ul>
              </div>
              <label htmlFor="complete-note">Why complete it now? (required)</label>
              <textarea id="complete-note" rows={3} value={note} onChange={(event) => setNote(event.target.value)} />
            </>
          )}
        </Modal>
      )}
    </Modal>
  );
}
