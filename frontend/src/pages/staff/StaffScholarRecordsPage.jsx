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

export default function StaffScholarRecordsPage() {
  const toast = useToast();
  const confirm = useConfirm();

  const [records, setRecords] = useState([]);
  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [tagging, setTagging] = useState(null);

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      Promise.all([api.get("/scholar-records"), api.get("/applications")])
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

      <section className="card">
        <h2>Current Scholar Records</h2>

        {records.length === 0 ? (
          <EmptyState message="No scholar records yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Scholarship</th>
                  <th>Status</th>
                  <th>Enrolled</th>
                  <th>ATM</th>
                  <th>Tagged</th>
                  <th>Actions</th>
                </tr>
              </thead>

              <tbody>
                {records.map((record) => (
                  <tr key={record.id}>
                    <td>{fullName(record.student)}</td>
                    <td>{record.scholarship?.name}</td>
                    <td>
                      <StatusBadge status={record.status} />
                    </td>
                    <td>{record.currently_enrolled ? "Yes" : "No"}</td>
                    <td>{record.has_atm ? "Yes" : "No"}</td>
                    <td>{formatDate(record.grantee_tagged_at)}</td>
                    <td>
                      <div className="button-row">
                        <button
                          className="button button-small button-secondary"
                          onClick={() => updateRecord(record, { has_atm: !record.has_atm })}
                        >
                          {record.has_atm ? "Mark no ATM" : "Mark has ATM"}
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

/* ---------- Tag grantee form (replaces the old "Does this student have an ATM?" pop-up) ---------- */

function TagGranteeModal({ application, onClose, onDone }) {
  const toast = useToast();

  const [hasAtm, setHasAtm] = useState("no");
  const [remarks, setRemarks] = useState("");
  const [saving, setSaving] = useState(false);

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    try {
      await api.post(`/applications/${application.id}/scholar-record`, {
        application_id: application.id,
        currently_enrolled: true,
        has_atm: hasAtm === "yes",
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

        <fieldset className="choice-group">
          <legend>Does the student already have an ATM card for the stipend?</legend>

          <label className="choice">
            <input
              type="radio"
              name="atm"
              value="yes"
              checked={hasAtm === "yes"}
              onChange={() => setHasAtm("yes")}
            />
            Yes, has an ATM card
          </label>

          <label className="choice">
            <input
              type="radio"
              name="atm"
              value="no"
              checked={hasAtm === "no"}
              onChange={() => setHasAtm("no")}
            />
            No ATM card yet
          </label>
        </fieldset>

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
