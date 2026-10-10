import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { enrollmentText, formatDate, fullName } from "../../lib/format";
import { useToast } from "../../lib/toast";
import Modal from "../../components/Modal";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import SheetTabs from "../../components/SheetTabs";
import { byLastName, lastFirst, programTabs } from "../../lib/programs";

export default function StaffScholarRecordsPage() {
  const toast = useToast();
  const confirm = useConfirm();

  const [records, setRecords] = useState([]);
  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [tagging, setTagging] = useState(null);
  const [editingAtm, setEditingAtm] = useState(null);
  const [query, setQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("active");
  const [atmFilter, setAtmFilter] = useState("all");
  const [program, setProgram] = useState("all");

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      Promise.all([api.get("/scholar-records"), api.get("/applications", { params: { status: "approved" } })])
        .then(([recordsResponse, applicationsResponse]) => {
          setRecords(recordsResponse.data);
          setApplications(applicationsResponse.data.filter((a) => a.status === "approved"));
        })
        .catch((err) => toast.error(errMsg(err, "Unable to load scholar records.")))
        .finally(() => setLoading(false)),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  // Approved applications that are not yet tagged as grantees.
  const untagged = applications.filter(
    (application) =>
      !records.some(
        (record) =>
          record.student_id === application.student_id &&
          record.scholarship_id === application.scholarship_id
      )
  );
  const readyToTag = untagged.filter((application) => application.enrollment_verified);
  const waitingForVerification = untagged.filter((application) => !application.enrollment_verified);

  async function updateRecord(record, changes, question) {
    if (question && !(await confirm(question))) return;

    try {
      await api.patch(`/scholar-records/${record.id}`, changes);
      toast.success("Scholar record updated.");
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to update scholar record."));
    }
  }

  // Filters first, then one tab per program; every list is A to Z by last name.
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  const filtered = records.filter((record) => {
    if (statusFilter !== "all" && record.status !== statusFilter) return false;
    if (atmFilter === "funded" && !(record.has_atm && record.atm_funds === "yes")) return false;
    if (atmFilter === "waiting" && !(record.has_atm && record.atm_funds !== "yes")) return false;
    if (atmFilter === "none" && record.has_atm) return false;
    const haystack = `${lastFirst(record.student)} ${record.student?.student_id || ""} ${record.scholarship?.name || ""}`.toLowerCase();
    return words.every((word) => haystack.includes(word));
  });
  const tabs = programTabs(filtered, (record) => record.scholarship);
  const activeProgram = tabs.some((tab) => tab.key === program) ? program : "all";
  const shownRecords = filtered
    .filter((record) => activeProgram === "all" || String(record.scholarship_id) === activeProgram)
    .sort((a, b) => byLastName(a.student, b.student));

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Scholar Records"
        subtitle="Tag verified students as grantees and manage active scholars"
      />

      <section className="card">
        <h2>Ready to Tag as Grantee</h2>

        <p className="muted">Approved by the agency and enrollment verified by OAS.</p>

        {readyToTag.length === 0 ? (
          <EmptyState message="No students are ready for tagging." />
        ) : (
          readyToTag.map((application) => (
            <div className="list-item" key={application.id}>
              <div>
                <strong>{fullName(application.student)}</strong>

                <p className="muted">
                  {application.student?.student_id} · {application.scholarship?.name} ·{" "}
                  {enrollmentText(application)}
                </p>
              </div>

              <button className="button button-primary" onClick={() => setTagging(application)}>
                Tag as Grantee
              </button>
            </div>
          ))
        )}

        {waitingForVerification.length > 0 && (
          <p className="muted">
            {waitingForVerification.length} approved application(s) still need enrollment
            verification. <Link to="/staff/applications">Go to Applications</Link> and press
            “Verify enrollment”.
          </p>
        )}
      </section>

      <section className="card sheet-card">
        <h2>Current Scholar Records</h2>

        <SheetTabs tabs={tabs} value={activeProgram} onChange={setProgram} />

        <div className="sheet-toolbar">
          <label htmlFor="scholar-search" className="sr-only">
            Search scholars
          </label>
          <input
            id="scholar-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search name, student ID or scholarship"
          />

          <label htmlFor="scholar-status" className="sr-only">
            Status
          </label>
          <select id="scholar-status" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
            <option value="active">Active scholars</option>
            <option value="completed">Completed</option>
            <option value="inactive">Inactive</option>
            <option value="all">All records</option>
          </select>

          <label htmlFor="scholar-atm" className="sr-only">
            ATM status
          </label>
          <select id="scholar-atm" value={atmFilter} onChange={(event) => setAtmFilter(event.target.value)}>
            <option value="all">Any ATM status</option>
            <option value="funded">ATM · funded</option>
            <option value="waiting">ATM · funds pending / none</option>
            <option value="none">No ATM yet</option>
          </select>

          <span className="sheet-meta muted">{shownRecords.length} shown, A–Z</span>
        </div>

        {shownRecords.length === 0 ? (
          <EmptyState message="No scholar records in this list." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Student ID</th>
                  {activeProgram === "all" && <th>Program</th>}
                  <th>Status</th>
                  <th>Enrolled</th>
                  <th>Has ATM</th>
                  <th>ATM funds</th>
                  <th>If no ATM</th>
                  <th>Tagged</th>
                  <th>Actions</th>
                </tr>
              </thead>

              <tbody>
                {shownRecords.map((record) => (
                  <tr key={record.id}>
                    <td>{lastFirst(record.student)}</td>
                    <td>{record.student?.student_id}</td>
                    {activeProgram === "all" && (
                      <td title={record.scholarship?.name}>{record.scholarship?.short_name || record.scholarship?.name}</td>
                    )}
                    <td>
                      <StatusBadge status={record.status} />
                    </td>
                    <td>{record.currently_enrolled ? "Yes" : "No"}</td>
                    <td>{record.has_atm ? "Yes" : "No"}</td>
                    <td>
                      {record.has_atm ? (
                        <span className={`status status-${FUNDS_TONE[record.atm_funds] || "neutral"}`}>
                          {FUNDS_LABEL[record.atm_funds] || "Not set"}
                        </span>
                      ) : (
                        "—"
                      )}
                    </td>
                    <td>{record.has_atm ? "—" : record.atm_note || "—"}</td>
                    <td>{formatDate(record.grantee_tagged_at)}</td>
                    <td>
                      <div className="button-row">
                        <button className="button button-small button-secondary" onClick={() => setEditingAtm(record)}>
                          ATM status
                        </button>

                        {record.status === "active" && (
                          <>
                            <button
                              className="button button-small button-success"
                              onClick={() =>
                                updateRecord(
                                  record,
                                  { status: "completed" },
                                  {
                                    title: "Mark scholarship as completed?",
                                    message: `${fullName(record.student)} will no longer be an active scholar for ${record.scholarship?.name}.`,
                                    confirmLabel: "Mark completed",
                                  }
                                )
                              }
                            >
                              Completed
                            </button>

                            <button
                              className="button button-small button-danger"
                              onClick={() =>
                                updateRecord(
                                  record,
                                  { status: "inactive" },
                                  {
                                    title: "Set scholarship to inactive?",
                                    message: `${fullName(record.student)} will be removed from active scholars and cannot be added to payroll.`,
                                    confirmLabel: "Set inactive",
                                    tone: "danger",
                                  }
                                )
                              }
                            >
                              Inactive
                            </button>
                          </>
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

      {editingAtm && (
        <AtmModal
          record={editingAtm}
          onClose={() => setEditingAtm(null)}
          onDone={async () => {
            setEditingAtm(null);
            await load();
          }}
        />
      )}

      {tagging && (
        <TagGranteeModal
          application={tagging}
          onClose={() => setTagging(null)}
          onDone={async () => {
            setTagging(null);
            await load();
          }}
        />
      )}
    </div>
  );
}

const FUNDS_LABEL = { yes: "Funded", no: "No funds yet", pending: "Pending" };
const FUNDS_TONE = { yes: "success", no: "danger", pending: "warning" };
const ATM_NOTES = [
  "For ATM application",
  "Pending bank processing",
  "ATM released, not yet activated",
  "Lost ATM, replacement requested",
];

/* ---------- ATM questions, used when tagging and when editing ---------- */

function AtmFields({ value, onChange }) {
  // "Other": a typed status (an empty one means "Other" was just picked).
  const custom = typeof value.atm_note === "string" && !ATM_NOTES.includes(value.atm_note);

  return (
    <>
      <fieldset className="choice-group">
        <legend>Does the student have an ATM card for the stipend?</legend>

        <label className="choice">
          <input
            type="radio"
            name="atm"
            checked={value.has_atm}
            onChange={() => onChange({ ...value, has_atm: true, atm_funds: value.atm_funds || "pending" })}
          />
          Yes, has an ATM card
        </label>

        <label className="choice">
          <input
            type="radio"
            name="atm"
            checked={!value.has_atm}
            onChange={() => onChange({ ...value, has_atm: false, atm_note: value.atm_note || ATM_NOTES[0] })}
          />
          No ATM card yet
        </label>
      </fieldset>

      {value.has_atm ? (
        <fieldset className="choice-group">
          <legend>Has the stipend reached the ATM?</legend>
          {Object.entries(FUNDS_LABEL).map(([key, label]) => (
            <label className="choice" key={key}>
              <input
                type="radio"
                name="atm-funds"
                checked={value.atm_funds === key}
                onChange={() => onChange({ ...value, atm_funds: key })}
              />
              {label}
            </label>
          ))}
        </fieldset>
      ) : (
        <div>
          <label htmlFor="atm-note">Status (no ATM yet)</label>
          <select
            id="atm-note"
            value={custom ? "other" : value.atm_note}
            onChange={(event) =>
              onChange({ ...value, atm_note: event.target.value === "other" ? "" : event.target.value })
            }
          >
            {ATM_NOTES.map((note) => (
              <option key={note} value={note}>
                {note}
              </option>
            ))}
            <option value="other">Other (type below)</option>
          </select>

          {custom && (
            <>
              <label htmlFor="atm-note-other" className="sr-only">
                Other ATM status
              </label>
              <input
                id="atm-note-other"
                value={value.atm_note}
                onChange={(event) => onChange({ ...value, atm_note: event.target.value })}
                maxLength={100}
                placeholder="Type the status"
              />
            </>
          )}
        </div>
      )}

      <p className="muted small">Status tracking only. The system does not connect to any bank.</p>
    </>
  );
}

function AtmModal({ record, onClose, onDone }) {
  const toast = useToast();
  const [value, setValue] = useState({
    has_atm: Boolean(record.has_atm),
    atm_funds: record.atm_funds || "pending",
    atm_note: record.atm_note || ATM_NOTES[0],
  });
  const [saving, setSaving] = useState(false);

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    try {
      await api.patch(`/scholar-records/${record.id}`, {
        has_atm: value.has_atm,
        atm_funds: value.has_atm ? value.atm_funds : null,
        atm_note: value.has_atm ? null : value.atm_note.trim() || ATM_NOTES[0],
      });
      toast.success("ATM status saved.");
      await onDone();
    } catch (err) {
      toast.error(errMsg(err, "Unable to save the ATM status."));
      setSaving(false);
    }
  }

  return (
    <Modal title="ATM status" onClose={onClose}>
      <form onSubmit={save}>
        <p>
          <strong>{fullName(record.student)}</strong> · {record.student?.student_id}
          <br />
          <span className="muted">{record.scholarship?.name}</span>
        </p>

        <AtmFields value={value} onChange={setValue} />

        <div className="modal-footer">
          <button type="button" className="button button-secondary" onClick={onClose}>
            Cancel
          </button>
          <button className="button button-primary" disabled={saving}>
            {saving ? "Saving..." : "Save"}
          </button>
        </div>
      </form>
    </Modal>
  );
}

/* ---------- Tag grantee form ---------- */

function TagGranteeModal({ application, onClose, onDone }) {
  const toast = useToast();

  const [atm, setAtm] = useState({ has_atm: false, atm_funds: "pending", atm_note: ATM_NOTES[0] });
  const [remarks, setRemarks] = useState("");
  const [saving, setSaving] = useState(false);

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    try {
      await api.post(`/applications/${application.id}/scholar-record`, {
        application_id: application.id,
        currently_enrolled: true,
        has_atm: atm.has_atm,
        atm_funds: atm.has_atm ? atm.atm_funds : null,
        atm_note: atm.has_atm ? null : atm.atm_note.trim() || ATM_NOTES[0],
        remarks: remarks.trim() || null,
      });

      toast.success(`${fullName(application.student)} is now tagged as a grantee.`);
      await onDone();
    } catch (err) {
      toast.error(errMsg(err, "Unable to tag grantee."));
      setSaving(false);
    }
  }

  return (
    <Modal title="Tag as Grantee" onClose={onClose}>
      <form onSubmit={save}>
        <p>
          <strong>{fullName(application.student)}</strong> · {application.student?.student_id}
          <br />
          <span className="muted">
            {application.scholarship?.name} · {enrollmentText(application)}
          </span>
        </p>

        <AtmFields value={atm} onChange={setAtm} />

        <label htmlFor="tag-remarks">Remarks (optional)</label>

        <textarea id="tag-remarks" value={remarks} onChange={(event) => setRemarks(event.target.value)} />

        <div className="modal-footer">
          <button type="button" className="button button-secondary" onClick={onClose}>
            Cancel
          </button>

          <button className="button button-primary" disabled={saving}>
            {saving ? "Saving..." : "Tag as grantee"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
