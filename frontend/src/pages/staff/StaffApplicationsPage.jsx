import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { enrollmentText, fileUrl, flaggedCount, formatDate, fullName, missingRequirements, stepLabel, timeAgo } from "../../lib/format";
import { verifySteps } from "../../lib/verifyGuide";
import { byLastName, lastFirst, programTabs } from "../../lib/programs";
import { useToast } from "../../lib/toast";
import Modal from "../../components/Modal";
import { usePaged } from "../../lib/paging";
import ShowMore from "../../components/ShowMore";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";
import SheetTabs from "../../components/SheetTabs";
import StatusBadge from "../../components/StatusBadge";
import StatusTimeline from "../../components/StatusTimeline";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const FILTERS = [
  ["all", "All statuses"],
  ["submitted", "Submitted"],
  ["under_review", "Under review"],
  ["needs_action", "Needs action"],
  ["complete", "Forwarded to agency"],
  ["approved", "Approved"],
  ["to_verify", "Approved, enrollment not verified"],
  ["flagged", "Has AI flags"],
  ["rejected", "Rejected"],
  ["draft", "Drafts"],
];

const SORTS = {
  student: "Grouped by student",
  recent: "Latest activity first",
  name: "Student name (A–Z)",
  submitted_old: "Oldest submitted first (queue)",
  submitted_new: "Newest submitted first",
};

const REFRESH_SECONDS = 20; // checks for changes made by others or by Auto-Review

const time = (value) => (value ? new Date(value).getTime() : 0);
const clock = () => new Date().toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" });

