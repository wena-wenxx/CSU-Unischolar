import { useCallback, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg, isCancel } from "../../services/api";
import { fileUrl, formatDate, statusLabel } from "../../lib/format";
import {
  checkableRow,
  groupDocuments,
  loadMyDocuments,
} from "../../lib/documents";
import { useToast } from "../../lib/toast";
import { useAiChecks } from "../../lib/aiChecks";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import Loading from "../../components/Loading";
import FileDrop from "../../components/FileDrop";

function validateDocumentUpload(file) {
  if (!file) return "Choose a file first.";
  if (file.size <= 0) return "The selected file is empty.";
  if (file.size > 10 * 1024 * 1024) return "The file is larger than 10 MB. Choose a smaller PDF or image.";
  if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) return "Choose a PDF, JPG, JPEG or PNG file.";
  return "";
}

export default function MyDocumentsPage() {
  const toast = useToast();

  const [data, setData] = useState(null);
  const [files, setFiles] = useState({});
  const [inputKeys, setInputKeys] = useState({});
  const [busy, setBusy] = useState(null);
  const [history, setHistory] = useState({});

  const load = useCallback(
    () =>
      loadMyDocuments()
        .then(setData)
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load your documents."));
          setData({ types: [], documents: [] });
        }),
    [toast],
  );

  useEffect(() => {
    load();
  }, [load]);

  const ai = useAiChecks({ toast, onChange: load });
  const confirm = useConfirm();
  const uploadControl = useRef(null);
  const [uploadingType, setUploadingType] = useState(null);
  const { resume } = ai;
  useEffect(() => {
    resume(data?.documents);
  }, [data, resume]);

  async function save(type, current) {
    const file = files[type];
    if (!file) return;
    const fileError = validateDocumentUpload(file);
    if (fileError) {
      toast.error(fileError);
      return;
    }

    const form = new FormData();
    form.append("file", file);
    if (!current) form.append("document_type", type);

    setBusy(type);
    const controller = new AbortController();
    uploadControl.current = controller;
    setUploadingType(type);

    try {
      const { data: result } = await api.post(
        current
          ? `/student/documents/${current.id}/replace`
          : "/student/documents",
        form,
        { headers: { "Content-Type": "multipart/form-data" }, signal: controller.signal },
      );

      toast.success(result.message);
      setFiles((f) => ({ ...f, [type]: null }));
      setInputKeys((k) => ({ ...k, [type]: (k[type] || 0) + 1 }));
      await load();
    } catch (err) {
      if (isCancel(err)) {
        toast.info("Upload cancelled. The file is still chosen; press Upload to try again.");
        load();
      }
      else toast.error(errMsg(err, "Upload failed."));
    } finally {
      uploadControl.current = null;
      setUploadingType(null);
      setBusy(null);
    }
  }

  function clearChosen(type) {
    setFiles((f) => ({ ...f, [type]: null }));
    setInputKeys((k) => ({ ...k, [type]: (k[type] || 0) + 1 }));
  }

  async function remove(type, current) {
    const ok = await confirm({
      title: `Delete ${current.original_filename}?`,
      message: `It is removed from My Documents (${type}). Applications you already submitted keep the copy the OAS is checking.`,
      confirmLabel: "Delete file",
      tone: "danger",
    });
    if (!ok) return;
    setBusy(type);
    try {
      const { data: result } = await api.delete(`/student/documents/${current.id}`);
      toast.success(result.message);
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to delete the file."));
    } finally {
      setBusy(null);
    }
  }

  if (!data) return <Loading />;

  const groups = groupDocuments(data);
  const savedCount = groups.filter((g) =>
    g.files.some((f) => !f.is_expired),
  ).length;

  return (
    <div>
      <PageHeader
        title="My Documents"
        subtitle={`${savedCount} of ${groups.length} document types saved and still valid`}
        actions={
          <Link className="button button-primary" to="/student/scholarships">
            Apply for a scholarship
          </Link>
        }
      />

      <div className="alert alert-info">
        Files you upload are kept here. When you apply for another scholarship,
        press <strong>Use saved file</strong> on the checklist instead of
        uploading again. Uploading a new copy here also updates your draft
        applications; submitted applications keep the file OAS is already
        checking.
      </div>

      <div className="documents-grid">
        {groups.map(({ type, files: versions }) => {
          const current = versions[0];
          const older = versions.slice(1);
          const row = current ? checkableRow(current) : null;

          return (
            <section
              className={`card doc-card${current?.is_expired ? " expired" : ""}`}
              key={type}
            >
              <div className="doc-card-head">
                <h2>{type}</h2>
                <ValidityBadge file={current} />
              </div>

              {current ? (
                <>
                  <p className="doc-file">
                    <a href={fileUrl(current)} target="_blank" rel="noreferrer">
                      {current.original_filename}
                    </a>
                    <span className="muted small">
                      {" "}
                      · uploaded {formatDate(current.uploaded_at)}
                    </span>
                  </p>

                  <p className="muted small">
                    {current.expires_at
                      ? current.is_expired
                        ? `Expired on ${formatDate(current.expires_at)}. Upload a new copy to use it again.`
                        : `Valid until ${formatDate(current.expires_at)}.`
                      : "This document does not expire."}
                  </p>

                  <AiResult file={current} checkable={Boolean(row)} />

                  {current.applications.length > 0 && (
                    <p className="muted small">
                      Used in:{" "}
                      {current.applications.map((a, i) => (
                        <span key={`${a.id}-${i}`}>
                          {i > 0 && ", "}
                          <Link to={`/student/applications/${a.id}`}>
                            {a.scholarship}
                          </Link>{" "}
                          ({statusLabel(a.status)})
                        </span>
                      ))}
                    </p>
                  )}
                </>
              ) : (
                <p className="muted">Not uploaded yet.</p>
              )}

              <div className="doc-actions">
                <label className="sr-only" htmlFor={`doc-${type}`}>
                  File for {type}
                </label>
                <FileDrop hint="or drag a file here">
                <input
                  id={`doc-${type}`}
                  key={inputKeys[type] || 0}
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png"
                  onChange={(event) =>
                    setFiles((f) => ({
                      ...f,
                      [type]: event.target.files?.[0] || null,
                    }))
                  }
                />
                </FileDrop>

                {files[type] ? (
                  <button
                    type="button"
                    className="button button-small button-primary"
                    onClick={() => save(type, current)}
                    disabled={busy !== null}
                  >
                    {busy === type ? "Uploading..." : current ? "Replace with this file" : "Upload"}
                  </button>
                ) : (
                  <span className="muted small">Choose a file to {current ? "replace this copy" : "upload it"}.</span>
                )}

                {uploadingType === type && (
                  <button type="button" className="button button-small button-danger-outline" onClick={() => uploadControl.current?.abort()}>
                    Cancel upload
                  </button>
                )}
                {files[type] && busy !== type && (
                  <button type="button" className="button button-small button-secondary" onClick={() => clearChosen(type)}>
                    Remove chosen file
                  </button>
                )}

                {current && row && ["uploaded", "needs_review", "flagged", "processing"].includes(current.status) && (
                  <button
                    type="button"
                    className="button button-small button-secondary"
                    onClick={() => ai.start(row.id)}
                    disabled={busy !== null || ai.isRunning(row.id) || row.status === "processing"}
                  >
                    {ai.isRunning(row.id) || row.status === "processing"
                      ? "Checking..."
                      : current.status === "needs_review"
                        ? "Retry AI check"
                        : current.status === "flagged"
                          ? "Re-run AI check"
                          : "Run AI check"}
                  </button>
                )}
                {row && (ai.isRunning(row.id) || row.status === "processing") && (
                  <button type="button" className="button button-small button-danger-outline" onClick={() => ai.cancel(row.id)}>
                    Cancel check
                  </button>
                )}
                {current && !files[type] && busy === null && (
                  <button type="button" className="button button-small button-secondary" onClick={() => remove(type, current)}>
                    Delete file
                  </button>
                )}
              </div>

              {older.length > 0 && (
                <div className="doc-history">
                  <button
                    type="button"
                    className="link-button"
                    onClick={() =>
                      setHistory((h) => ({ ...h, [type]: !h[type] }))
                    }
                    aria-expanded={Boolean(history[type])}
                  >
                    {history[type] ? "Hide" : "Show"} {older.length} older cop
                    {older.length === 1 ? "y" : "ies"}
                  </button>

                  {history[type] && (
                    <ul>
                      {older.map((file) => (
                        <li key={file.file_path}>
                          <a
                            href={fileUrl(file)}
                            target="_blank"
                            rel="noreferrer"
                          >
                            {file.original_filename}
                          </a>{" "}
                          <span className="muted small">
                            {formatDate(file.uploaded_at)}
                            {file.is_expired ? " · expired" : ""}
                            {file.applications.length
                              ? ` · ${file.applications.map((a) => a.scholarship).join(", ")}`
                              : ""}
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </section>
          );
        })}
      </div>

      <p className="muted small">
        How long documents stay valid (from the upload date): COR, grades,
        indigency, barangay clearance, good moral and recommendation letter: 6
        months · income tax return: 1 year · birth certificate and valid ID: no
        expiry. OAS may still ask for a newer copy.
      </p>
    </div>
  );
}

function ValidityBadge({ file }) {
  if (!file) return <span className="status status-neutral">Missing</span>;
  if (file.is_expired)
    return <span className="status status-danger">Expired</span>;
  if (file.status === "flagged")
    return <span className="status status-warning">Needs attention</span>;
  return <span className="status status-success">Valid</span>;
}

function AiResult({ file, checkable }) {
  if (file.status === "processing") {
    return <p className="ai-note muted small"><span className="spinner" aria-hidden="true" /> The AI is reading this file. You can keep working; the result appears here.</p>;
  }

  if (file.status === "validated") {
    const notes = String(file.flags || "").split("\n").filter(Boolean);
    return (
      <div className="ai-note ok">
        ✓ The AI check found no issues (OAS staff still review it).
        {notes.length > 0 && <span className="muted small"> Note: {notes.join(" ")}</span>}
      </div>
    );
  }

  if (file.status === "flagged") {
    return (
      <div className="ai-note flagged">
        <strong>AI check found something to look at:</strong>
        <ul>
          {String(file.flags || "")
            .split("\n")
            .filter(Boolean)
            .map((flag) => (
              <li key={flag}>{flag}</li>
            ))}
        </ul>
        <span className="muted small">
          The AI only points things out; OAS staff make the decision.
        </span>
      </div>
    );
  }

  if (file.status === "needs_review") {
    return (
      <p className="ai-note review">
        The AI could not read this file. OAS will check it by hand.
      </p>
    );
  }

  if (!checkable) {
    return <p className="ai-note muted small">Sent to OAS with your application. The staff will check it.</p>;
  }

  return <p className="ai-note muted small">Not checked by the AI yet. Press “Check with AI” below.</p>;
}
