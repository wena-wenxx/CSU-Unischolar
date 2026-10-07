import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { fileUrl, formatDate, statusLabel } from "../../lib/format";
import { checkableRow, groupDocuments, loadMyDocuments } from "../../lib/documents";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import Loading from "../../components/Loading";

/*
  My Documents: every file the student has uploaded, by type.
  - shows whether each file is still valid (not expired),
  - shows the AI check result, with the reasons when something was flagged,
  - lets the student upload or replace a file (drafts are updated too),
  - files saved here can be picked when applying, instead of uploading again.
*/
export default function MyDocumentsPage() {
  const toast = useToast();

  const [data, setData] = useState(null);
  const [files, setFiles] = useState({}); // type -> chosen File
  const [inputKeys, setInputKeys] = useState({});
  const [busy, setBusy] = useState(null); // type or "check-<id>"
  const [history, setHistory] = useState({}); // type -> open?

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      loadMyDocuments()
        .then(setData)
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load your documents."));
          setData({ types: [], documents: [] });
        }),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  async function save(type, current) {
    const file = files[type];
    if (!file) return;

    const form = new FormData();
    form.append("file", file);
    if (!current) form.append("document_type", type);

    setBusy(type);

    try {
      const { data: result } = await api.post(
        current ? `/student/documents/${current.id}/replace` : "/student/documents",
        form,
        { headers: { "Content-Type": "multipart/form-data" } }
      );

      toast.success(result.message);
      setFiles((f) => ({ ...f, [type]: null }));
      setInputKeys((k) => ({ ...k, [type]: (k[type] || 0) + 1 }));
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Upload failed."));
    } finally {
      setBusy(null);
    }
  }

  async function check(row) {
    setBusy(`check-${row.id}`);

    try {
      const { data: result } = await api.post(`/documents/${row.id}/validate`);
      const status = result.document?.status;

      if (status === "validated") toast.success("AI check: no problems found.");
      else if (status === "flagged") toast.info("AI check: something may need fixing. See the reasons below.");
      else toast.info(result.message || "The file could not be read automatically.");

      await load();
    } catch (err) {
      toast.error(errMsg(err, "The AI check could not run. Try again later."));
      await load();
    } finally {
      setBusy(null);
    }
  }

  if (!data) return <Loading />;

  const groups = groupDocuments(data);
  const savedCount = groups.filter((g) => g.files.some((f) => !f.is_expired)).length;

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
        Files you upload are kept here. When you apply for another scholarship, press{" "}
        <strong>Use saved file</strong> on the checklist instead of uploading again. Uploading a new copy here also updates
        your draft applications; submitted applications keep the file OAS is already checking.
      </div>

      <div className="documents-grid">
        {groups.map(({ type, files: versions }) => {
          const current = versions[0];
          const older = versions.slice(1);
          const row = current ? checkableRow(current) : null;

          return (
            <section className={`card doc-card${current?.is_expired ? " expired" : ""}`} key={type}>
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
                    <span className="muted small"> · uploaded {formatDate(current.uploaded_at)}</span>
                  </p>

                  <p className="muted small">
                    {current.expires_at
                      ? current.is_expired
                        ? `Expired on ${formatDate(current.expires_at)}. Upload a new copy to use it again.`
                        : `Valid until ${formatDate(current.expires_at)}.`
                      : "This document does not expire."}
                  </p>

                  <AiResult file={current} />

                  {current.applications.length > 0 && (
                    <p className="muted small">
                      Used in:{" "}
                      {current.applications.map((a, i) => (
                        <span key={`${a.id}-${i}`}>
                          {i > 0 && ", "}
                          <Link to={`/student/applications/${a.id}`}>{a.scholarship}</Link> ({statusLabel(a.status)})
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
                <input
                  id={`doc-${type}`}
                  key={inputKeys[type] || 0}
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png"
                  onChange={(event) => setFiles((f) => ({ ...f, [type]: event.target.files?.[0] || null }))}
                />

                <button
                  type="button"
                  className="button button-small button-primary"
                  onClick={() => save(type, current)}
                  disabled={!files[type] || busy !== null}
                >
                  {busy === type ? "Saving..." : current ? "Replace" : "Upload"}
                </button>

                {current && row && current.status === "uploaded" && (
                  <button
                    type="button"
                    className="button button-small button-secondary"
                    onClick={() => check(row)}
                    disabled={busy !== null}
                  >
                    {busy === `check-${row.id}` ? "Checking..." : "Check with AI"}
                  </button>
                )}
              </div>

              {older.length > 0 && (
                <div className="doc-history">
                  <button
                    type="button"
                    className="link-button"
                    onClick={() => setHistory((h) => ({ ...h, [type]: !h[type] }))}
                    aria-expanded={Boolean(history[type])}
                  >
                    {history[type] ? "Hide" : "Show"} {older.length} older cop{older.length === 1 ? "y" : "ies"}
                  </button>

                  {history[type] && (
                    <ul>
                      {older.map((file) => (
                        <li key={file.file_path}>
                          <a href={fileUrl(file)} target="_blank" rel="noreferrer">
                            {file.original_filename}
                          </a>{" "}
                          <span className="muted small">
                            {formatDate(file.uploaded_at)}
                            {file.is_expired ? " · expired" : ""}
                            {file.applications.length ? ` · ${file.applications.map((a) => a.scholarship).join(", ")}` : ""}
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
        How long documents stay valid (from the upload date): COR, grades, indigency, barangay clearance, good moral and
        recommendation letter: 6 months · income tax return: 1 year · birth certificate and valid ID: no expiry. OAS may
        still ask for a newer copy.
      </p>
    </div>
  );
}

function ValidityBadge({ file }) {
  if (!file) return <span className="status status-neutral">Missing</span>;
  if (file.is_expired) return <span className="status status-danger">Expired</span>;
  if (file.status === "flagged") return <span className="status status-warning">Needs attention</span>;
  return <span className="status status-success">Valid</span>;
}

function AiResult({ file }) {
  if (file.status === "validated") {
    return <p className="ai-note ok">✓ AI check: no problems found.</p>;
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
        <span className="muted small">The AI only points things out; OAS staff make the decision.</span>
      </div>
    );
  }

  if (file.status === "needs_review") {
    return <p className="ai-note review">The AI could not read this file. OAS will check it by hand.</p>;
  }

  return <p className="ai-note muted small">Not checked by the AI yet.</p>;
}
