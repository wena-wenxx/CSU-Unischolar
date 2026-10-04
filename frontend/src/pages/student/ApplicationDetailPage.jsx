import { useCallback, useEffect, useState } from "react";
import { Link, useLocation, useParams } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { enrollmentText, missingRequirements } from "../../lib/format";
import { useToast } from "../../components/Toast";
import { useConfirm } from "../../components/Modal";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

/* One application: upload documents, then submit to OAS. */
export default function ApplicationDetailPage() {
  const { id } = useParams();
  const location = useLocation();
  const toast = useToast();
  const confirm = useConfirm();

  const [application, setApplication] = useState(null);
  const [error, setError] = useState("");
  const [file, setFile] = useState(null);
  const [fileInputKey, setFileInputKey] = useState(0);
  const [requirementId, setRequirementId] = useState("");
  const [busy, setBusy] = useState(false);

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

  async function uploadDocument(event) {
    event.preventDefault();

    if (!file || !requirementId) {
      toast.error("Choose a requirement and a file first.");
      return;
    }

    const formData = new FormData();
    formData.append("file", file);
    formData.append("scholarship_requirement_id", requirementId);

    setBusy(true);

    try {
      await api.post(`/applications/${id}/documents`, formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      setFile(null);
      setRequirementId("");
      setFileInputKey((key) => key + 1);

      await load();
      toast.success("Document uploaded.");
    } catch (err) {
      toast.error(errMsg(err, "Document upload failed."));
    } finally {
      setBusy(false);
    }
  }

  async function submitApplication() {
    const ok = await confirm({
      title: "Submit application?",
      message:
        "Your application and documents will be sent to OAS. You cannot upload more documents unless OAS asks you to.",
      confirmLabel: "Submit application",
    });

    if (!ok) return;

    setBusy(true);

    try {
      await api.post(`/applications/${id}/submit`);
      await load();
      toast.success("Application submitted to OAS.");
    } catch (err) {
      toast.error(errMsg(err, "Unable to submit application."));
    } finally {
      setBusy(false);
    }
  }

  const back = (
    <Link className="button button-secondary" to="/student/applications">
      ← My applications
    </Link>
  );

  if (error) {
    return (
      <div className="card">
        <EmptyState message={error} action={back} />
      </div>
    );
  }

  if (!application) return <Loading />;

  const editable = ["draft", "needs_action"].includes(application.status);
  const missing = missingRequirements(application);

  return (
    <div>
      <PageHeader
        title={application.scholarship?.name || "Application"}
        subtitle={<StatusBadge status={application.status} />}
        actions={back}
      />

      {location.state?.justCreated && application.status === "draft" && (
        <div className="alert alert-info">
          Your application was saved as a draft. Upload each required document below, then press{" "}
          <strong>Submit application</strong>.
        </div>
      )}

      {application.status === "needs_action" && application.remarks && (
        <div className="alert alert-warning">
          <strong>OAS says:</strong> {application.remarks}
        </div>
      )}

      {application.status === "approved" && (
        <div className="alert alert-success">
          Approved. Enrollment: <strong>{enrollmentText(application)}</strong>
        </div>
      )}

      <section className="card">
        <h2>Documents</h2>

        {application.documents?.length ? (
          application.documents.map((document) => (
            <div className="document-row" key={document.id}>
              <div>
                <strong>
                  {document.requirement?.name || document.document_type || document.original_filename}
                </strong>

                <p className="muted">{document.original_filename}</p>
              </div>

              <StatusBadge status={document.status} />
            </div>
          ))
        ) : (
          <EmptyState message="No documents uploaded yet." />
        )}
      </section>

      {editable && (
        <section className="card">
          <h2>Upload a Requirement</h2>

          <form onSubmit={uploadDocument}>
            <div className="form-grid">
              <div>
                <label htmlFor="requirement">Requirement</label>

                <select
                  id="requirement"
                  value={requirementId}
                  onChange={(event) => setRequirementId(event.target.value)}
                >
                  <option value="">Select requirement</option>

                  {application.scholarship?.requirements?.map((requirement) => (
                    <option key={requirement.id} value={requirement.id}>
                      {requirement.name}
                      {requirement.is_required ? " (required)" : ""}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label htmlFor="file">File (PDF, JPG or PNG, up to 10 MB)</label>

                <input
                  id="file"
                  key={fileInputKey}
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png"
                  onChange={(event) => setFile(event.target.files?.[0] || null)}
                />
              </div>
            </div>

            <div className="button-row">
              <button className="button button-secondary" disabled={busy}>
                {busy ? "Please wait..." : "Upload document"}
              </button>

              <button
                type="button"
                className="button button-primary"
                onClick={submitApplication}
                disabled={busy || missing.length > 0}
              >
                Submit application
              </button>
            </div>
          </form>

          {missing.length > 0 ? (
            <p className="muted">
              Still needed before you can submit: {missing.map((requirement) => requirement.name).join(", ")}
            </p>
          ) : (
            <p className="muted">All required documents are uploaded. You can submit now.</p>
          )}
        </section>
      )}
    </div>
  );
}
