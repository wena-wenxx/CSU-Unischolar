import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { parseCSV } from "../../lib/csv";
import { downloadCSV, formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

/*
  Enrollment (staff):
  1. Upload the Registrar's list of enrolled students (CSV) for the term.
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
        }),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  const headers = useMemo(() => (rows.length ? rows[0].map((h, i) => h.trim() || `Column ${i + 1}`) : []), [rows]);
  const dataRows = rows.slice(1);

  function readFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    if (!/\.csv$/i.test(file.name)) {
      toast.error("Please choose a .csv file. In Excel: File → Save As → CSV (Comma delimited).");
      setFileKey((k) => k + 1);
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      const parsed = parseCSV(String(reader.result || ""));
      if (parsed.length < 2) {
        toast.error("The file has no student rows.");
        return;
      }
      const head = parsed[0].map((h) => h.trim());
      setRows(parsed);
      setFileName(file.name);
      setColumns({
        id: guessColumn(head, [/student.?(id|no|num)/, /id.?(no|num)/, /^stud/, /^id$/]),
        last: guessColumn(head, [/last/, /surname/, /family/]),
        first: guessColumn(head, [/first/, /given/]),
        course: guessColumn(head, [/course/, /program/, /degree/]),
      });
    };
    reader.readAsText(file);
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
      { Student_ID: "221-00462", Last_Name: "Contiga", First_Name: "Wena Rose", Course: "BS Information Technology" },
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
        <h2>1. Registrar's enrollment list</h2>

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
                {periods.periods.map((p) => (
                  <option key={p} value={p}>
                    {p}
                    {p === periods.current ? " (current)" : ""}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="enr-file">Registrar's list (CSV file)</label>
              <input id="enr-file" key={fileKey} type="file" accept=".csv,text/csv" onChange={readFile} />
            </div>
          </div>
          <p className="muted small">
            Ask the Registrar for the enrolled students of the term as Excel, then File → Save As → CSV. Only the Student
            ID, name and course columns are read.
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
              <p className="muted small">
                {fileName}: {dataRows.length} rows. First row: {dataRows[0]?.join(" · ")}
              </p>
              <div className="button-row">
                <button className="button button-secondary" onClick={resetFile}>
                  Choose another file
                </button>
                <button
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

      <section className="card">
        <h2>2. Verify all enrollments</h2>
        <p className="muted small">
          The system only suggests; nothing is saved until you press a Record button. Approved applicants waiting:{" "}
          <strong>{info.waiting}</strong> · active grantees: <strong>{info.grantees}</strong>.
        </p>

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
                  <table>
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
                          <td>{row.student_id}</td>
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
            <table>
              <thead>
                <tr>
                  <th>Uploaded</th>
                  <th>Term</th>
                  <th>File</th>
                  <th className="numeric">Students</th>
                  <th>By</th>
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
