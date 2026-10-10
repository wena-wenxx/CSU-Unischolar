import { useCallback, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { formatDate } from "../../lib/format";
import { groupDocuments, loadMyDocuments } from "../../lib/documents";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";
import StatusBadge from "../../components/StatusBadge";
import Loading from "../../components/Loading";

// Registrar-owned fields a student may ask OAS to correct.
const CORRECTABLE = {
  first_name: "First name",
  middle_name: "Middle name",
  last_name: "Last name",
  sex: "Sex",
  student_id: "Student ID",
  course: "Course",
  year_level: "Year level",
  college: "College",
};

/*
  My Profile
  - Registrar information (name, Student ID, course, year level, college):
    read-only. A wrong value is fixed through "Request a correction".
  - E-mail: read-only (it is the university login account).
  - Contact number: the student can change it.
*/
export default function StudentProfilePage() {
  const toast = useToast();

  const [profile, setProfile] = useState(null);
  const [requests, setRequests] = useState([]);
  const [contact, setContact] = useState("");
  const [savingContact, setSavingContact] = useState(false);
  const [correction, setCorrection] = useState({ field: "", requested_value: "", reason: "" });
  const [sending, setSending] = useState(false);
  const [documents, setDocuments] = useState([]);
  const correctionRef = useRef(null);

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      Promise.all([api.get("/profile"), api.get("/student/change-requests"), loadMyDocuments().catch(() => null)])
        .then(([profileResponse, requestsResponse, mine]) => {
          setDocuments(mine ? groupDocuments(mine) : []);
          setProfile(profileResponse.data);
          setContact(profileResponse.data.student?.contact_number || "");
          setRequests(requestsResponse.data);
        })
        .catch((err) => toast.error(errMsg(err, "Unable to load your profile."))),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  async function saveContact(event) {
    event.preventDefault();
    setSavingContact(true);

    try {
      await api.patch("/profile", { contact_number: contact.trim() });
      toast.success("Contact number updated.");
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to update the contact number."));
    } finally {
      setSavingContact(false);
    }
  }

  async function sendCorrection(event) {
    event.preventDefault();
    setSending(true);

    try {
      await api.post("/student/change-requests", correction);
      toast.success("Request sent to OAS. They will check it with the Registrar.");
      setCorrection({ field: "", requested_value: "", reason: "" });
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to send the request."));
    } finally {
      setSending(false);
    }
  }

  const student = profile?.student;

  if (!student) {
    return (
      <div>
        <PageHeader title="My Profile" subtitle="Your student information" />
        <div className="card">
          <Loading />
        </div>
      </div>
    );
  }

  const current = (field) => student[field] || "—";

  // "Request a change" next to a locked field: pick it in the form below.
  function requestChange(field) {
    setCorrection({ field, requested_value: "", reason: "" });
    correctionRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    setTimeout(() => document.getElementById("correction-value")?.focus(), 300);
  }

  const pending = (field) => requests.some((r) => r.field === field && r.status === "pending");
  const validTypes = documents.filter((g) => g.files.some((f) => !f.is_expired)).length;
  const expiredTypes = documents.filter((g) => g.files.length && g.files.every((f) => f.is_expired)).length;

  return (
    <div>
      <PageHeader title="My Profile" subtitle="Your student information" />

      <section className="card">
        <div className="card-header">
          <h2>Student information</h2>
          <span className="lock-note">🔒 From the Registrar · read-only</span>
        </div>

        <div className="locked-fields">
          {Object.entries(CORRECTABLE).map(([field, label]) => (
            <div className="locked-field" key={field}>
              <ProfileItem label={label} value={student[field]} />
              {pending(field) ? (
                <span className="muted small">Change requested</span>
              ) : (
                <button type="button" className="link-button small" onClick={() => requestChange(field)}>
                  Request a change
                </button>
              )}
            </div>
          ))}

          <div className="locked-field">
            <ProfileItem label="Email (login account)" value={profile.email} />
            <span className="muted small">Managed by the university</span>
          </div>
        </div>

        <p className="muted small">
          These details come from the Registrar and are used to check your documents, so you cannot change them here.
          If something is wrong, use <strong>Request a correction</strong> below.
        </p>
      </section>

      {profile.active_scholar_record && <StipendCard grant={profile.active_scholar_record} />}

      <div className="dashboard-grid">
        <section className="card">
          <h2>Contact number</h2>
          <p className="muted small">OAS uses this to reach you about your application. You can change it.</p>

          <form className="inline-form" onSubmit={saveContact}>
            <label htmlFor="contact" className="sr-only">
              Contact number
            </label>
            <input
              id="contact"
              inputMode="numeric"
              placeholder="09XXXXXXXXX"
              value={contact}
              onChange={(event) => setContact(event.target.value)}
              maxLength={11}
              required
            />
            <button className="button button-primary" disabled={savingContact || contact === (student.contact_number || "")}>
              {savingContact ? "Saving..." : "Save"}
            </button>
          </form>
        </section>

        <section className="card" ref={correctionRef} id="request-correction">
          <h2>Request a correction</h2>

          <form className="form-grid" onSubmit={sendCorrection}>
            <div>
              <label htmlFor="correction-field">What is wrong?</label>
              <select
                id="correction-field"
                value={correction.field}
                onChange={(event) => setCorrection({ ...correction, field: event.target.value })}
                required
              >
                <option value="">Choose a field</option>
                {Object.entries(CORRECTABLE).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label} (now: {current(key)})
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label htmlFor="correction-value">Correct value</label>
              <input
                id="correction-value"
                value={correction.requested_value}
                onChange={(event) => setCorrection({ ...correction, requested_value: event.target.value })}
                maxLength={255}
                required
              />
            </div>

            <div className="full-column">
              <label htmlFor="correction-reason">Reason or proof (optional)</label>
              <textarea
                id="correction-reason"
                value={correction.reason}
                onChange={(event) => setCorrection({ ...correction, reason: event.target.value })}
                maxLength={1000}
                placeholder="e.g. My COR for this semester shows 3rd Year."
              />
            </div>

            <div>
              <button className="button button-primary" disabled={sending}>
                {sending ? "Sending..." : "Send to OAS"}
              </button>
            </div>
          </form>
        </section>
      </div>

      <section className="card">
        <div className="card-header">
          <h2>My documents</h2>
          <Link className="button button-small button-secondary" to="/student/documents">
            Open My Documents
          </Link>
        </div>

        <p>
          <strong>{validTypes}</strong> of {documents.length} document types saved and valid
          {expiredTypes > 0 && (
            <>
              {" "}
              · <span className="text-danger">{expiredTypes} expired</span>
            </>
          )}
          .
        </p>

        <ul className="doc-chips">
          {documents.map((group) => {
            const latest = group.files[0];
            const state = !latest ? "missing" : latest.is_expired ? "expired" : latest.status === "flagged" ? "flagged" : "ok";
            return (
              <li key={group.type} className={`doc-chip ${state}`}>
                {state === "ok" ? "✓" : state === "missing" ? "○" : "!"} {group.type}
              </li>
            );
          })}
        </ul>
      </section>

      <section className="card">
        <div className="card-header">
          <h2>Password</h2>
          <Link className="button button-small button-secondary" to="/account/password">
            Change password
          </Link>
        </div>
        <p className="muted small">Use at least 8 characters with letters and numbers. Do not share it with anyone.</p>
      </section>

      {requests.length > 0 && (
        <section className="card">
          <h2>My correction requests</h2>

          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Field</th>
                  <th>Requested value</th>
                  <th>Sent</th>
                  <th>Status</th>
                  <th>OAS remarks</th>
                </tr>
              </thead>
              <tbody>
                {requests.map((item) => (
                  <tr key={item.id}>
                    <td>{CORRECTABLE[item.field] || item.field}</td>
                    <td>{item.requested_value}</td>
                    <td>{formatDate(item.created_at)}</td>
                    <td>
                      <StatusBadge status={item.status === "resolved" ? "completed" : "submitted"} label={item.status} />
                    </td>
                    <td>{item.staff_remarks || "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  );
}

// Read-only: OAS keeps the ATM status up to date. No banking is done here.
function StipendCard({ grant }) {
  const paid = (grant.payroll_records || []).filter((p) => p.status === "processed").length;
  const funds = { yes: "The stipend has reached your ATM.", no: "No funds on your ATM yet.", pending: "Funds are being processed." };

  return (
    <section className="card">
      <div className="card-header">
        <h2>My stipend</h2>
        <span className="lock-note">🔒 Updated by OAS · read-only</span>
      </div>

      <div className="detail-grid">
        <ProfileItem label="Scholarship" value={grant.scholarship?.name} />
        <ProfileItem label="Has ATM" value={grant.has_atm ? "Yes" : "No"} />
        <ProfileItem
          label={grant.has_atm ? "ATM funds" : "ATM status"}
          value={grant.has_atm ? funds[grant.atm_funds] || "Not recorded yet" : grant.atm_note || "For ATM application"}
        />
        <ProfileItem label="Stipends released" value={String(paid)} />
      </div>

      <p className="muted small">
        If this is wrong, contact the OAS. The system only records the status; it does not handle money.
      </p>
    </section>
  );
}
