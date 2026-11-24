import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg, isCancel } from "../../services/api";
import { LIST_ACCEPT, readListFile } from "../../lib/listFile";
import { downloadCSV, formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import { SEMESTERS } from "../../lib/terms";
import FileDrop from "../../components/FileDrop";

/*
  Enrollment (staff):
  1. Upload the Registrar's list of enrolled students (CSV, Excel, Word or PDF) for the term.
  2. Verify All Enrollments: approved applicants (and/or active grantees) are
     matched by Student ID -> Enrolled / Not on the list / Needs manual check.
  3. Record: nothing is saved until staff press a Record button.
  There is no live link to the Registrar; the list is the Registrar's export.
*/
const GROUPS = [
  ["enrolled", "Enrolled", "success", "Student ID on the Registrar's list and the surname matches."],
  ["not_enrolled", "Not on the list", "danger", "Student ID not on the Registrar's list. Check before recording."],
  ["manual", "Needs manual check", "warning", "Student ID found, but the name is different. Check who this is."],
];

function guessColumn(headers, patterns) {
  const index = headers.findIndex((h) => patterns.some((p) => p.test(h.toLowerCase())));
  return index >= 0 ? String(index) : "";
}

// Used only when the server could not send its term list (e.g. MySQL was stopped), so the Term box is
// never empty. Same wording as the server: "1st Semester AY 2026-2027".
function fallbackTerms() {
  const now = new Date();
  const start = now.getMonth() >= 7 ? now.getFullYear() : now.getFullYear() - 1; // school year starts in August
  const names = SEMESTERS;
  const list = [];
  for (const year of [start - 1, start]) {
    for (const name of names) list.push(`${name} AY ${year}-${year + 1}`);
  }
  const month = now.getMonth();
  const current = `${month >= 7 ? names[0] : month <= 4 ? names[1] : names[2]} AY ${start}-${start + 1}`;
  return { current, periods: list };
}

export default function EnrollmentPage() {
  const toast = useToast();
  const confirm = useConfirm();

  const [info, setInfo] = useState(null);
  const [periods, setPeriods] = useState({ current: "", periods: [] });

  // upload
  const [period, setPeriod] = useState("");
  const [fileName, setFileName] = useState("");
  const [rows, setRows] = useState([]);
  const [columns, setColumns] = useState({ id: "", last: "", first: "", course: "" });
  const [fileKey, setFileKey] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [reading, setReading] = useState(""); // name of the file being read
  const readControl = useRef(null); // "Cancel reading"
  const [viewing, setViewing] = useState(null); // an earlier list whose rows are shown

  // verify
  const [scope, setScope] = useState("approved");
  const [check, setCheck] = useState(null);
  const [tab, setTab] = useState("enrolled");
  const [picked, setPicked] = useState([]); // "kind-id"
  const [busy, setBusy] = useState(false);

  // .then() (not await) so React's lint rule sees the state is set later.
  const load = useCallback(
    () =>
      Promise.all([api.get("/staff/enrollment-lists"), api.get("/payroll/periods")])
        .then(([lists, termList]) => {
          setInfo(lists.data);
          setPeriods(termList.data);
          setPeriod((current) => current || termList.data.current);
        })
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load this page."));
          setInfo({ lists: [], waiting: 0, grantees: 0 });
          const terms = fallbackTerms();
          setPeriods((old) => (old.periods.length ? old : terms));
          setPeriod((current) => current || terms.current);
        }),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  const headers = useMemo(() => (rows.length ? rows[0].map((h, i) => h.trim() || `Column ${i + 1}`) : []), [rows]);
  const dataRows = rows.slice(1);

  // CSV is read here; Excel and Word by Laravel; PDF and pictures by the AI service.
  async function readFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    const controller = new AbortController();
    readControl.current = controller;
    setReading(file.name);
    try {
      const { rows: parsed, warnings } = await readListFile(file, { signal: controller.signal });
      if (parsed.length < 2) {
        toast.error("The file has no student rows.");
        return;
      }
      warnings.forEach((warning) => toast.info(warning));
      const head = parsed[0].map((h) => String(h).trim());
      setRows(parsed);
      setFileName(file.name);
      setColumns({
        id: guessColumn(head, [/student.?(id|no|num)/, /id.?(no|num)/, /^stud/, /^id$/]),
        last: guessColumn(head, [/last/, /surname/, /family/]),
        first: guessColumn(head, [/first/, /given/]),
        course: guessColumn(head, [/course/, /program/, /degree/]),
      });
    } catch (err) {
      if (isCancel(err)) toast.info("Reading cancelled. Nothing was saved.");
      else toast.error(errMsg(err, err.message || "The file could not be read."));
      setFileKey((k) => k + 1);
    } finally {
      readControl.current = null;
      setReading("");
    }
  }

  function resetFile() {
    setRows([]);
    setFileName("");
    setFileKey((k) => k + 1);
  }

  async function upload() {
    const cell = (row, key) => (columns[key] === "" ? null : (row[Number(columns[key])] || "").trim() || null);

    setUploading(true);
    try {
      const { data } = await api.post("/staff/enrollment-lists", {
        period,
        file_name: fileName,
        rows: dataRows.map((row) => ({
          student_id: cell(row, "id") || "",
          last_name: cell(row, "last"),
          first_name: cell(row, "first"),
          course: cell(row, "course"),
        })),
      });
      toast.success(data.message);
      resetFile();
      setCheck(null);
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to save the list."));
    } finally {
      setUploading(false);
    }
  }

  async function verifyAll() {
    setBusy(true);
    try {
      const { data } = await api.post("/staff/enrollment/check", { scope });
      setCheck(data);
      setPicked([]);
      setTab(data.summary.enrolled ? "enrolled" : data.summary.manual ? "manual" : "not_enrolled");
    } catch (err) {
      toast.error(errMsg(err, "Unable to check enrollments."));
    } finally {
      setBusy(false);
    }
  }

  async function record(items, enrolled) {
    if (!enrolled) {
      const ok = await confirm({
        title: `Record ${items.length} as NOT enrolled?`,
        message:
          "Approved applicants recorded as not enrolled cannot be tagged as grantees; grantees are left out of payroll. Do this only after checking with the Registrar.",
        confirmLabel: "Record not enrolled",
        tone: "danger",
      });
      if (!ok) return;
    }

    setBusy(true);
    try {
      const { data } = await api.post("/staff/enrollment/record", {
        enrollment_list_id: check.list.id,
        items: items.map((row) => ({ kind: row.kind, id: row.id, enrolled })),
      });
      toast.success(data.message);
      await load();
      const again = await api.post("/staff/enrollment/check", { scope });
      setCheck(again.data);
      setPicked([]);
    } catch (err) {
      toast.error(errMsg(err, "Unable to save."));
    } finally {
      setBusy(false);
    }
  }

  function downloadTemplate() {
    downloadCSV("registrar-enrollment-list-template.csv", [
      { Student_ID: "2026-00001", Last_Name: "Navarro", First_Name: "Juan", Course: "BS Information Technology" },
    ]);
  }

  if (!info) return <Loading />;

  const latest = info.lists[0];
  const key = (row) => `${row.kind}-${row.id}`;
  const shown = check ? check.rows.filter((row) => row.result === tab) : [];
  const pickedRows = shown.filter((row) => picked.includes(key(row)));
  const allPicked = shown.length > 0 && pickedRows.length === shown.length;

  return (
    <div>
      <PageHeader
        title="Enrollment"
        subtitle="Check approved applicants and grantees against the Registrar's list of enrolled students"
        actions={
          <button className="button button-secondary" onClick={downloadTemplate}>
            Download sample template
          </button>
        }
      />

      <section className="card">
        <h2>1. Upload the Registrar&apos;s enrollment list</h2>

        {latest ? (
          <p>
            Using <strong>{latest.file_name}</strong> · {latest.period} · {latest.rows_count} students · uploaded{" "}
            {formatDateTime(latest.created_at)} by {latest.uploader?.name || "OAS"}.
          </p>
        ) : (
          <div className="alert alert-warning">No Registrar list yet. Upload one to use Verify All Enrollments.</div>
        )}

        <details className="req-add" open={!latest}>
          <summary>Upload a newer list</summary>

          <div className="form-grid">
            <div>
              <label htmlFor="enr-period">Term</label>
              <select id="enr-period" value={period} onChange={(event) => setPeriod(event.target.value)}>
                {!period && <option value="">Choose the term of this list</option>}
                {periods.periods.map((p) => (
                  <option key={p} value={p}>
                    {p}
                    {p === periods.current ? " (current)" : ""}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="enr-file">Registrar's list (Excel, CSV, Word or PDF)</label>
              <FileDrop hint="or drag the Registrar's list here">
                <input id="enr-file" key={fileKey} type="file" accept={LIST_ACCEPT} onChange={readFile} disabled={Boolean(reading)} />
              </FileDrop>
              {reading && (
                <p className="small">
                  <span className="spinner" aria-hidden="true" /> Reading {reading}...{" "}
                  <button type="button" className="button button-small button-danger-outline" onClick={() => readControl.current?.abort()}>
                    Cancel reading
                  </button>
                </p>
              )}
            </div>
          </div>
          <p className="muted small">
            Excel (.xlsx), CSV, Word (.docx) or PDF from the Registrar. PDF files are read by the AI service, so keep it
            running for those. Only the Student ID, name and course columns are read. Old .xls/.doc files: open them and
            Save As .xlsx/.docx first.
          </p>

          {rows.length > 0 && (
            <>
              <div className="form-grid">
                {[
                  ["id", "Student ID column (required)"],
                  ["last", "Last name column (recommended)"],
                  ["first", "First name column"],
                  ["course", "Course column"],
                ].map(([k, label]) => (
                  <div key={k}>
                    <label htmlFor={`enr-col-${k}`}>{label}</label>
                    <select
                      id={`enr-col-${k}`}
                      value={columns[k]}
                      onChange={(event) => setColumns({ ...columns, [k]: event.target.value })}
                    >
                      <option value="">{k === "id" ? "Choose a column" : "None"}</option>
                      {headers.map((h, i) => (
                        <option key={i} value={String(i)}>
                          {h}
                        </option>
                      ))}
                    </select>
                  </div>
                ))}
              </div>
              <p className="small">
                <strong>{fileName}</strong>: <strong>{dataRows.length} rows read</strong>. Check them below. Nothing is
                saved yet, and saving the list does NOT record anyone as enrolled: it only keeps the Registrar&apos;s list
                to check against in step 3.
              </p>
              <ReadPreview rows={dataRows} columns={columns} />
              <div className="button-row">
                <button className="button button-secondary" onClick={resetFile}>
                  Choose another file
                </button>
                <button aria-busy={uploading}
                  className="button button-primary"
                  onClick={upload}
                  disabled={uploading || columns.id === "" || !period}
                >
                  {uploading ? "Saving..." : `Save list (${dataRows.length} rows)`}
                </button>
              </div>
            </>
          )}
        </details>
      </section>

      {(viewing || latest) && <ListRows key={(viewing || latest).id} list={viewing || latest} latestId={latest?.id} onBack={() => setViewing(null)} />}

      <section className="card">
        <h2>3. Verify all enrollments</h2>
        <p className="muted small">
          Checks the people who need it (approved applicants waiting, and/or active grantees) against the latest list.
          The system only suggests; nothing is saved until you press a Record button. Approved applicants waiting:{" "}
          <strong>{info.waiting}</strong> · active grantees: <strong>{info.grantees}</strong>.
        </p>
        {!latest && <p className="small warning">Upload the Registrar&apos;s list in step 1 first: the button stays off until there is one.</p>}
        {latest && !info.waiting && !info.grantees && <WhyEmpty why={info.why_empty} />}

        <div className="inline-form">
          <label htmlFor="enr-scope" className="sr-only">
            Who to check
          </label>
          <select id="enr-scope" value={scope} onChange={(event) => setScope(event.target.value)}>
            <option value="approved">Approved applicants waiting for verification</option>
            <option value="grantees">Active grantees (start-of-semester check)</option>
            <option value="both">Both</option>
          </select>
          <button className="button button-primary" onClick={verifyAll} disabled={busy || !latest}>
            {busy && !check ? "Checking..." : "Verify All Enrollments"}
          </button>
        </div>

        {check && (
          <div className="enr-results">
            <div className="filter-row" role="group" aria-label="Results">
              {GROUPS.map(([k, label]) => (
                <button
                  key={k}
                  className={tab === k ? "button button-small button-primary" : "button button-small button-secondary"}
                  aria-pressed={tab === k}
                  onClick={() => {
                    setTab(k);
                    setPicked([]);
                  }}
                >
                  {label} ({check.summary[k]})
                </button>
              ))}
            </div>

            <p className="muted small">{GROUPS.find(([k]) => k === tab)[3]}</p>

            {shown.length === 0 ? (
              <EmptyState message="Nobody in this group." />
            ) : (
              <>
                <div className="bulk-bar">
                  <span>
                    <strong>{pickedRows.length}</strong> of {shown.length} selected
                  </span>
                  <div className="button-row">
                    {tab === "enrolled" && (
                      <button className="button button-small button-success" disabled={busy} onClick={() => record(shown, true)}>
                        Record all {shown.length} as enrolled
                      </button>
                    )}
                    <button
                      className="button button-small button-success"
                      disabled={busy || !pickedRows.length}
                      onClick={() => record(pickedRows, true)}
                    >
                      Record selected as enrolled
                    </button>
                    <button
                      className="button button-small button-danger"
                      disabled={busy || !pickedRows.length}
                      onClick={() => record(pickedRows, false)}
                    >
                      Record selected as NOT enrolled
                    </button>
                  </div>
                </div>

                <div className="table-wrapper">
                  <table className="numbered">
                    <thead>
                      <tr>
                        <th>
                          <label className="sr-only" htmlFor="enr-all">
                            Select all
                          </label>
                          <input
                            id="enr-all"
                            type="checkbox"
                            checked={allPicked}
                            onChange={() => setPicked(allPicked ? [] : shown.map(key))}
                          />
                        </th>
                        <th>Student</th>
                        <th>Student ID</th>
                        <th>Scholarship</th>
                        <th>Who</th>
                        <th>What the list says</th>
                      </tr>
                    </thead>
                    <tbody>
                      {shown.map((row) => (
                        <tr key={key(row)} className={picked.includes(key(row)) ? "row-selected" : ""}>
                          <td>
                            <label className="sr-only" htmlFor={`enr-${key(row)}`}>
                              Select {row.student}
                            </label>
                            <input
                              id={`enr-${key(row)}`}
                              type="checkbox"
                              checked={picked.includes(key(row))}
                              onChange={() =>
                                setPicked((current) =>
                                  current.includes(key(row)) ? current.filter((x) => x !== key(row)) : [...current, key(row)]
                                )
                              }
                            />
                          </td>
                          <td>{row.student}</td>
                          <td className="nowrap">{row.student_id}</td>
                          <td>{row.scholarship}</td>
                          <td>{row.kind === "application" ? "Approved applicant" : "Active grantee"}</td>
                          <td>{row.note}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}

            <p className="muted small">
              After recording, tag the newly enrolled applicants in <Link to="/staff/scholars">Scholar Records</Link>. A
              single student can still be checked by hand from Applications → Verify enrollment.
            </p>
          </div>
        )}
      </section>

      {info.lists.length > 1 && (
        <section className="card">
          <h2>Earlier lists</h2>
          <div className="table-wrapper">
            <table className="numbered">
              <thead>
                <tr>
                  <th>Uploaded</th>
                  <th>Term</th>
                  <th>File</th>
                  <th className="numeric">Students</th>
                  <th>By</th>
                  <th>Rows</th>
                </tr>
              </thead>
              <tbody>
                {info.lists.map((list) => (
                  <tr key={list.id}>
                    <td>{formatDateTime(list.created_at)}</td>
                    <td>{list.period}</td>
                    <td>{list.file_name}</td>
                    <td className="numeric">{list.rows_count}</td>
                    <td>{list.uploader?.name || "—"}</td>
                    <td>
                      <button type="button" className="link-button small" onClick={() => setViewing(list)}>
                        View rows
                      </button>
                    </td>
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


// Before saving: every row that was read, in the chosen columns (so staff see all 55, not only the first).
function ReadPreview({ rows, columns }) {
  const cell = (row, key) => (columns[key] === "" ? "" : row[Number(columns[key])] || "");
  return (
    <div className="table-wrapper preview-scroll">
      <table className="numbered">
        <thead>
          <tr>
            <th>Student ID</th>
            <th>Last name</th>
            <th>First name</th>
            <th>Course</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={i} className={cell(row, "id").trim() ? "" : "row-muted"}>
              <td className="nowrap">{cell(row, "id") || <span className="text-warning small">no Student ID: skipped</span>}</td>
              <td>{cell(row, "last")}</td>
              <td>{cell(row, "first")}</td>
              <td>{cell(row, "course")}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const ROW_GROUPS = [
  ["all", "All rows"],
  ["waiting", "Approved, waiting for verification"],
  ["grantee", "Active grantees"],
  ["name_differs", "Name differs"],
  ["verified", "Already verified"],
  ["no_grant", "In the system, no grant"],
  ["not_in_system", "Not in the system"],
];

// Step 2: every row of a saved list with what the system found. Read-only.
function ListRows({ list, latestId, onBack }) {
  const toast = useToast();
  const [data, setData] = useState(null);
  const [group, setGroup] = useState("all");

  useEffect(() => {
    api
      .get(`/staff/enrollment-lists/${list.id}/rows`)
      .then(({ data: result }) => setData(result))
      .catch((err) => {
        toast.error(errMsg(err, "Unable to show the rows of this list."));
        setData({ summary: { total: 0 }, rows: [] });
      });
  }, [list.id, toast]);

  const shown = data ? data.rows.filter((row) => group === "all" || row.result === group) : [];

  return (
    <section className="card">
      <h2>2. What is in the list</h2>
      <p className="muted small">
        {list.file_name} · {list.period} · <strong>{list.rows_count} students</strong>
        {list.id !== latestId && (
          <>
            {" "}
            (an earlier list){" "}
            <button type="button" className="link-button small" onClick={onBack}>
              Show the latest list
            </button>
          </>
        )}
        . Every row is matched by Student ID. This only shows what was found; nobody is recorded as enrolled here.
      </p>
      {!data ? (
        <Loading />
      ) : (
        <>
          <div className="filter-row" role="group" aria-label="Rows">
            {ROW_GROUPS.filter(([k]) => k === "all" || data.summary[k]).map(([k, label]) => (
              <button
                key={k}
                type="button"
                className={group === k ? "button button-small button-primary" : "button button-small button-secondary"}
                aria-pressed={group === k}
                onClick={() => setGroup(k)}
              >
                {label} ({k === "all" ? data.summary.total : data.summary[k]})
              </button>
            ))}
          </div>
          <div className="table-wrapper preview-scroll">
            <table className="numbered">
              <thead>
                <tr>
                  <th>Student ID</th>
                  <th>Name on the list</th>
                  <th>Course</th>
                  <th>In the system</th>
                  <th>What it means</th>
                </tr>
              </thead>
              <tbody>
                {shown.map((row) => (
                  <tr key={`${row.no}-${row.student_id}`}>
                    <td className="nowrap">{row.student_id}</td>
                    <td>{[row.last_name, row.first_name].filter(Boolean).join(", ")}</td>
                    <td>{row.course}</td>
                    <td>
                      {row.student || "—"}
                      {row.programs && <small className="muted account-note">{row.programs}</small>}
                    </td>
                    <td className="small">{row.note}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  );
}

// Why there is nobody to verify, from the real counts (instead of an empty list with no reason).
function WhyEmpty({ why }) {
  if (!why) return null;
  const reasons = [];
  if (why.forwarded_waiting_for_agency)
    reasons.push(`${why.forwarded_waiting_for_agency} application(s) are forwarded and still waiting for the agency. Record the agency's answer in Approved Lists first.`);
  if (why.in_completed_batches)
    reasons.push(`${why.in_completed_batches} approved application(s) are in a processing batch that was marked completed. Reopen that batch in Processing Batches to verify them.`);
  if (why.already_verified) reasons.push(`${why.already_verified} approved application(s) were already verified.`);
  if (!reasons.length) reasons.push("No application has been approved by an agency yet, and nobody is an active grantee.");
  return (
    <div className="ai-note review small">
      <strong>Why nobody is listed:</strong>
      <ul>
        {reasons.map((reason) => (
          <li key={reason}>{reason}</li>
        ))}
      </ul>
    </div>
  );
}
