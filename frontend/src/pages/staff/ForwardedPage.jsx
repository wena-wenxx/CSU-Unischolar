import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { downloadCSV, formatDate } from "../../lib/format";
import { useToast } from "../../lib/toast";
import { useConfirm } from "../../lib/confirm";
import { usePaged } from "../../lib/paging";
import ShowMore from "../../components/ShowMore";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import SheetTabs from "../../components/SheetTabs";
import ExportMenu from "../../components/ExportMenu";
import { downloadFile } from "../../lib/download";
import SearchField from "../../components/SearchField";
import { matchesWords, searchWords } from "../../lib/search";
import { byLastName, lastFirst, programTabs } from "../../lib/programs";
import Modal from "../../components/Modal";

const stamp = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });
const DAY = 24 * 60 * 60 * 1000;

export default function ForwardedPage() {
  const toast = useToast();

  const [rows, setRows] = useState(null);
  const [programId, setProgramId] = useState("all");
  const [query, setQuery] = useState("");
  const [picked, setPicked] = useState([]);
  const [sending, setSending] = useState(null);
  const [programs2, setPrograms2] = useState([]);

  const load = useCallback(
    () =>
      api
        .get("/applications", { params: { status: "complete" } })
        .then(({ data }) => {
          const now = Date.now();
          setRows(
            data
              .filter((a) => a.status === "complete")
              .map((a) => ({
                ...a,
                waiting: a.forwarded_at ? Math.max(0, Math.floor((now - new Date(a.forwarded_at).getTime()) / DAY)) : null,
              }))
              .sort((a, b) => (b.waiting ?? -1) - (a.waiting ?? -1))
          );
        })
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load forwarded applications."));
          setRows([]);
        }),
    [toast]
  );

  useEffect(() => {
    load();
    api.get("/scholarships").then(({ data }) => setPrograms2(data || [])).catch(() => setPrograms2([]));
  }, [load]);

  const list = rows || [];
  const programs = [...new Map(list.map((a) => [a.scholarship_id, a.scholarship])).values()];
  const words = searchWords(query);
  const searched = list.filter((a) => {
    const haystack = `${lastFirst(a.student)} ${a.student?.student_id || ""}`.toLowerCase();
    return matchesWords(haystack, words);
  });
  const tabs = programTabs(searched, (a) => a.scholarship);
  const active = tabs.some((tab) => tab.key === programId) ? programId : "all";
  const shown = searched
    .filter((a) => active === "all" || String(a.scholarship_id) === active)
    .sort((a, b) => byLastName(a.student, b.student));

  const page = usePaged(shown, `${active}|${shown.length}|${shown[0]?.id}`);

  if (!rows) return <Loading />;

  const pickedRows = shown.filter((a) => picked.includes(a.id));
  const allPicked = shown.length > 0 && shown.every((a) => picked.includes(a.id));
  function togglePick(id) {
    setPicked((old) => (old.includes(id) ? old.filter((x) => x !== id) : [...old, id]));
  }
  function toggleAll() {
    setPicked(allPicked ? [] : shown.map((a) => a.id));
  }

  function exportCSV() {
    const program = programs.find((p) => String(p?.id) === active);
    const code = program ? program.name.replace(/[^a-z0-9]+/gi, "-").toLowerCase().replace(/^-|-$/g, "") : "all-programs";
    const ok = downloadCSV(
      `forwarded-${code}-${stamp()}.csv`,
      shown.map((a) => ({
        "Student ID": a.student?.student_id,
        "Last name": a.student?.last_name,
        "First name": a.student?.first_name,
        "Middle name": a.student?.middle_name || "",
        Sex: a.student?.sex || "",
        Course: a.student?.course || "",
        "Year level": a.student?.year_level || "",
        College: a.student?.college || "",
        "Contact number": a.student?.contact_number || "",
        Email: a.student?.user?.email || "",
        Scholarship: a.scholarship?.name,
        Agency: a.scholarship?.provider || "",
        "Forwarded on": a.forwarded_at ? formatDate(a.forwarded_at) : "",
      }))
    );
    if (!ok) toast.info("Nothing to export.");
  }

  async function exportFile(format) {
    const program = programs.find((p) => String(p?.id) === active);
    try {
      const name = await downloadFile(
        "/staff/forwarded/export",
        {
          format,
          ids: shown.map((a) => a.id),
          title: program ? `Forwarded to ${program.provider || "the agency"}: ${program.name}` : null,
        },
        `forwarded.${format}`
      );
      toast.success(`Downloaded ${name}`);
    } catch (err) {
      toast.error(errMsg(err, `Unable to make the ${format === "pdf" ? "PDF" : "Excel"} file.`));
    }
  }

  return (
    <div>
      <PageHeader
        title="Forwarded to Agency"
        subtitle="Complete applications OAS sent to the agencies, waiting for their decision"
        actions={
          <div className="button-row">
            <ExportMenu
              count={shown.length}
              disabled={!shown.length}
              options={[
                { label: "Excel (.xlsx)", hint: "all details, opens in Excel", onClick: () => exportFile("xlsx") },
                { label: "PDF", hint: "to print or e-mail", onClick: () => exportFile("pdf") },
                { label: "CSV", hint: "plain text for any program", onClick: exportCSV },
              ]}
            />
            <Link className="button button-secondary" to="/staff/agency-lists">
              Record an agency's answer
            </Link>
          </div>
        }
      />

      <section className="card sheet-card">
        <SheetTabs tabs={tabs} value={active} onChange={setProgramId} />

        <div className="sheet-toolbar">
          <SearchField id="fw-search" label="Search" value={query} onChange={setQuery} placeholder="Search name or Student ID" />
          <span className="sheet-meta muted">
            {shown.length} shown · A–Z by surname
          </span>
        </div>

        <div className="button-row selection-bar">
          <span className="muted small">{picked.length} selected</span>
          <button
            className="button button-small button-primary"
            disabled={!pickedRows.length}
            onClick={() => setSending("record")}
            title={active === "all" ? "Choose one program tab first" : ""}
          >
            Record as sent to agency
          </button>
          <button className="button button-small button-secondary" disabled={!pickedRows.length} onClick={() => setSending("zip")}>
            Supporting documents (ZIP)
          </button>
          {active === "all" && picked.length > 0 && (
            <span className="muted small">One sending is for one program: open its tab to record it.</span>
          )}
        </div>

        {shown.length === 0 ? (
          <EmptyState
            message="Nothing is waiting for an agency."
            action={
              <Link className="button button-secondary" to="/staff/auto-review">
                Open Auto-Review
              </Link>
            }
          />
        ) : (
          <div className="table-wrapper">
            <table className="numbered">
              <thead>
                <tr>
                  <th>
                    <input type="checkbox" aria-label="Select all shown" checked={allPicked} onChange={toggleAll} />
                  </th>
                  <th>Student</th>
                  <th>Student ID</th>
                  <th>Course / year</th>
                  <th>Scholarship</th>
                  <th>Agency</th>
                  <th>Sent to agency</th>
                  <th className="numeric">Days waiting</th>
                </tr>
              </thead>
              <tbody>
                {page.visible.map((a) => (
                  <tr key={a.id}>
                    <td>
                      <input
                        type="checkbox"
                        aria-label={`Select ${lastFirst(a.student)}`}
                        checked={picked.includes(a.id)}
                        onChange={() => togglePick(a.id)}
                      />
                    </td>
                    <td>
                      <strong className="student-name">{lastFirst(a.student)}</strong>
                    </td>
                    <td>{a.student?.student_id}</td>
                    <td>
                      {a.student?.course}
                      {a.student?.year_level ? ` · ${a.student.year_level}` : ""}
                    </td>
                    <td>{a.scholarship?.name}</td>
                    <td>{a.scholarship?.provider || "—"}</td>
                    <td>
                      {a.agency_submission ? (
                        <>
                          {formatDate(a.agency_submission.sent_on)}
                          {a.agency_submission.sent_by_way && <span className="muted small"> · {a.agency_submission.sent_by_way}</span>}
                        </>
                      ) : (
                        <span className="muted small">
                          Not recorded as sent{a.forwarded_at ? ` (ready since ${formatDate(a.forwarded_at)})` : ""}
                        </span>
                      )}
                    </td>
                    <td className="numeric">
                      {a.waiting === null ? "—" : (
                        <span className={a.waiting > 30 ? "text-warning" : ""}>{a.waiting}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <ShowMore paged={page} />

        <p className="muted small">
          The export is a plain list of the student details OAS keeps, not an agency's official form. Over 30 days waiting
          is shown in orange so OAS can follow up. When you send the list, press <strong>Record as sent to agency</strong>:
          every student in that list then shows the same date.
        </p>
      </section>

      {sending === "record" && (
        <RecordSentModal
          rows={pickedRows}
          onClose={() => setSending(null)}
          onDone={() => {
            setSending(null);
            setPicked([]);
            load();
          }}
        />
      )}
      {sending === "zip" && (
        <DocumentsZipModal rows={pickedRows} programs={programs2} onClose={() => setSending(null)} />
      )}
    </div>
  );
}

function RecordSentModal({ rows, onClose, onDone }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [sentOn, setSentOn] = useState(stamp());
  const [way, setWay] = useState("E-mail");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const programsInList = [...new Set(rows.map((a) => a.scholarship?.name))];

  async function save(confirmResend = false) {
    setBusy(true);
    try {
      const { data } = await api.post("/staff/agency-submissions", {
        ids: rows.map((a) => a.id),
        sent_on: sentOn,
        sent_by_way: way || null,
        note: note.trim() || null,
        confirm_resend: confirmResend,
      });
      toast.success(data.message);
      onDone();
    } catch (err) {
      if (err.response?.status === 409 && !confirmResend) {
        setBusy(false);
        const again = await confirm({
          title: "Already recorded as sent",
          message: err.response.data?.message || "Record them in this new sending?",
          confirmLabel: "Record again",
        });
        if (again) return save(true);
      } else {
        toast.error(errMsg(err, "Unable to record the sending."));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Record as sent to agency"
      subtitle={<p className="muted small">{rows.length} application(s) · {programsInList.join(", ")}</p>}
      onClose={onClose}
      footer={
        <div className="button-row">
          <button className="button button-secondary" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="button button-primary" onClick={() => save()} disabled={busy || !sentOn || programsInList.length !== 1}>
            {busy ? "Saving..." : "Record"}
          </button>
        </div>
      }
    >
      {programsInList.length !== 1 ? (
        <p className="warning small">These students are in more than one program. Open one program tab, then select its students.</p>
      ) : (
        <div className="form-grid">
          <div>
            <label htmlFor="sent-on">Date the list was sent</label>
            <input id="sent-on" type="date" value={sentOn} max={stamp()} onChange={(event) => setSentOn(event.target.value)} />
          </div>
          <div>
            <label htmlFor="sent-way">How it was sent</label>
            <select id="sent-way" value={way} onChange={(event) => setWay(event.target.value)}>
              <option>E-mail</option>
              <option>Hand-carried</option>
              <option>Agency portal</option>
              <option>Courier</option>
              <option value="">Other / not recorded</option>
            </select>
          </div>
          <div className="full-column">
            <label htmlFor="sent-note">Note (optional)</label>
            <input id="sent-note" value={note} maxLength={2000} onChange={(event) => setNote(event.target.value)} placeholder="e.g. sent to the CHED regional office" />
          </div>
          <p className="muted small full-column">
            Every selected student will show this date as Forwarded on. Nothing else changes: the applications stay with the
            agency until you record its answer in Approved Lists.
          </p>
        </div>
      )}
    </Modal>
  );
}

function DocumentsZipModal({ rows, programs, onClose }) {
  const toast = useToast();
  const programIds = [...new Set(rows.map((a) => a.scholarship_id))];
  const names = [
    ...new Set(
      programs
        .filter((p) => programIds.includes(p.id))
        .flatMap((p) => (p.requirements || []).map((r) => r.name))
    ),
  ];
  const [types, setTypes] = useState([]);
  const [busy, setBusy] = useState(false);

  async function download() {
    setBusy(true);
    try {
      const name = await downloadFile("/staff/forwarded/documents-zip", { ids: rows.map((a) => a.id), types }, "supporting-documents.zip");
      toast.success(`Downloaded ${name}. The file 00_contents.csv inside lists every document and any that are missing.`);
      onClose();
    } catch (err) {
      toast.error(errMsg(err, "Unable to make the ZIP."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Supporting documents (ZIP)"
      subtitle={<p className="muted small">{rows.length} student(s) · one folder per student</p>}
      onClose={onClose}
      footer={
        <div className="button-row">
          <button className="button button-secondary" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="button button-primary" onClick={download} disabled={busy || !types.length}>
            {busy ? "Making the ZIP..." : `Download ZIP (${types.length} type${types.length === 1 ? "" : "s"})`}
          </button>
        </div>
      }
    >
      <p className="small">Tick only the documents the agency asked for. The ZIP holds students' personal data: send it only to the agency, through an official channel (RA 10173).</p>
      {names.length === 0 ? (
        <p className="muted small">This program has no document types set up.</p>
      ) : (
        <div className="checkbox-list">
          {names.map((name) => (
            <label key={name} className="checkbox-row">
              <input
                type="checkbox"
                checked={types.includes(name)}
                onChange={() => setTypes((old) => (old.includes(name) ? old.filter((t) => t !== name) : [...old, name]))}
              />{" "}
              {name}
            </label>
          ))}
        </div>
      )}
      <p className="muted small">The download is written to the activity log.</p>
    </Modal>
  );
}
