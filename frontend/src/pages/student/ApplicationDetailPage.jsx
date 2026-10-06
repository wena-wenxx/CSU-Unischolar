import { useCallback, useEffect, useState } from "react";
import { Link, useLocation, useParams } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import {
  STATUS_HELP,
  availabilityInfo,
  enrollmentText,
  formatDate,
  missingRequirements,
  studentDocumentState,
} from "../../lib/format";
import { useToast } from "../../components/Toast";
import { useConfirm } from "../../components/Modal";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import StatusTimeline from "../../components/StatusTimeline";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

/*
  One application:
  1. a checklist with one row per required document (upload each one),
  2. a progress bar and a Submit button that is grey until every required
     document is uploaded, then turns green,
  3. the step-by-step timeline and "what happens next".
*/
export default function ApplicationDetailPage() {
  const { id } = useParams();
  const location = useLocation();
  const toast = useToast();
  const confirm = useConfirm();

  const [application, setApplication] = useState(null);
  const [error, setError] = useState("");
  const [files, setFiles] = useState({}); // requirement id -> chosen File
  const [inputKeys, setInputKeys] = useState({}); // to clear a file input after upload
  const [uploadingId, setUploadingId] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const load = useCallback(async () => {
    try {
      const response = await api.get(`/applications/${id}`);
      setApplication(response.data);
    } catch (err) {
      setError(errMsg(err, "Unable to load application."));
    }
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  async function upload(requirement) {
    const file = files[requirement.id];

    if (!file) {
      toast.error(`Choose a file for "${requirement.name}" first.`);
      return;
    }

    const formData = new FormData();
    formData.append("file", file);
    formData.append("scholarship_requirement_id", requirement.id);

    setUploadingId(requirement.id);

    try {
      await api.post(`/applications/${id}/documents`, formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      setFiles((current) => ({ ...current, [requirement.id]: null }));
      setInputKeys((current) => ({ ...current, [requirement.id]: (current[requirement.id] || 0) + 1 }));

      await load();
      toast.success(`${requirement.name} uploaded.`);
    } catch (err) {
      toast.error(errMsg(err, "Document upload failed."));
    } finally {
      setUploadingId(null);
    }
  }

  async function submitApplication() {
    const ok = await confirm({
      title: "Submit application?",
      message:
        "Your application and documents will be sent to OAS. You cannot change your documents unless OAS asks you to.",
      confirmLabel: "Submit application",
    });

    if (!ok) return;

    setSubmitting(true);

    try {
      await api.post(`/applications/${id}/submit`);
      await load();
      toast.success("Application submitted to OAS.");
    } catch (err) {
      toast.error(errMsg(err, "Unable to submit application."));
    } finally {
      setSubmitting(false);
    }
  }

  const back = { to: "/student/applications", label: "My applications" };

  if (error) {
    return (
      <div className="card">
        <EmptyState
          message={error}
          action={<Link className="button button-secondary" to="/student/applications">My applications</Link>}
        />
      </div>
    );
  }

  if (!application) return <Loading />;

  const scholarship = application.scholarship || {};
  const requirements = scholarship.requirements || [];
  const required = requirements.filter((r) => r.is_required);
  const missing = missingRequirements(application);
  const uploadedCount = required.length - missing.length;
  const percent = required.length ? Math.round((uploadedCount / required.length) * 100) : 100;

  const editable = ["draft", "needs_action"].includes(application.status);
  const deadlinePassed = application.status === "draft" && scholarship.availability !== "open";
  const canSubmit = editable && missing.length === 0 && !deadlinePassed && !uploadingId && !submitting;

  const documentFor = (requirement) =>
    (application.documents || []).find((d) => d.scholarship_requirement_id === requirement.id);
  const otherFiles = (application.documents || []).filter((d) => !d.scholarship_requirement_id);
  const help = STATUS_HELP[application.status];

  return (
    <div>
      <PageHeader back={back} title={scholarship.name || "Application"} subtitle={<StatusBadge status={application.status} />} />

      {location.state?.justCreated && application.status === "draft" && (
        <div className="alert alert-info">
          Your application was saved as a draft. Upload a file for each required document below, then press{" "}
          <strong>Submit application</strong>.
        </div>
      )}

      {application.status === "needs_action" && application.remarks && (
        <div className="alert alert-warning">
          <strong>OAS says:</strong> {application.remarks}
          <br />
          Upload the corrected document below, then submit again.
        </div>
      )}

      {application.status === "approved" && (
        <div className="alert alert-success">
          Approved. Enrollment: <strong>{enrollmentText(application)}</strong>
        </div>
      )}

      {application.status === "rejected" && application.remarks && (
        <div className="alert alert-danger">
          <strong>Remarks:</strong> {application.remarks}
        </div>
      )}

      {help && !editable && (
        <section className="card next-steps">
          <h2>What happens next?</h2>
          <p>{help.text}</p>
        </section>
      )}

      <section className="card">
        <div className="card-header">
          <h2>Required documents</h2>
          <span className="muted">
            {uploadedCount} of {required.length} uploaded
          </span>
        </div>

        <div
          className="progress"
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={percent}
          aria-label="Required documents uploaded"
        >
          <span style={{ width: `${percent}%` }} />
        </div>

        <ul className="checklist">
          {requirements.map((requirement) => {
            const document = documentFor(requirement);
            const state = studentDocumentState(document);

            return (
              <li key={requirement.id} className={document ? "checklist-item has-file" : "checklist-item"}>
                <span className={document ? "check done" : "check"} aria-hidden="true">
                  {document ? "✓" : ""}
                </span>

                <div className="checklist-text">
                  <strong>
                    {requirement.name}
                    {requirement.is_required && <span className="required">Required</span>}
                  </strong>

                  <span className="muted small">
                    {document ? document.original_filename : requirement.description || "Not uploaded yet"}
                  </span>
                </div>

                <span className={`status status-${state.tone}`}>{state.label}</span>

                {editable && (
                  <div className="checklist-upload">
                    <label className="sr-only" htmlFor={`file-${requirement.id}`}>
                      File for {requirement.name}
                    </label>

                    <input
                      id={`file-${requirement.id}`}
                      key={inputKeys[requirement.id] || 0}
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png"
                      onChange={(event) =>
                        setFiles((current) => ({ ...current, [requirement.id]: event.target.files?.[0] || null }))
                      }
                    />

                    <button
                      type="button"
                      className="button button-small button-secondary"
                      onClick={() => upload(requirement)}
                      disabled={uploadingId !== null || !files[requirement.id]}
                    >
                      {uploadingId === requirement.id ? "Uploading..." : document ? "Replace" : "Upload"}
                    </button>
                  </div>
                )}
              </li>
            );
          })}
        </ul>

        {otherFiles.length > 0 && (
          <p className="muted small">Other files: {otherFiles.map((d) => d.original_filename).join(", ")}</p>
        )}

        {editable && (
          <div className="submit-area">
            {deadlinePassed ? (
              <p className="submit-hint warning">
                The deadline for this scholarship has passed ({availabilityInfo(scholarship).label}). This draft can no
                longer be submitted.
              </p>
            ) : missing.length > 0 ? (
              <p className="submit-hint">
                Upload {missing.length} more required document{missing.length === 1 ? "" : "s"} to submit:{" "}
                {missing.map((requirement) => requirement.name).join(", ")}.
                {scholarship.application_end && application.status === "draft" && (
                  <> Deadline: {formatDate(scholarship.application_end)}.</>
                )}
              </p>
            ) : (
              <p className="submit-hint ready">All required documents are uploaded. You can submit now.</p>
            )}

            <button
              type="button"
              className={canSubmit ? "button button-submit ready" : "button button-submit"}
              onClick={submitApplication}
              disabled={!canSubmit}
            >
              {submitting ? "Submitting..." : application.status === "needs_action" ? "Submit again" : "Submit application"}
            </button>
          </div>
        )}
      </section>

      <section className="card">
        <h2>Application timeline</h2>
        <StatusTimeline application={application} />
      </section>
    </div>
  );
}
