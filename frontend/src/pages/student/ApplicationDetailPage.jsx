import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useLocation, useParams } from "react-router-dom";
import api, { errMsg, isCancel } from "../../services/api";
import {
  STATUS_HELP,
  availabilityInfo,
  enrollmentText,
  fileUrl,
  formatDate,
  missingRequirements,
  studentDocumentState,
} from "../../lib/format";
import { groupDocuments, latestUsable, loadMyDocuments } from "../../lib/documents";
import { useToast } from "../../lib/toast";
import { needsAiCheck, useAiChecks } from "../../lib/aiChecks";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import StatusTimeline from "../../components/StatusTimeline";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import FileDrop from "../../components/FileDrop";

function validateDocumentUpload(file) {
  if (!file) return "Choose a file first.";
  if (file.size <= 0) return "The selected file is empty.";
  if (file.size > 10 * 1024 * 1024) return "The file is larger than 10 MB. Choose a smaller PDF or image.";
  if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) return "Choose a PDF, JPG, JPEG or PNG file.";
  return "";
}

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
  const uploadControl = useRef(null); // AbortController of the upload in progress ("Cancel upload")
  const stopUploads = useRef(false); // "Cancel upload" also stops the rest of "Upload all chosen files"
  const [submitting, setSubmitting] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [saved, setSaved] = useState([]); // My Documents, grouped by type

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      Promise.all([api.get(`/applications/${id}`), loadMyDocuments().catch(() => null)])
        .then(([response, mine]) => {
          setApplication(response.data);
          setSaved(mine ? groupDocuments(mine) : []);
        })
        .catch((err) => setError(errMsg(err, "Unable to load application."))),
    [id]
  );

  useEffect(() => {
    load();
  }, [load]);

  // One "Checking..." state per document; checks running in the AI worker are followed until they finish.
  const ai = useAiChecks({ toast, onChange: load, confirm });
  const { resume } = ai;
  useEffect(() => {
    resume(application?.documents);
  }, [application, resume]);

  // Sends one chosen file. quiet = part of "Upload all": no reload / message per file.
  async function sendFile(requirement, file, quiet = false) {
    const fileError = validateDocumentUpload(file);
    if (fileError) {
      toast.error(`${requirement.name}: ${fileError}`);
      return false;
    }

    const formData = new FormData();
    formData.append("file", file);
    formData.append("scholarship_requirement_id", requirement.id);
    setUploadingId(requirement.id);
    const controller = new AbortController();
    uploadControl.current = controller;

    try {
      await api.post(`/applications/${id}/documents`, formData, {
        headers: { "Content-Type": "multipart/form-data" },
        signal: controller.signal,
      });
      setFiles((current) => ({ ...current, [requirement.id]: null }));
      setInputKeys((current) => ({ ...current, [requirement.id]: (current[requirement.id] || 0) + 1 }));
      if (!quiet) {
        await load();
        toast.success(`${requirement.name} uploaded.`);
      }
      return true;
    } catch (err) {
      if (isCancel(err)) {
        toast.info(`Upload of ${requirement.name} cancelled. The file is still chosen; press Upload to try again.`);
        load(); // if the file had already fully arrived, the server may have kept it: show the real state
        return false;
      }
      toast.error(`${requirement.name}: ${errMsg(err, "Document upload failed.")}`);
      return false;
    } finally {
      uploadControl.current = null;
      setUploadingId(null);
    }
  }

  // "Cancel upload": stops the file being sent now (and the rest of "Upload all chosen files").
  function cancelUpload() {
    stopUploads.current = true;
    uploadControl.current?.abort();
  }

  // "Remove": un-choose a file that was not uploaded yet.
  function clearChosen(requirement) {
    setFiles((current) => ({ ...current, [requirement.id]: null }));
    setInputKeys((current) => ({ ...current, [requirement.id]: (current[requirement.id] || 0) + 1 }));
  }

  // "Delete": remove an uploaded file from this application (only while it can still be changed).
  async function deleteDocument(requirement, document) {
    const ok = await confirm({
      title: `Delete ${document.original_filename}?`,
      message: `It is removed from "${requirement.name}" and from My Documents. You can upload another file after.`,
      confirmLabel: "Delete file",
      tone: "danger",
    });
    if (!ok) return;
    try {
      if (ai.isRunning(document.id)) await ai.cancel(document.id, true);
      const { data } = await api.delete(`/student/documents/${document.id}`);
      toast.success(data.message);
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to delete the file."));
    }
  }

  async function upload(requirement) {
    const file = files[requirement.id];
    if (!file) {
      toast.error(`Choose a file for "${requirement.name}" first.`);
      return;
    }
    await sendFile(requirement, file);
  }

  // "Upload all documents" when files were already chosen on the rows: uploads every chosen file in
  // one press (no need to press Upload on each row).
  async function uploadChosen() {
    const todo = (application?.scholarship?.requirements || []).filter((r) => files[r.id]);
    let done = 0;
    stopUploads.current = false;
    for (const requirement of todo) {
      if (stopUploads.current) break;
      if (await sendFile(requirement, files[requirement.id], true)) done++;
    }
    await load();
    if (stopUploads.current) toast.info(`${done} uploaded before you cancelled. The others are still chosen on their rows.`);
    else if (done === todo.length) toast.success(`${done} document${done === 1 ? "" : "s"} uploaded. Press AI check all to check them.`);
    else toast.error(`${done} of ${todo.length} uploaded. The others are still chosen on their rows; fix them and press Upload all again.`);
  }

  // Attach a file from My Documents instead of uploading it again.
  async function attachSaved(requirement, file, quiet = false) {
    setUploadingId(requirement.id);

    try {
      await api.post(`/applications/${id}/documents/reuse`, {
        document_id: file.id,
        scholarship_requirement_id: requirement.id,
      });
      if (!quiet) {
        await load();
        toast.success(`Saved file used for ${requirement.name}.`);
      }
      return true;
    } catch (err) {
      toast.error(errMsg(err, "Unable to use the saved file."));
      return false;
    } finally {
      setUploadingId(null);
    }
  }

  async function attachAllSaved(pairs) {
    let used = 0;
    for (const [requirement, file] of pairs) {
      if (await attachSaved(requirement, file, true)) used++;
    }
    await load();
    toast.success(`${used} saved document${used === 1 ? "" : "s"} added from My Documents.`);
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
  const chosenCount = requirements.filter((r) => files[r.id]).length;
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

  // Saved files (My Documents) that could fill a requirement, if different from what is attached now.
  const savedFor = (requirement) => {
    const file = latestUsable(saved, requirement.name);
    const attached = documentFor(requirement);
    return file && file.file_path !== attached?.file_path ? file : null;
  };
  // "Use my saved documents" fills empty rows, but skips files the AI flagged:
  // those stay as a separate "Use saved file" choice with a warning.
  const fillable = editable
    ? requirements
        .filter((r) => !documentFor(r) && savedFor(r) && savedFor(r).status !== "flagged")
        .map((r) => [r, savedFor(r)])
    : [];
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
          <div className="button-row">
            <span className="muted">
              {uploadedCount} of {required.length} uploaded
            </span>
            {editable && (application.documents || []).some(needsAiCheck) && (
              <button
                type="button"
                className="button button-small button-secondary"
                onClick={() => ai.startAll(application.documents)}
                disabled={(application.documents || []).some((d) => ai.isRunning(d.id))}
                title="Checks only the files that were not checked yet"
              >
                AI check all ({(application.documents || []).filter(needsAiCheck).length})
              </button>
            )}
            {editable && ai.anyRunning && (
              <button type="button" className="button button-small button-danger-outline" onClick={() => ai.cancelAll()}>
                Cancel AI checks
              </button>
            )}
            {editable && (
              <button aria-busy={uploadingId !== null}
                type="button"
                className="button button-small button-primary"
                onClick={() => (chosenCount > 0 ? uploadChosen() : setBulkOpen((open) => !open))}
                disabled={uploadingId !== null}
                aria-expanded={chosenCount > 0 ? undefined : bulkOpen}
                title={chosenCount > 0 ? "Uploads every file you chose on the rows below" : "Choose all your files at once"}
              >
                {uploadingId !== null ? "Uploading..." : chosenCount > 0 ? `Upload all chosen files (${chosenCount})` : "Upload all documents"}
              </button>
            )}
            {editable && uploadingId !== null && (
              <button type="button" className="button button-small button-danger-outline" onClick={cancelUpload}>
                Cancel upload
              </button>
            )}
          </div>
        </div>

        {editable && bulkOpen && (
          <UploadAll
            applicationId={id}
            requirements={requirements}
            documentFor={documentFor}
            onDone={async () => {
              await load();
            }}
            onClose={() => setBulkOpen(false)}
          />
        )}

        {fillable.length > 0 && (
          <div className="saved-banner">
            <span>
              You already have {fillable.length} of these documents in{" "}
              <Link to="/student/documents">My Documents</Link>.
            </span>
            <button
              type="button"
              className="button button-small button-primary"
              onClick={() => attachAllSaved(fillable)}
              disabled={uploadingId !== null}
            >
              Use my saved documents
            </button>
          </div>
        )}

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

                  {document ? (
                    <>
                      <a className="small" href={fileUrl(document)} target="_blank" rel="noreferrer">
                        {document.original_filename}
                      </a>
                      <span className="muted small">
                        Document type: {document.document_type || requirement.name}
                        {" · "}Uploaded {formatDate(document.created_at)}
                      </span>
                      <AiFindings document={document} />
                    </>
                  ) : (
                    <span className="muted small">
                      {requirement.description || "Not uploaded yet"}
                    </span>
                  )}
                </div>

                <span className={`status status-${state.tone}`}>{state.label}</span>

                {editable && (
                  <FileDrop className="checklist-upload" hint={files[requirement.id] ? `Chosen: ${files[requirement.id].name}` : "or drag a file here"}>
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

                    {uploadingId === requirement.id && (
                      <button type="button" className="button button-small button-danger-outline" onClick={cancelUpload}>
                        Cancel upload
                      </button>
                    )}

                    {files[requirement.id] && uploadingId !== requirement.id && (
                      <button type="button" className="button button-small button-secondary" onClick={() => clearChosen(requirement)}>
                        Remove chosen file
                      </button>
                    )}

                    {document && ["uploaded", "needs_review", "flagged", "processing"].includes(document.status) && (
                      <button
                        type="button"
                        className="button button-small button-secondary"
                        onClick={() => ai.start(document.id)}
                        disabled={uploadingId !== null || ai.isRunning(document.id) || document.status === "processing"}
                      >
                        {ai.isRunning(document.id) || document.status === "processing"
                          ? "Checking..."
                          : document.status === "needs_review"
                            ? "Retry AI check"
                            : document.status === "flagged"
                              ? "Re-run AI check"
                              : "Run AI check"}
                      </button>
                    )}

                    {document && (ai.isRunning(document.id) || document.status === "processing") && (
                      <button type="button" className="button button-small button-danger-outline" onClick={() => ai.cancel(document.id)}>
                        Cancel check
                      </button>
                    )}

                    {document && uploadingId === null && (
                      <button type="button" className="button button-small button-secondary" onClick={() => deleteDocument(requirement, document)}>
                        Delete file
                      </button>
                    )}

                    {savedFor(requirement) && (
                      <button
                        type="button"
                        className="button button-small button-secondary"
                        onClick={() => attachSaved(requirement, savedFor(requirement))}
                        disabled={uploadingId !== null}
                        title={`${savedFor(requirement).original_filename}, uploaded ${formatDate(savedFor(requirement).uploaded_at)}`}
                      >
                        Use saved file
                      </button>
                    )}

                    {savedFor(requirement)?.status === "flagged" && (
                      <span className="small ai-warning">⚠ The AI flagged this saved file. Check it in My Documents first.</span>
                    )}
                  </FileDrop>
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

            <button aria-busy={submitting}
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


// What the AI check found, in the student's words. The AI only points things out; OAS decides.
function AiFindings({ document }) {
  const result = document.validation_result;
  if (document.status === "processing") {
    return <span className="ai-note muted small">The AI is reading this file. You can keep working; the result appears here.</span>;
  }
  if (!result) return null;
  const flags = String(result.flags || "").split("\n").filter(Boolean);
  if (document.status === "validated") {
    return <span className="ai-note ok small">✓ AI check: name and document type found, no issues. OAS still reviews it.</span>;
  }
  if (document.status === "needs_review") {
    return (
      <span className="ai-note review small">
        The AI could not read this file well (blank, blurry or locked). Upload a clearer copy, or OAS will check it by hand.
      </span>
    );
  }
  if (!flags.length) return null;
  return (
    <div className="ai-note flagged small">
      <strong>AI check: please look at this</strong>
      <ul>
        {flags.map((flag) => (
          <li key={flag}>{flag}</li>
        ))}
      </ul>
      <span className="muted">If the file is wrong or old, press Replace. The AI only points things out; OAS makes the decision.</span>
    </div>
  );
}


// Guess which requirement a file is for from its name ("birth certificate.pdf" -> Birth Certificate).
const NAME_HINTS = [
  [/birth|psa|nso/i, /birth/i],
  [/indigen/i, /indigen/i],
  [/good.?moral|moral/i, /moral/i],
  [/itr|tax|bir|2316|income/i, /tax|itr|income/i],
  [/\bcor\b|registration|enrol/i, /registration|\bcor\b/i],
  [/grade|cog|tor|transcript/i, /grade|transcript/i],
  [/\bid\b|valid|philid|national/i, /valid id|\bid\b/i],
  [/barangay|clearance|brgy/i, /barangay|clearance/i],
  [/photo|1x1|2x2|picture/i, /photo|picture/i],
];

function guessRequirement(fileName, requirements, taken) {
  for (const [fileRule, requirementRule] of NAME_HINTS) {
    if (!fileRule.test(fileName)) continue;
    const match = requirements.find((r) => requirementRule.test(r.name) && !taken.includes(r.id));
    if (match) return String(match.id);
  }
  return "";
}

/*
  "Upload all documents": choose several files at once; each is matched to a document by its file name
  (the student checks or changes every match), then all are uploaded one after another with their own
  status. The upload and Replace buttons on each row still work as before.
*/
function UploadAll({ applicationId, requirements, documentFor, onDone, onClose }) {
  const toast = useToast();
  const [items, setItems] = useState([]); // {key, file, requirementId, state, message}
  const [running, setRunning] = useState(false);
  const control = useRef(null); // the upload in progress
  const stopped = useRef(false); // "Cancel" pressed: the rest is not sent

  function choose(event) {
    const files = Array.from(event.target.files || []);
    const taken = [];
    setItems(
      files.map((file, index) => {
        const requirementId = guessRequirement(file.name, requirements, taken);
        if (requirementId) taken.push(Number(requirementId));
        return { key: `${file.name}-${index}`, file, requirementId, state: "ready", message: validateDocumentUpload(file) };
      })
    );
  }

  const setItem = (key, change) => setItems((old) => old.map((item) => (item.key === key ? { ...item, ...change } : item)));
  const chosenIds = items.map((item) => item.requirementId).filter(Boolean);
  const duplicates = chosenIds.filter((value, index) => chosenIds.indexOf(value) !== index);
  const ready = items.filter((item) => item.requirementId && !item.message && item.state !== "done");

  async function uploadAll() {
    setRunning(true);
    stopped.current = false;
    let done = 0;
    for (const item of ready) {
      if (stopped.current) break;
      setItem(item.key, { state: "uploading" });
      const controller = new AbortController();
      control.current = controller;
      const formData = new FormData();
      formData.append("file", item.file);
      formData.append("scholarship_requirement_id", item.requirementId);
      try {
        await api.post(`/applications/${applicationId}/documents`, formData, {
          headers: { "Content-Type": "multipart/form-data" },
          signal: controller.signal,
        });
        setItem(item.key, { state: "done" });
        done++;
      } catch (err) {
        setItem(item.key, isCancel(err) ? { state: "ready" } : { state: "failed", message: errMsg(err, "Upload failed.") });
      }
    }
    control.current = null;
    setRunning(false);
    await onDone();
    const failed = ready.length - done;
    if (stopped.current) toast.info(`${done} uploaded before you cancelled. The others were not sent; press Upload again to send them.`);
    else if (failed) toast.error(`${done} uploaded, ${failed} failed. See the list; you can try the failed ones again.`);
    else toast.success(`${done} document${done === 1 ? "" : "s"} uploaded. Press Run AI check on each to check them.`);
  }

  return (
    <div className="upload-all">
      <label htmlFor="upload-all-files">
        <strong>Choose all your files at once</strong> (PDF, JPG or PNG, up to 10 MB each)
      </label>
      <FileDrop className="file-drop-big" hint="or drag all your files here at once">
        <input id="upload-all-files" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" onChange={choose} disabled={running} />
      </FileDrop>

      {items.length > 0 && (
        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>File</th>
                <th>Upload as</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => {
                const requirement = requirements.find((r) => String(r.id) === item.requirementId);
                const replacing = requirement && documentFor(requirement);
                return (
                  <tr key={item.key}>
                    <td>{item.file.name}</td>
                    <td>
                      <select
                        aria-label={`Document for ${item.file.name}`}
                        value={item.requirementId}
                        disabled={running || item.state === "done"}
                        onChange={(event) => setItem(item.key, { requirementId: event.target.value, state: "ready" })}
                      >
                        <option value="">Do not upload</option>
                        {requirements.map((r) => (
                          <option key={r.id} value={String(r.id)}>
                            {r.name}
                          </option>
                        ))}
                      </select>
                      {replacing && item.state !== "done" && <small className="muted account-note">Replaces the file already uploaded</small>}
                    </td>
                    <td>
                      {item.message && item.state !== "failed" ? (
                        <span className="text-warning small">{item.message}</span>
                      ) : item.state === "uploading" ? (
                        <span className="small"><span className="spinner" aria-hidden="true" /> Uploading...</span>
                      ) : item.state === "done" ? (
                        <span className="status status-success">Uploaded</span>
                      ) : item.state === "failed" ? (
                        <span className="text-warning small">Failed: {item.message}</span>
                      ) : !item.requirementId ? (
                        <span className="muted small">Choose what it is</span>
                      ) : (
                        <span className="muted small">Ready</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {duplicates.length > 0 && <p className="small warning">Two files are set to the same document. Choose a different one for one of them.</p>}

      <div className="button-row">
        <button
          type="button"
          className="button button-primary"
          onClick={uploadAll}
          disabled={running || !ready.length || duplicates.length > 0}
        >
          {running ? "Uploading..." : `Upload ${ready.length} file${ready.length === 1 ? "" : "s"}`}
        </button>
        {running ? (
          <button
            type="button"
            className="button button-danger-outline"
            onClick={() => {
              stopped.current = true;
              control.current?.abort();
            }}
          >
            Cancel upload
          </button>
        ) : (
          <button type="button" className="button button-secondary" onClick={onClose}>
            Close
          </button>
        )}
      </div>
    </div>
  );
}
