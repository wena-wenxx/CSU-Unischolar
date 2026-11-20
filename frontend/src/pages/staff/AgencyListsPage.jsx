import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { LIST_ACCEPT, readListFile } from "../../lib/listFile";
import { downloadCSV, formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import Modal from "../../components/Modal";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import ListPreview from "../../components/ListPreview";

const RESULT_LABELS = {
  approved: ["Approved", "success"],
  created: ["New approved application", "success"],
  already: ["Already approved", "neutral"],
  unmatched: ["Unmatched", "warning"],
  check: ["Check: name differs", "warning"],
  error: ["Error", "danger"],
};

// "TES", "CMSP"... read from the file, compared with the program chosen.
function programMismatch(detected, program) {
  if (!detected || !program) return false;
  const want = String(detected).toUpperCase();
  const have = `${program.short_name || ""} ${program.name || ""}`.toUpperCase();
  return !have.includes(want);
}

// Guess which column holds what, from the header names.
function guessColumn(headers, patterns) {
  const index = headers.findIndex((h) => patterns.some((p) => p.test(h.toLowerCase())));
  return index >= 0 ? String(index) : "";
}

/*
  Upload the approved list an agency sends back (CHED, DOST, LGU, foundation).
  1. Choose the program and the list file (CSV, Excel, Word, PDF or a photo).
  2. Say which column holds the Student ID (and, optionally, the approval date).
  3. Process: each row is matched to a student; see the summary and details.
*/
export default function AgencyListsPage() {
  const toast = useToast();
  const confirm = useConfirm();

  const [scholarships, setScholarships] = useState(null);
  const [history, setHistory] = useState([]);
  const [scholarshipId, setScholarshipId] = useState("");
  const [agency, setAgency] = useState("");
  const [fileName, setFileName] = useState("");
  const [rows, setRows] = useState([]);
  const [cells, setCells] = useState(null);
  const [fileWarnings, setFileWarnings] = useState([]);
  const [method, setMethod] = useState("");
  const [hasHeader, setHasHeader] = useState(true);
  const [idColumn, setIdColumn] = useState("");
  const [dateColumn, setDateColumn] = useState("");
  const [fileKey, setFileKey] = useState(0);
  const [processing, setProcessing] = useState(false);
  const [reading, setReading] = useState(false);
  const [result, setResult] = useState(null);
  const [metadata, setMetadata] = useState({});
  const [details, setDetails] = useState({
    school_year: "",
    semester: "",
    batch: "",
    campus: "",
    reference_number: "",
    list_date: "",
    expected_count: "",
    expected_total: "",
    notes: "",
  });
  const [confirmedMismatch, setConfirmedMismatch] = useState(false);
  const [defaults, setDefaults] = useState({});

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      Promise.all([api.get("/scholarships"), api.get("/staff/agency-lists"), api.get("/settings/public")])
        .then(([programs, uploads, settings]) => {
          setScholarships(programs.data);
          setHistory(uploads.data);
          setDefaults(settings.data || {});
          setDetails((old) => ({
            ...old,
            school_year: old.school_year || settings.data?.current_school_year || "",
            semester: old.semester || settings.data?.current_semester || "",
          }));
        })
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load this page."));
          setScholarships([]);
        }),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  const headers = useMemo(() => {
    if (!rows.length) return [];
    return hasHeader ? rows[0].map((h, i) => h.trim() || `Column ${i + 1}`) : rows[0].map((_, i) => `Column ${i + 1}`);
  }, [rows, hasHeader]);

  const dataRows = hasHeader ? rows.slice(1) : rows;
  const firstDataLine = hasHeader ? 2 : 1;

  function chooseProgram(id) {
    setScholarshipId(id);
    const program = (scholarships || []).find((s) => String(s.id) === id);
    setAgency(program?.provider || "");
  }

  // CSV is read here; Excel and Word by Laravel; PDF and pictures by the AI service.
  async function readFile(event) {
    const file = event.target.files?.[0];
    setResult(null);

    if (!file) return;
    setReading(true);

    try {
      const { rows: parsed, cells: cellInfo, warnings, metadata: detected, method: how } = await readListFile(file);
      if (parsed.length === 0) {
        toast.error("The file is empty.");
        return;
      }
      setFileWarnings(warnings);
      setMethod(how);
      setRows(parsed);
      setCells(cellInfo);
      setFileName(file.name);
      setMetadata(detected || {});
      setConfirmedMismatch(false);
      setDetails((old) => ({
        ...old,
        school_year: detected?.academic_year || old.school_year || defaults.current_school_year || "",
        semester: detected?.semester || old.semester || defaults.current_semester || "",
        batch: detected?.batch ? (/^batch/i.test(detected.batch) ? detected.batch : `Batch ${detected.batch}`) : old.batch || "",
        campus: detected?.campus || old.campus || "",
        reference_number: detected?.reference_number || "",
        list_date: detected?.date || "",
        // what the FILE says (printed total ÷ amount), not how many rows were read
        expected_count: detected?.expected_count ? String(detected.expected_count) : "",
        expected_total: detected?.printed_total != null ? String(detected.printed_total) : "",
      }));
      const head = parsed[0].map((h) => String(h).trim());
      setIdColumn(guessColumn(head, [/student.?(id|no|num)/, /id.?(no|num)/, /^stud/, /^id$/]));
      setDateColumn(guessColumn(head, [/approv.*date/, /date/]));
    } catch (err) {
      toast.error(errMsg(err, err.message || "The file could not be read."));
      setFileKey((k) => k + 1);
    } finally {
      setReading(false);
    }
  }

  // A cell corrected by staff in the preview.
  function editCell(r, c, value) {
    const line = r + (hasHeader ? 1 : 0);
    setRows((old) => old.map((row, i) => (i === line ? row.map((cell, j) => (j === c ? value : cell)) : row)));
    setCells((old) => {
      if (!old) return old;
      return old.map((row, i) =>
        i === line ? (row || []).map((info, j) => (j === c ? { ...info, check: false, edited: true } : info)) : row
      );
    });
  }

  function reset() {
    setRows([]);
    setCells(null);
    setFileWarnings([]);
    setMethod("");
    setFileName("");
    setIdColumn("");
    setDateColumn("");
    setMetadata({});
    setConfirmedMismatch(false);
    setFileKey((k) => k + 1);
  }

  async function process(confirmedDuplicate = false) {
    const program = scholarships.find((s) => String(s.id) === scholarshipId);

    if (!confirmedDuplicate) {
      const ok = await confirm({
        title: `Process ${dataRows.length} rows?`,
        message: `Students on this list will be marked approved for "${program?.name}". Applications that are rejected, drafts, students who already hold another active scholarship, and rows whose name does not match the Student ID are NOT changed; they are listed for you to check.${cellsLeft ? ` ${cellsLeft} highlighted cell(s) were not checked yet.` : ""}`,
        confirmLabel: "Process list",
      });

      if (!ok) return;
    }

    setProcessing(true);

    try {
      const fieldColumn = (names) => {
        const index = headers.findIndex((header) => names.some((name) =>
          header.toLowerCase().replace(/[^a-z]/g, "") === name.toLowerCase().replace(/[^a-z]/g, "")
        ));
        return index >= 0 ? index : null;
      };
      const valueAt = (row, index) => index === null ? null : (row[index] || "").trim() || null;
      // "10,000.00" -> 10000; anything that is not a number (an unreadable scan) -> null
      const money = (text) => {
        const plain = String(text ?? "").replaceAll(",", "").trim();
        return /^\d+(\.\d+)?$/.test(plain) ? plain : null;
      };
      const payload = {
        scholarship_id: Number(scholarshipId),
        agency_name: agency.trim(),
        file_name: fileName,
        ...details,
        expected_count: details.expected_count === "" ? null : Number(details.expected_count),
        expected_total: details.expected_total === "" ? null : Number(details.expected_total),
        confirmed_mismatch: confirmedMismatch,
        confirmed_duplicate: confirmedDuplicate,
        rows: dataRows.map((row, i) => ({
          row: firstDataLine + i,
          student_id: valueAt(row, Number(idColumn)),
          approval_date: dateColumn === "" ? null : valueAt(row, Number(dateColumn)),
          amount: money(valueAt(row, amountColumn)),
          control_number: valueAt(row, fieldColumn(["Control Number"])),
          award_number: valueAt(row, fieldColumn(["TES Award Number"])),
          last_name: valueAt(row, fieldColumn(["Last Name"])),
          first_name: valueAt(row, fieldColumn(["Given Name", "First Name"])),
          middle_name: valueAt(row, fieldColumn(["Middle Initial", "Middle Name"])),
          sex: valueAt(row, fieldColumn(["Sex at Birth", "Sex"])),
          birthdate: valueAt(row, fieldColumn(["Birthdate", "Date of Birth"])),
          program: valueAt(row, fieldColumn(["Degree Program", "Program", "Course"])),
          year_level: valueAt(row, fieldColumn(["Year Level", "Year"])),
          email: valueAt(row, fieldColumn(["Email Address", "Email"])),
          phone: valueAt(row, fieldColumn(["Phone Number", "Phone", "Mobile Number"])),
          batch: valueAt(row, fieldColumn(["TES Batch", "Batch"])),
          pwd: valueAt(row, fieldColumn(["PWD", "Person with Disability"])),
          total_amount: money(valueAt(row, fieldColumn(["TOTAL AMOUNT", "Total Amount"]))),
        })),
      };

      const { data } = await api.post("/staff/agency-lists", payload);
      toast.success(data.message);
      setResult(data.upload);
      reset();
      load();
    } catch (err) {
      if (err.response?.status === 409 && !confirmedDuplicate) {
        setProcessing(false);
        const again = await confirm({
          title: "This list was already processed",
          message: `${err.response.data?.message || ""} Process it again?`,
          confirmLabel: "Process again",
        });
        if (again) return process(true);
        return;
      }
      toast.error(errMsg(err, "Unable to process the list."));
    } finally {
      setProcessing(false);
    }
  }

  function downloadTemplate() {
    downloadCSV("agency-approved-list-template.csv", [
      { Student_ID: "2026-00001", Last_Name: "Navarro", First_Name: "Juan", Approval_Date: "2026-10-15" },
    ]);
  }

  if (!scholarships) return <Loading />;

  const ready = scholarshipId && agency.trim() && fileName && idColumn !== "" && dataRows.length > 0;
  const amountColumn = guessColumn(headers, [/tes.?amount/, /^amount$/, /total.?amount/]);
  const countMismatch = details.expected_count !== "" && Number(details.expected_count) !== dataRows.length;
  const amountSum = dataRows.reduce((sum, row) => sum + (Number(String(row[amountColumn] || "").replaceAll(",", "")) || 0), 0);
  const amountMismatch = details.expected_total !== "" && Math.abs(amountSum - Number(details.expected_total)) > 0.01;
  const chosenProgram = scholarships.find((s) => String(s.id) === scholarshipId);
  const wrongProgram = programMismatch(metadata.program, chosenProgram);
  const pastYear = details.school_year && defaults.current_school_year && details.school_year < defaults.current_school_year;
  const cellsLeft = (cells || []).slice(hasHeader ? 1 : 0).reduce((sum, row) => sum + (row || []).filter((c) => c?.check).length, 0);

  return (
    <div>
      <PageHeader
        title="Approved Lists"
        subtitle="Upload the list of students an agency approved and update their applications in one step"
        actions={
          <button className="button button-secondary" onClick={downloadTemplate}>
            Download sample template
          </button>
        }
      />

      <section className="card">
        <h2>1. Program and file</h2>

        <div className="form-grid">
          <div>
            <label htmlFor="agency-program">Scholarship program</label>
            <select id="agency-program" value={scholarshipId} onChange={(event) => chooseProgram(event.target.value)}>
              <option value="">Choose a program</option>
              {scholarships.map((s) => (
                <option key={s.id} value={String(s.id)}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="agency-name">Agency that sent the list</label>
            <input id="agency-name" value={agency} onChange={(event) => setAgency(event.target.value)} />
          </div>

          <div className="full-column">
            <label htmlFor="agency-file">Approved list (Excel, CSV, Word or PDF)</label>
            <input id="agency-file" key={fileKey} type="file" accept={LIST_ACCEPT} onChange={readFile} disabled={reading} />
            <p className="muted small">
              Excel (.xlsx), CSV, Word (.docx), PDF or a photo. PDFs and photos are read by the AI service, so keep it
              running for those. The list needs a column with the Student ID; an approval date column is optional. Old
              .xls/.doc files: open them and Save As .xlsx/.docx first.
            </p>
            {reading && <p className="muted small"><strong>Reading and organizing the file...</strong></p>}
          </div>
        </div>
      </section>

      {rows.length > 0 && (
        <section className="card">
          <h2>2. List details</h2>
          {method && method.startsWith("ocr") && (
            <p className="muted small">
              This file is a scan, so it was read with OCR. Check the highlighted cells in step 3 against the paper.
            </p>
          )}
          {(fileWarnings.length > 0 || wrongProgram || pastYear) && (
            <div className="alert alert-warning">
              <strong>Check before processing:</strong>
              <ul className="plain-list">
                {wrongProgram && (
                  <li>
                    The file looks like a <strong>{metadata.program}</strong> list, but you chose “{chosenProgram?.name}”.
                  </li>
                )}
                {pastYear && (
                  <li>
                    Academic year {details.school_year} is a past year (current: {defaults.current_school_year}).
                  </li>
                )}
                {fileWarnings.map((warning, i) => (
                  <li key={i}>{warning}</li>
                ))}
              </ul>
            </div>
          )}
          <div className="form-grid">
            {[
              ["school_year", "Academic year", "select"],
              ["semester", "Semester", "select"],
              ["batch", "TES batch", "select"],
              ["campus", "HEI campus", "text"],
              ["reference_number", "Agency reference number", "text"],
              ["list_date", "List date", "date"],
              ["expected_count", "Expected number of grantees", "number"],
              ["expected_total", "Expected total amount", "number"],
              ["notes", "Notes", "text"],
            ].map(([key, label, type]) => (
              <div key={key}>
                <label htmlFor={`list-${key}`}>{label}</label>
                {key === "school_year" ? (
                  <select id={`list-${key}`} value={details[key]} onChange={(event) => setDetails({ ...details, [key]: event.target.value })}>
                    <option value="">Choose academic year</option>
                    {["2024-2025", "2025-2026", "2026-2027", "2027-2028", "2028-2029", "2029-2030"].map((year) =>
                      <option key={year} value={year}>{year}</option>
                    )}
                  </select>
                ) : key === "batch" ? (
                  <select id={`list-${key}`} value={details[key]} onChange={(event) => setDetails({ ...details, [key]: event.target.value })}>
                    <option value="">Choose batch</option>
                    {Array.from({ length: 12 }, (_, i) => `Batch ${i + 1}`).map((batch) =>
                      <option key={batch} value={batch}>{batch}</option>
                    )}
                  </select>
                ) : type === "select" ? (
                  <select id={`list-${key}`} value={details[key]} onChange={(event) => setDetails({ ...details, [key]: event.target.value })}>
                    <option value="">Choose semester</option>
                    <option>1st Semester</option><option>2nd Semester</option><option>Summer</option>
                  </select>
                ) : (
                  <input id={`list-${key}`} type={type} value={details[key]} onChange={(event) => setDetails({ ...details, [key]: event.target.value })} />
                )}
                {metadata[key === "school_year" ? "academic_year" : key === "reference_number" ? "reference_number" : key === "list_date" ? "date" : key === "campus" ? "campus" : key === "expected_total" ? "printed_total" : key === "expected_count" ? "expected_count" : key] != null && (
                  <span className="muted small">Detected from file</span>
                )}
              </div>
            ))}
          </div>
          <div className={`alert ${countMismatch || amountMismatch ? "alert-warning" : "alert-success"}`}>
            Rows read: {dataRows.length} vs expected: {details.expected_count || "—"} · Summed amount: {amountSum.toLocaleString(undefined, { minimumFractionDigits: 2 })} vs printed: {details.expected_total || "—"}
            {countMismatch || amountMismatch ? (
              <label className="choice"><input type="checkbox" checked={confirmedMismatch} onChange={(event) => setConfirmedMismatch(event.target.checked)} /> I reviewed the mismatch and want to continue.</label>
            ) : " — Match"}
          </div>

          <h2>3. Which column is which?</h2>

          <div className="form-grid">
            <div>
              <label htmlFor="id-column">Student ID column (required)</label>
              <select id="id-column" value={idColumn} onChange={(event) => setIdColumn(event.target.value)}>
                <option value="">Choose a column</option>
                {headers.map((h, i) => (
                  <option key={i} value={String(i)}>
                    {h}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label htmlFor="date-column">Approval date column (optional)</label>
              <select id="date-column" value={dateColumn} onChange={(event) => setDateColumn(event.target.value)}>
                <option value="">None</option>
                {headers.map((h, i) => (
                  <option key={i} value={String(i)}>
                    {h}
                  </option>
                ))}
              </select>
            </div>

            <label className="choice">
              <input type="checkbox" checked={hasHeader} onChange={(event) => setHasHeader(event.target.checked)} />
              The first row has column names
            </label>
          </div>

          <p className="muted small">
            {fileName}: {dataRows.length} student row{dataRows.length === 1 ? "" : "s"}.
          </p>

          <ListPreview
            headers={headers}
            rows={dataRows}
            cells={cells ? (hasHeader ? cells.slice(1) : cells) : null}
            firstLine={firstDataLine}
            pickedColumn={idColumn}
            onEdit={editCell}
          />

          <div className="button-row">
            <button className="button button-secondary" onClick={reset}>
              Choose another file
            </button>
            <button className="button button-primary" onClick={() => process()} disabled={!ready || processing || ((countMismatch || amountMismatch) && !confirmedMismatch)}>
              {processing ? "Processing..." : `4. Process ${dataRows.length} rows`}
            </button>
          </div>
        </section>
      )}

      {result && <UploadResult upload={result} />}

      <section className="card">
        <h2>Upload history</h2>

        {history.length === 0 ? (
          <EmptyState message="No lists uploaded yet." />
        ) : (
          <HistoryTable history={history} />
        )}
      </section>
    </div>
  );
}

function Summary({ upload }) {
  const cells = [
    ["approved", upload.approved_count],
    ["created", upload.created_count],
    ["already", upload.already_count],
    ["unmatched", upload.unmatched_count],
    ["error", upload.error_count],
  ];

  return (
    <div className="result-summary">
      {cells.map(([key, value]) => (
        <div key={key} className={`result-box tone-${RESULT_LABELS[key][1]}`}>
          <strong>{value}</strong>
          <span>{RESULT_LABELS[key][0]}</span>
        </div>
      ))}
    </div>
  );
}

function Details({ details }) {
  // Problems first, so staff see what needs attention.
  const order = { error: 0, check: 1, unmatched: 2, created: 3, approved: 4, already: 5 };
  const sorted = [...(details || [])].sort((a, b) => order[a.result] - order[b.result] || a.row - b.row);

  return (
    <div className="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Row</th>
            <th>Student ID</th>
            <th>Student</th>
            <th>Result</th>
            <th>What happened</th>
          </tr>
        </thead>
        <tbody>
          {sorted.map((line) => (
            <tr key={line.row}>
              <td>{line.row}</td>
              <td>{line.student_id || "—"}</td>
              <td>{line.student || "—"}</td>
              <td>
                <span className={`status status-${RESULT_LABELS[line.result][1]}`}>{RESULT_LABELS[line.result][0]}</span>
              </td>
              <td>{line.note}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function UploadResult({ upload }) {
  return (
    <section className="card">
      <div className="card-header">
        <h2>Result: {upload.file_name}</h2>
        <Link className="button button-small button-secondary" to="/staff/applications">
          Go to Applications
        </Link>
      </div>

      <Summary upload={upload} />

      <p className="muted small">
        Next step for approved students: open each one in Applications and press <strong>Verify enrollment</strong>,
        then tag them in Scholar Records. A student who is not currently enrolled stays approved but is not tagged and
        gets no payroll.
      </p>

      <Details details={upload.details} />
    </section>
  );
}

function HistoryTable({ history }) {
  const [open, setOpen] = useState(null);

  return (
    <>
      <div className="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Uploaded</th>
              <th>Program</th>
              <th>Period</th>
              <th>Agency</th>
              <th>File</th>
              <th>By</th>
              <th>Rows</th>
              <th>Matched</th>
              <th>Unmatched</th>
              <th>Errors</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {history.map((item) => (
              <tr key={item.id}>
                <td>{formatDateTime(item.created_at)}</td>
                <td>{item.scholarship?.name}</td>
                <td>{[item.semester, item.school_year && `AY ${item.school_year}`, item.batch].filter(Boolean).join(" ") || "—"}</td>
                <td>{item.agency_name}</td>
                <td>{item.file_name}</td>
                <td>{item.uploader?.name || "—"}</td>
                <td>{item.total_rows}</td>
                <td>{item.approved_count + item.created_count + item.already_count}</td>
                <td>{item.unmatched_count}</td>
                <td>{item.error_count}</td>
                <td>
                  <button className="button button-small button-secondary" onClick={() => setOpen(item)}>
                    Details
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {open && (
        <Modal size="large" title={`Upload: ${open.file_name}`} onClose={() => setOpen(null)}>
          <p className="muted">
            {open.scholarship?.name} · {[open.semester, open.school_year && `AY ${open.school_year}`, open.batch].filter(Boolean).join(" ") || "No period"} · {open.agency_name} · {formatDateTime(open.created_at)} by {open.uploader?.name}
          </p>
          <Summary upload={open} />
          <Details details={open.details} />
        </Modal>
      )}
    </>
  );
}
