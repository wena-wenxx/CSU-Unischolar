import { useCallback, useEffect, useState } from "react";
import api, { errMsg } from "../../services/api";
import { formatDate } from "../../lib/format";
import { useToast } from "../../components/Toast";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";
import StatusBadge from "../../components/StatusBadge";
import Loading from "../../components/Loading";

// Registrar-owned fields a student may ask OAS to correct.
const CORRECTABLE = {
  first_name: "First name",
  middle_name: "Middle name",
  last_name: "Last name",
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

  const load = useCallback(async () => {
    try {
      const [profileResponse, requestsResponse] = await Promise.all([
        api.get("/profile"),
        api.get("/student/change-requests"),
      ]);

      setProfile(profileResponse.data);
      setContact(profileResponse.data.student?.contact_number || "");
      setRequests(requestsResponse.data);
    } catch (err) {
      toast.error(errMsg(err, "Unable to load your profile."));
    }
  }, [toast]);

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

  return (
    <div>
      <PageHeader title="My Profile" subtitle="Your student information" />

      <section className="card">
        <div className="card-header">
          <h2>Student information</h2>
          <span className="lock-note">🔒 From the Registrar · read-only</span>
        </div>

        <div className="profile-grid">
          <ProfileItem label="Student ID" value={student.student_id} />
          <ProfileItem label="First Name" value={student.first_name} />
          <ProfileItem label="Middle Name" value={student.middle_name} />
          <ProfileItem label="Last Name" value={student.last_name} />
          <ProfileItem label="Course" value={student.course} />
          <ProfileItem label="Year Level" value={student.year_level} />
          <ProfileItem label="College" value={student.college} />
          <ProfileItem label="Email (login account)" value={profile.email} />
        </div>

        <p className="muted small">
          These details come from the Registrar and are used to check your documents, so you cannot change them here.
          If something is wrong, use <strong>Request a correction</strong> below.
        </p>
      </section>

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

        <section className="card">
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