export default function StaffApplicationsPage() {
  const toast = useToast();

  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [updatedAt, setUpdatedAt] = useState("");
  // The address can preselect a list, e.g. /staff/applications?filter=needs_action
  const location = useLocation();
  const [filter, setFilter] = useState(() => {
    const wanted = new URLSearchParams(location.search).get("filter");
    return FILTERS.some(([key]) => key === wanted) ? wanted : "all";
  });
  const [query, setQuery] = useState("");
  const [program, setProgram] = useState("all");
  const [sort, setSort] = useState("student");
  const [selected, setSelected] = useState(null);
  const [verifying, setVerifying] = useState(null);
  const version = useRef(null);

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      api
        .get("/applications")
        .then((response) => {
          setApplications(response.data);
          setUpdatedAt(clock());
        })
        .catch((err) => toast.error(errMsg(err, "Unable to load applications.")))
        .finally(() => setLoading(false)),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  // Stay up to date: every few seconds (and when you come back to this tab)
  // ask the server for a small "version"; reload the list only if it changed.
  useEffect(() => {
    function check() {
      if (document.visibilityState !== "visible") return;
      api
        .get("/staff/applications/version")
        .then(({ data }) => {
          if (version.current !== null && data.version !== version.current) load();
          version.current = data.version;
        })
        .catch(() => {});
    }

    check();
    const timer = window.setInterval(check, REFRESH_SECONDS * 1000);
    window.addEventListener("focus", check);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener("focus", check);
    };
  }, [load]);

  // /staff/applications?review=<id> opens that application's review window
  // (used by Auto-Review). .then() so the state is set when the server answers.
  useEffect(() => {
    const wanted = new URLSearchParams(location.search).get("review");
    if (!wanted) return;
    api
      .get(`/applications/${wanted}`)
      .then((response) => setSelected(response.data))
      .catch((err) => toast.error(errMsg(err, "Unable to load application.")));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function openApplication(id) {
    try {
      const response = await api.get(`/applications/${id}`);
      setSelected(response.data);
    } catch (err) {
      toast.error(errMsg(err, "Unable to load application."));
    }
  }

  // Refresh both the table and the open review window.
  async function refresh(id) {
    await load();
    if (selected?.id === id) await openApplication(id);
  }

  // All programs: one block per student (a student can apply to several programs).
  // A program tab lists its students A to Z (like a sheet in Excel).
  function chooseProgram(key) {
    setProgram(key);
    setSort(key === "all" ? "student" : "name");
  }

  const inFilter = (application, key) => {
    if (key === "all") return true;
    if (key === "to_verify") {
      return application.status === "approved" && !application.enrollment_verified;
    }
    if (key === "flagged") return flaggedCount(application) > 0;
    return application.status === key;
  };

  // Search by student name, student ID or scholarship (every word must match).
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  const matchesSearch = (application) => {
    const haystack = [
      lastFirst(application.student),
      application.student?.student_id,
      application.scholarship?.name,
      application.scholarship?.short_name,
    ]
      .join(" ")
      .toLowerCase();
    return words.every((word) => haystack.includes(word));
  };

  const searched = applications.filter(matchesSearch).filter((application) => inFilter(application, filter));
  const tabs = programTabs(searched, (a) => a.scholarship);
  const activeProgram = tabs.some((tab) => tab.key === program) ? program : "all";

  // Grouped by student: the student with the newest activity first, then their applications (newest first).
  const latestOf = {};
  searched.forEach((a) => {
    latestOf[a.student_id] = Math.max(latestOf[a.student_id] || 0, time(a.updated_at));
  });
  const countOf = {};
  const shown = searched
    .filter((application) => activeProgram === "all" || String(application.scholarship_id) === activeProgram)
    .sort((a, b) => {
      if (sort === "student") {
        return latestOf[b.student_id] - latestOf[a.student_id] || a.student_id - b.student_id || time(b.updated_at) - time(a.updated_at);
      }
      if (sort === "submitted_new") return time(b.submitted_at) - time(a.submitted_at);
      if (sort === "submitted_old") return (time(a.submitted_at) || Infinity) - (time(b.submitted_at) || Infinity);
      if (sort === "name") return byLastName(a.student, b.student);
      return time(b.updated_at) - time(a.updated_at); // the backend's default order
    });
  shown.forEach((a) => {
    countOf[a.student_id] = (countOf[a.student_id] || 0) + 1;
  });
  const grouped = sort === "student";

  // Long lists: 25 rows first, then Show more (resets on another tab, filter or search).
  const page = usePaged(shown, `${activeProgram}|${shown.length}|${shown[0]?.id}`);


  const statusCount = (key) =>
    applications
      .filter(matchesSearch)
      .filter((a) => activeProgram === "all" || String(a.scholarship_id) === activeProgram)
      .filter((a) => inFilter(a, key)).length;

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Applications"
        subtitle="Check documents, forward complete applications, record the agency's decision, and verify enrollment"
        actions={
          <Link className="button button-primary" to="/staff/auto-review">
            Auto-Review
          </Link>
        }
      />

      <div className="card sheet-card">
        <SheetTabs tabs={tabs} value={activeProgram} onChange={chooseProgram} />

        <div className="sheet-toolbar">
          <label htmlFor="application-search" className="sr-only">
            Search applications
          </label>
          <input
            id="application-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search name, Student ID or program"
          />

          <label htmlFor="status-filter" className="sr-only">
            Status
          </label>
          <select id="status-filter" value={filter} onChange={(event) => setFilter(event.target.value)}>
            {FILTERS.map(([key, label]) => (
              <option key={key} value={key}>
                {label} ({statusCount(key)})
              </option>
            ))}
          </select>

          <label htmlFor="sort-applications" className="sr-only">
            Sort
          </label>
          <select id="sort-applications" value={sort} onChange={(event) => setSort(event.target.value)}>
            {Object.entries(SORTS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>

          <span className="sheet-meta muted">
            {shown.length} shown{updatedAt && ` · updated ${updatedAt}`}
          </span>
        </div>

        {shown.length === 0 ? (
          <EmptyState message="No applications in this list." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  {activeProgram === "all" && <th>Program</th>}
                  <th>Submitted</th>
                  <th>Status</th>
                  <th>AI flags</th>
                  <th>Last activity</th>
                  <th>Enrollment</th>
                  <th>Actions</th>
                </tr>
              </thead>

              <tbody>
                {page.visible.map((application, index) => {
                  const sameStudent = grouped && index > 0 && page.visible[index - 1].student_id === application.student_id;
                  return (
                  <tr key={application.id} className={sameStudent ? "group-cont" : grouped ? "group-start" : undefined}>
                    <td>
                      {sameStudent ? (
                        <span className="sr-only">{lastFirst(application.student)}</span>
                      ) : (
                        <>
                          <strong>{lastFirst(application.student)}</strong>
                          <small className="muted account-note">
                            {application.student?.student_id}
                            {grouped && countOf[application.student_id] > 1 && ` · ${countOf[application.student_id]} applications`}
                          </small>
                        </>
                      )}
                    </td>
                    {activeProgram === "all" && (
                      <td className="nowrap" title={application.scholarship?.name}>
                        {application.scholarship?.short_name || application.scholarship?.name}
                      </td>
                    )}
                    <td className="nowrap">{application.submitted_at ? formatDate(application.submitted_at) : "Not submitted"}</td>
                    <td>
                      <StatusBadge status={application.status} />
                    </td>
                    <td>
                      <FlagCell application={application} />
                    </td>
                    <td className="activity-cell">
                      {application.latest_log ? stepLabel(application.latest_log.to_status) : "Updated"}
                      <small className="muted">{timeAgo(application.updated_at)}</small>
                    </td>
                    <td>{enrollmentText(application)}</td>
                    <td>
                      <div className="button-row">
                        <button
                          className="button button-small button-secondary"
                          onClick={() => openApplication(application.id)}
                        >
                          Review
                        </button>

                        {application.status === "approved" &&
                          (application.enrollment_verified ? (
                            <button className="link-button small" onClick={() => setVerifying(application)}>
                              Re-verify
                            </button>
                          ) : (
                            <button className="button button-small button-secondary" onClick={() => setVerifying(application)}>
                              Verify enrollment
                            </button>
                          ))}
                      </div>
                    </td>
                  </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <ShowMore paged={page} />
      </div>

      {selected && (
        <ReviewModal
          application={selected}
          onClose={() => setSelected(null)}
          onChanged={() => refresh(selected.id)}
          onVerify={() => setVerifying(selected)}
        />
      )}

      {verifying && (
        <VerifyEnrollmentModal
          application={verifying}
          onClose={() => setVerifying(null)}
          onDone={async () => {
            const id = verifying.id;
            setVerifying(null);
            await refresh(id);
          }}
        />
      )}
    </div>
  );
}

/* ---------- AI flag column: how many documents the AI flagged ---------- */

function FlagCell({ application }) {
  const flagged = flaggedCount(application);
  if (flagged) return <span className="status status-warning">⚠ {flagged}</span>;
  return <span className="muted">—</span>;
}

/* ---------- Review one application ---------- */

// Buttons for each next status the backend allows (Application::STAFF_NEXT).
const ACTIONS = {
  under_review: { label: "Mark under review", className: "button-secondary" },
  needs_action: { label: "Needs action (send remarks)", className: "button-warning" },
  complete: { label: "Complete: forward to agency", className: "button-primary" },
  approved: { label: "Approved by agency", className: "button-success" },
  rejected: { label: "Rejected by agency", className: "button-danger" },
};

// What staff see after changing the status (plain words, not status codes).
const DONE_MESSAGE = {
  under_review: "Marked as under review.",
  needs_action: "Sent back to the student with your remarks.",
  complete: "Forwarded to the agency. It is now in Forwarded to Agency.",
  approved: "Recorded: approved by the agency. Next, verify the enrollment.",
  rejected: "Recorded: not approved by the agency.",
};

// What staff should do next, for each status.
const NEXT_HINT = {
  draft: "The student has not submitted this application yet. Nothing to review.",
  submitted: "Check each document. Ask for corrections (Needs action) or forward the complete application to the agency.",
  under_review: "Finish checking the documents, then forward the complete application to the agency or ask for corrections.",
  needs_action: "Waiting for the student to upload the corrected documents and submit again.",
  complete: "Forwarded to the agency. When the agency answers, record its decision here (or upload its list in Approved Lists).",
  approved: "Approved by the agency. Next: verify enrollment, then tag the student as a grantee in Scholar Records.",
  rejected: "The agency did not approve this application.",
};

function ReviewModal({ application, onClose, onChanged, onVerify }) {
  const toast = useToast();

  const [remarks, setRemarks] = useState(application.remarks || "");
  const [busy, setBusy] = useState(false);
  const [busyDocumentId, setBusyDocumentId] = useState(null);
  const [verifyNotes, setVerifyNotes] = useState({});

  async function review(status) {
    if (status === "needs_action" && !remarks.trim()) {
      toast.error("Type in Remarks what the student needs to fix, then press Needs action again.");
      document.getElementById("remarks")?.focus();
      return;
    }

    setBusy(true);

    try {
      const { data } = await api.patch(`/applications/${application.id}/review`, {
        status,
        remarks: remarks.trim() || null,
      });

      toast.success(
        status === application.status ? "Remarks saved." : DONE_MESSAGE[status] || `Status changed to "${status.replaceAll("_", " ")}".`
      );

      // Approval e-mail to the student (see Reports → E-mails sent).
      if (data.email?.status === "sent") toast.info(`Approval e-mail sent to ${data.email.to}.`);
      else if (data.email?.status === "queued") toast.info(`Approval e-mail queued for ${data.email.to}.`);
      else if (data.email?.status === "failed") toast.error("The approval e-mail could not be sent. See Reports → E-mails sent.");
      await onChanged();
    } catch (err) {
      toast.error(errMsg(err, "Unable to update application."));
    } finally {
      setBusy(false);
    }
  }

  async function validateDocument(documentId) {
    setBusyDocumentId(documentId);

    try {
      const response = await api.post(`/documents/${documentId}/validate`);
      const status = response.data?.document?.status;

      if (status === "validated") toast.success(response.data.message);
      else toast.info(response.data?.message || "AI check finished.");
    } catch (err) {
      toast.error(errMsg(err, "AI validation failed."));
    } finally {
      setBusyDocumentId(null);
    }

    await onChanged();
  }

  // A person's confirmation that the document is genuine (the AI cannot prove this).
  async function verifyDocument(document, verified) {
    const note = verified ? (verifyNotes[document.id] || "").trim() : "";
    setBusyDocumentId(document.id);
    try {
      const { data } = await api.post(`/documents/${document.id}/verify`, { verified, note: note || null });
      toast.success(data.message);
    } catch (err) {
      toast.error(errMsg(err, "Unable to change the verification."));
    } finally {
      setBusyDocumentId(null);
    }
    await onChanged();
  }

  const missing = application.missing_requirements || missingRequirements(application).map((r) => r.name);
  const next = application.next_statuses || [];
  const decided = ["approved", "rejected"].includes(application.status);
  const student = application.student || {};
  const others = application.other_applications || [];
  const grants = application.scholar_records || [];
  const flagged = (application.documents || []).filter((d) => d.status === "flagged").length;

  return (
    <Modal
      size="large"
      title="Application Review"
      subtitle={<StatusBadge status={application.status} />}
      onClose={onClose}
    >
      <div className="review-next">
        <strong>Next step:</strong> {NEXT_HINT[application.status] || "—"}
      </div>

      <div className="detail-grid">
        <ProfileItem label="Student" value={fullName(student)} />
        <ProfileItem label="Student ID" value={student.student_id} />
        <ProfileItem label="Course" value={student.course} />
        <ProfileItem label="Year Level" value={student.year_level} />
        <ProfileItem label="College" value={student.college} />
        <ProfileItem label="Email" value={student.user?.email} />
        <ProfileItem label="Contact number" value={student.contact_number} />
        <ProfileItem label="Scholarship" value={application.scholarship?.name} />
        <ProfileItem label="Submitted" value={application.submitted_at ? formatDate(application.submitted_at) : "Not submitted"} />
        <ProfileItem label="Enrollment" value={enrollmentText(application)} />
      </div>

      <div className="review-strip">
        <span>
          <strong>Other applications:</strong>{" "}
          {others.length
            ? others.map((o) => `${o.scholarship} (${String(o.status).replaceAll("_", " ")})`).join(" · ")
            : "none"}
        </span>
        <span>
          <strong>Scholarships held:</strong>{" "}
          {grants.length ? grants.map((g) => `${g.scholarship} (${g.status})`).join(" · ") : "none"}
        </span>
        {grants.some((g) => g.status === "active") && (
          <span className="text-danger">⚠ This student already has an active scholarship (one active scholarship only).</span>
        )}
      </div>

      {missing.length > 0 && (
        <div className="alert alert-danger">Missing required documents: {missing.join(", ")}</div>
      )}

      <h3>
        Submitted Documents
        {flagged > 0 && <span className="status status-warning review-flag-count">⚠ {flagged} flagged by the AI</span>}
      </h3>

      {application.documents?.length ? (
        application.documents.map((document) => (
          <div className="document-row" key={document.id}>
            <div>
              <strong>{document.requirement?.name || document.document_type || "Document"}</strong>

              <p className="muted">
                <a href={fileUrl(document)} target="_blank" rel="noreferrer">
                  {document.original_filename}
                </a>
                {" · uploaded "}
                {formatDate(document.created_at)}
                {document.expires_at &&
                  (document.is_expired ? (
                    <span className="text-danger"> · expired {formatDate(document.expires_at)}</span>
                  ) : (
                    <> · valid until {formatDate(document.expires_at)}</>
                  ))}
              </p>

              <AiResult result={document.validation_result} />

              {document.staff_verified_at ? (
                <p className="verified-note">
                  ✓ Verified by {document.staff_verifier?.name || "OAS staff"} on {formatDate(document.staff_verified_at)}
                  {document.staff_verify_note ? ` · ${document.staff_verify_note}` : ""}{" "}
                  <button className="link-button small" onClick={() => verifyDocument(document, false)} disabled={busyDocumentId === document.id}>
                    Undo
                  </button>
                </p>
              ) : (
                <details className="verify-guide">
                  <summary>How to check it is genuine</summary>
                  <ul>
                    {verifySteps(document.requirement?.name || document.document_type).map((step) => (
                      <li key={step}>{step}</li>
                    ))}
                  </ul>
                  <div className="verify-row">
                    <label className="sr-only" htmlFor={`verify-note-${document.id}`}>
                      How you checked it
                    </label>
                    <input
                      id={`verify-note-${document.id}`}
                      value={verifyNotes[document.id] || ""}
                      onChange={(event) => setVerifyNotes({ ...verifyNotes, [document.id]: event.target.value })}
                      maxLength={255}
                      placeholder="How you checked it (e.g. PhilSys Check QR, called the barangay)"
                    />
                    <button
                      className="button button-small button-secondary"
                      onClick={() => verifyDocument(document, true)}
                      disabled={busyDocumentId === document.id}
                    >
                      Mark as verified
                    </button>
                  </div>
                </details>
              )}
            </div>

            <div className="button-row">
              <StatusBadge status={document.status} />

              <button
                className="button button-small button-secondary"
                onClick={() => validateDocument(document.id)}
                disabled={busyDocumentId === document.id}
              >
                {busyDocumentId === document.id
                  ? "Checking..."
                  : document.validation_result
                    ? "Re-run AI check"
                    : "Run AI check"}
              </button>
            </div>
          </div>
        ))
      ) : (
        <EmptyState message="No documents submitted." />
      )}

      {application.status !== "draft" && (
        <>
          <label htmlFor="remarks">Remarks (shown to the student)</label>

          <textarea
            id="remarks"
            value={remarks}
            onChange={(event) => setRemarks(event.target.value)}
            placeholder="Example: Your Certificate of Grades is blurry. Please upload a clearer copy."
          />
        </>
      )}

      <p className="muted small">
        OAS checks the documents and forwards complete applications. The external agency makes the final decision;
        the AI only points things out.
      </p>

      <div className="button-row">
        {next.map((status) => {
          const undo = decided && status === "complete";
          const action = undo
            ? { label: "Undo agency decision", className: "button-secondary" }
            : ACTIONS[status];
          return (
            <button
              key={status}
              className={`button ${action.className}`}
              disabled={busy}
              onClick={() => review(status)}
            >
              {action.label}
            </button>
          );
        })}

        {application.status !== "draft" && (
          <button
            className="button button-secondary"
            disabled={busy || remarks.trim() === (application.remarks || "").trim()}
            onClick={() => review(application.status)}
          >
            Save remarks only
          </button>
        )}

        {application.status === "approved" && (
          <button className="button button-success" onClick={onVerify}>
            Verify enrollment
          </button>
        )}
      </div>

      {!next.includes("complete") && ["submitted", "under_review"].includes(application.status) && missing.length > 0 && (
        <p className="muted small">“Complete: forward to agency” appears when every required document is uploaded.</p>
      )}

      <h3>Application timeline</h3>
      <StatusTimeline application={application} />
    </Modal>
  );
}

/* ---------- Enrollment verification form ---------- */

function VerifyEnrollmentModal({ application, onClose, onDone }) {
  const toast = useToast();

  const [enrolled, setEnrolled] = useState("yes");
  const [remarks, setRemarks] = useState("");
  const [saving, setSaving] = useState(false);

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    try {
      const response = await api.post(`/applications/${application.id}/verify-enrollment`, {
        currently_enrolled: enrolled === "yes",
        remarks: remarks.trim() || undefined,
      });

      if (enrolled === "yes") toast.success(response.data?.message || "Enrollment verified.");
      else toast.info(response.data?.message || "Recorded as not enrolled.");

      await onDone();
    } catch (err) {
      toast.error(errMsg(err, "Unable to verify enrollment."));
      setSaving(false);
    }
  }

  return (
    <Modal title="Verify Enrollment" onClose={onClose}>
      <form onSubmit={save}>
        <p>
          <strong>{fullName(application.student)}</strong> · {application.student?.student_id}
          <br />
          <span className="muted">{application.scholarship?.name}</span>
        </p>

        <fieldset className="choice-group">
          <legend>Is this student currently enrolled this semester?</legend>

          <label className="choice">
            <input
              type="radio"
              name="enrolled"
              value="yes"
              checked={enrolled === "yes"}
              onChange={() => setEnrolled("yes")}
            />
            Yes, currently enrolled
          </label>

          <label className="choice">
            <input
              type="radio"
              name="enrolled"
              value="no"
              checked={enrolled === "no"}
              onChange={() => setEnrolled("no")}
            />
            No, not enrolled
          </label>
        </fieldset>

        <label htmlFor="enrollment-remarks">Remarks (optional)</label>

        <textarea
          id="enrollment-remarks"
          value={remarks}
          onChange={(event) => setRemarks(event.target.value)}
          placeholder="Example: Checked against the Registrar's enrollment list."
        />

        <div className="modal-footer">
          <button type="button" className="button button-secondary" onClick={onClose}>
            Cancel
          </button>

          <button className="button button-primary" disabled={saving}>
            {saving ? "Saving..." : "Save verification"}
          </button>
        </div>
      </form>
    </Modal>
  );
}

/* ---------- What the AI found (it only FLAGS; staff decide) ---------- */

function AiResult({ result }) {
  if (!result) {
    return <p className="muted">AI check: not run yet</p>;
  }

  const flags = String(result.flags || "")
    .split(/\n|;\s*/)
    .map((flag) => flag.trim())
    .filter(Boolean);

  return (
    <div className="ai-result">
      <p className="muted">
        AI check
        {result.confidence_score !== null && result.confidence_score !== undefined
          ? ` · name match score ${Number(result.confidence_score)}`
          : ""}
        {result.extracted_data?.detected_name
          ? ` · name found: ${result.extracted_data.detected_name}`
          : ""}
      </p>

      {flags.length ? (
        <ul>
          {flags.map((flag) => (
            <li key={flag}>{flag}</li>
          ))}
        </ul>
      ) : (
        <p className="ai-ok">No issues flagged.</p>
      )}

      {result.extracted_data?.authenticity_notes?.length > 0 && (
        <div className="caution-box">
          <strong>Possible edit (not proof)</strong>
          <ul>
            {result.extracted_data.authenticity_notes.map((note) => (
              <li key={note}>{note}</li>
            ))}
          </ul>
        </div>
      )}

      {result.extracted_text && (
        <details className="ai-text">
          <summary>Text read by the AI</summary>
          <pre>{result.extracted_text}</pre>
        </details>
      )}
    </div>
  );
}
