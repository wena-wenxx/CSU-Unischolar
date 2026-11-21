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

// SHA-256 of the file, so exactly the same file uploaded again is noticed (null when the browser
// cannot do it, e.g. on a plain http:// address that is not localhost).
async function fileFingerprint(file) {
  try {
    if (!window.crypto?.subtle) return null;
    const digest = await window.crypto.subtle.digest("SHA-256", await file.arrayBuffer());
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, "0")).join("");
  } catch {
    return null;
  }
}

// How the pages of a PDF were read, e.g. "2 pages from the PDF text, 6 pages with OCR".
function pageMethodsText(metadata) {
  const pages = metadata?.page_methods;
  if (!Array.isArray(pages) || pages.length === 0) return "";
  const count = (method) => pages.filter((p) => p.method === method).length;
  const parts = [
    count("text") && `${count("text")} page${count("text") === 1 ? "" : "s"} from the PDF's own text`,
    count("ocr") && `${count("ocr")} page${count("ocr") === 1 ? "" : "s"} with OCR`,
    count("text-no-ocr") && `${count("text-no-ocr")} scanned page${count("text-no-ocr") === 1 ? "" : "s"} read from a weak text layer (OCR not installed)`,
    count("skipped") && `${count("skipped")} page${count("skipped") === 1 ? "" : "s"} skipped (instructions, totals or signatures)`,
  ].filter(Boolean);
  return parts.join(", ");
}

const openRow = (line) => ["unmatched", "error", "check"].includes(line.result) && !line.resolved_at;

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
  const [nameColumn, setNameColumn] = useState("");
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
  const [openBatches, setOpenBatches] = useState([]);
  const [batchId, setBatchId] = useState("");
  const [fileSha, setFileSha] = useState(null);

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      Promise.all([
        api.get("/scholarships"),
        api.get("/staff/agency-lists"),
        api.get("/settings/public"),
        api.get("/staff/processing-batches", { params: { status: "open" } }),
      ])
        .then(([programs, uploads, settings, batches]) => {
          setScholarships(programs.data);
          setHistory(uploads.data);
          setOpenBatches(batches.data?.data || []);
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
    setBatchId("");
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
      setFileSha(await fileFingerprint(file));
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
      setNameColumn(guessColumn(head, [/^(full\s*)?name$/, /^name of/, /^(student|grantee|scholar|awardee)('?s)?\s*name$/]));
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
    setFileSha(null);
    setIdColumn("");
    setDateColumn("");
    setNameColumn("");
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
      // One "Name" column ("DELA CRUZ, JUAN M." or "Juan M. Dela Cruz") -> last and first name.
      const splitName = (text) => {
        const value = String(text || "").trim();
        if (!value) return [null, null];
        const dropInitials = (words) => words.filter((w) => !/^[A-Za-zÑñ]\.?$/.test(w)).join(" ");
        if (value.includes(",")) {
          const [last, rest] = value.split(",", 2);
          return [last.trim(), dropInitials(rest.trim().split(/\s+/)) || null];
        }
        const words = value.split(/\s+/);
        return words.length > 1 ? [words[words.length - 1], dropInitials(words.slice(0, -1))] : [value, null];
      };
      const lastCol = fieldColumn(["Last Name", "Surname"]);
      const firstCol = fieldColumn(["Given Name", "First Name"]);
      const payload = {
        scholarship_id: Number(scholarshipId),
        agency_name: agency.trim(),
        file_name: fileName,
        file_sha256: fileSha,
        processing_batch_id: batchId === "" ? null : Number(batchId),
        ...details,
        expected_count: details.expected_count === "" ? null : Number(details.expected_count),
        expected_total: details.expected_total === "" ? null : Number(details.expected_total),
        confirmed_mismatch: confirmedMismatch,
        confirmed_duplicate: confirmedDuplicate,
        rows: dataRows.map((row, i) => ({
          row: firstDataLine + i,
          student_id: idColumn === "" ? null : valueAt(row, Number(idColumn)),
          approval_date: dateColumn === "" ? null : valueAt(row, Number(dateColumn)),
          amount: money(valueAt(row, amountColumn)),
          control_number: valueAt(row, fieldColumn(["Control Number"])),
          award_number: valueAt(row, fieldColumn(["TES Award Number"])),
          last_name: lastCol !== null ? valueAt(row, lastCol) : nameColumn !== "" ? splitName(valueAt(row, Number(nameColumn)))[0] : null,
          first_name: firstCol !== null ? valueAt(row, firstCol) : nameColumn !== "" ? splitName(valueAt(row, Number(nameColumn)))[1] : null,
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

  // A Student ID column is best; a list with names only can still be matched (exact, one-of-a-kind names).
  const hasNames = nameColumn !== "" || headers.some((h) => /^(last\s*name|surname)$/i.test(h.trim()));
  const ready = scholarshipId && agency.trim() && fileName && (idColumn !== "" || hasNames) && dataRows.length > 0;
  const amountColumn = guessColumn(headers, [/tes.?amount/, /^amount$/, /total.?amount/]);
  const countMismatch = details.expected_count !== "" && Number(details.expected_count) !== dataRows.length;
  const amountSum = dataRows.reduce((sum, row) => sum + (Number(String(row[amountColumn] || "").replaceAll(",", "")) || 0), 0);
  const amountMismatch = details.expected_total !== "" && Math.abs(amountSum - Number(details.expected_total)) > 0.01;
  const chosenProgram = scholarships.find((s) => String(s.id) === scholarshipId);
  const wrongProgram = programMismatch(metadata.program, chosenProgram);
  const pastYear = details.school_year && defaults.current_school_year && details.school_year < defaults.current_school_year;
  const cellsLeft = (cells || []).slice(hasHeader ? 1 : 0).reduce((sum, row) => sum + (row || []).filter((c) => c?.check).length, 0);
  const programBatches = openBatches.filter((b) => String(b.scholarship_id) === scholarshipId);

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

          <div>
            <label htmlFor="agency-batch">Processing batch</label>
            <select id="agency-batch" value={batchId} onChange={(event) => setBatchId(event.target.value)} disabled={!scholarshipId}>
              <option value="">Automatic: the open batch of this program and period</option>
              {programBatches.map((b) => (
                <option key={b.id} value={String(b.id)}>
                  {b.name}
                </option>
              ))}
            </select>
            <span className="muted small">
              Students added from this list go into this batch. Manage batches in{" "}
              <Link to="/staff/batches">Processing Batches</Link>.
            </span>
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
          {pageMethodsText(metadata) && <p className="muted small">How the pages were read: {pageMethodsText(metadata)}.</p>}
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
              <label htmlFor="id-column">Student ID column</label>
              <select id="id-column" value={idColumn} onChange={(event) => setIdColumn(event.target.value)}>
                <option value="">None (the list has names only)</option>
                {headers.map((h, i) => (
                  <option key={i} value={String(i)}>
                    {h}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label htmlFor="name-column">Name column (one column with the whole name)</label>
              <select id="name-column" value={nameColumn} onChange={(event) => setNameColumn(event.target.value)}>
                <option value="">None / separate Last and First name columns</option>
                {headers.map((h, i) => (
                  <option key={i} value={String(i)}>
                    {h}
                  </option>
                ))}
              </select>
              {idColumn === "" && (
                <span className="muted small">
                  Without a Student ID, a row is matched only when its name fits exactly one student; the others are
                  listed as unmatched for you to check.
                </span>
              )}
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

      {result && (
        <UploadResult
          upload={result}
          onChanged={(fresh) => {
            setResult(fresh);
            load();
          }}
        />
      )}

      <section className="card">
        <h2>Upload history</h2>

        {history.length === 0 ? (
          <EmptyState message="No lists uploaded yet." />
        ) : (
          <HistoryTable history={history} onChanged={load} />
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

function Details({ upload, onChanged }) {
  const [linking, setLinking] = useState(null);
  const [settingAside, setSettingAside] = useState(null);

  // Problems first, so staff see what needs attention.
  const order = { error: 0, check: 1, unmatched: 2, created: 3, approved: 4, already: 5 };
  const sorted = [...(upload.details || [])].sort(
    (a, b) => Number(openRow(b)) - Number(openRow(a)) || order[a.result] - order[b.result] || a.row - b.row
  );
  const canAct = Boolean(onChanged) && upload.processing_batch?.status !== "completed";

  return (
    <>
      <div className="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Row</th>
              <th>Student ID</th>
              <th>Student</th>
              <th>Result</th>
              <th>What happened</th>
              {canAct && <th></th>}
            </tr>
          </thead>
          <tbody>
            {sorted.map((line) => (
              <tr key={line.row}>
                <td>{line.row}</td>
                <td>{line.student_id || "—"}</td>
                <td>{line.student || [line.first_name, line.last_name].filter(Boolean).join(" ") || "—"}</td>
                <td>
                  <span className={`status status-${RESULT_LABELS[line.result][1]}`}>{RESULT_LABELS[line.result][0]}</span>
                  {line.set_aside && <span className="status status-neutral">Set aside</span>}
                </td>
                <td>
                  {line.note}
                  {line.resolved_at && (
                    <div className="muted small">
                      {line.set_aside
                        ? `Set aside by ${line.resolved_by} (${formatDateTime(line.resolved_at)}): ${line.resolved_note}`
                        : `Linked by ${line.resolved_by} (${formatDateTime(line.resolved_at)}). Before: ${RESULT_LABELS[line.resolved_from]?.[0] || line.resolved_from}${line.resolved_note ? ` – ${line.resolved_note}` : ""}`}
                    </div>
                  )}
                </td>
                {canAct && (
                  <td>
                    {openRow(line) && (
                      <div className="button-row">
                        <button className="button button-small button-primary" onClick={() => setLinking(line)}>
                          Link to student
                        </button>
                        <button className="button button-small button-secondary" onClick={() => setSettingAside(line)}>
                          Set aside
                        </button>
                      </div>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {linking && (
        <LinkRowModal
          upload={upload}
          line={linking}
          onClose={() => setLinking(null)}
          onDone={(fresh) => {
            setLinking(null);
            onChanged(fresh);
          }}
        />
      )}
      {settingAside && (
        <SetAsideModal
          upload={upload}
          line={settingAside}
          onClose={() => setSettingAside(null)}
          onDone={(fresh) => {
            setSettingAside(null);
            onChanged(fresh);
          }}
        />
      )}
    </>
  );
}

/*
  An unmatched row (no or a wrong Student ID on the list): staff find the student in the Data Bank
  and link the row. The row is then processed for that student, as during the upload.
*/
function LinkRowModal({ upload, line, onClose, onDone }) {
  const toast = useToast();
  const confirm = useConfirm();
  const onList = [line.first_name, line.middle_name, line.last_name].filter(Boolean).join(" ");
  const [query, setQuery] = useState(line.last_name || line.student_id || "");
  const [found, setFound] = useState(null);
  const [searching, setSearching] = useState(false);
  const [saving, setSaving] = useState(false);

  async function search(event) {
    event?.preventDefault();
    if (!query.trim()) return;
    setSearching(true);
    try {
      const { data } = await api.get("/staff/data-bank", { params: { q: query.trim(), per_page: 10 } });
      setFound(data.data || []);
    } catch (err) {
      toast.error(errMsg(err, "Unable to search the Data Bank."));
    } finally {
      setSearching(false);
    }
  }

  async function link(student, confirmName = false) {
    setSaving(true);
    try {
      const { data } = await api.post(`/staff/agency-lists/${upload.id}/rows/${line.row}/link`, {
        student_id: student.id,
        confirm_name: confirmName,
      });
      toast.success(data.message);
      onDone(data.upload);
    } catch (err) {
      if (err.response?.status === 409 && err.response.data?.needs_confirmation && !confirmName) {
        setSaving(false);
        const ok = await confirm({
          title: "The names are different",
          message: err.response.data.message,
          confirmLabel: "Link anyway",
        });
        if (ok) return link(student, true);
        return;
      }
      toast.error(errMsg(err, "Unable to link this row."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      size="large"
      title={`Link row ${line.row} to a student`}
      subtitle={
        <p className="muted small">
          On the list: {onList || "no name"} · Student ID {line.student_id || "none"}
          {line.amount ? ` · amount ${line.amount}` : ""}
        </p>
      }
      onClose={onClose}
    >
      <form className="button-row" onSubmit={search}>
        <input
          aria-label="Search the Data Bank"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Name or Student ID"
        />
        <button className="button button-secondary" type="submit" disabled={searching}>
          {searching ? "Searching..." : "Search Data Bank"}
        </button>
      </form>

      {found && found.length === 0 && (
        <p className="muted">No student found. If the student has no account yet, set the row aside with a note.</p>
      )}
      {found && found.length > 0 && (
        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Student</th>
                <th>Student ID</th>
                <th>Course / year</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {found.map((student) => (
                <tr key={student.id}>
                  <td>
                    <strong className="student-name">
                      {student.last_name}, {student.first_name} {student.middle_name || ""}
                    </strong>
                  </td>
                  <td>{student.student_id}</td>
                  <td>
                    {student.course || "—"}
                    {student.year_level ? ` · ${student.year_level}` : ""}
                  </td>
                  <td>
                    <button className="button button-small button-primary" onClick={() => link(student)} disabled={saving}>
                      Link this student
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="muted small">
        Linking processes the row for the chosen student exactly as the upload does (approve, add an approved
        application, or note that the student is already approved). The one-active-scholarship rule still applies.
      </p>
    </Modal>
  );
}

function SetAsideModal({ upload, line, onClose, onDone }) {
  const toast = useToast();
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    try {
      const { data } = await api.post(`/staff/agency-lists/${upload.id}/rows/${line.row}/set-aside`, { note: note.trim() });
      toast.success(data.message);
      onDone(data.upload);
    } catch (err) {
      toast.error(errMsg(err, "Unable to set this row aside."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={`Set aside row ${line.row}`}
      onClose={onClose}
      footer={
        <div className="button-row">
          <button className="button button-secondary" onClick={onClose}>
            Cancel
          </button>
          <button className="button button-primary" onClick={save} disabled={saving || !note.trim()}>
            {saving ? "Saving..." : "Set aside"}
          </button>
        </div>
      }
    >
      <p className="muted">
        The row stays on the list as it is, with your reason. Nothing else changes. Use this when, for example, the
        student is not in the system or the agency will send a correction.
      </p>
      <label htmlFor="aside-note">Reason</label>
      <textarea id="aside-note" rows={3} value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} />
    </Modal>
  );
}

function UploadResult({ upload, onChanged }) {
  return (
    <section className="card">
      <div className="card-header">
        <h2>Result: {upload.file_name}</h2>
        <Link className="button button-small button-secondary" to="/staff/applications">
          Go to Applications
        </Link>
      </div>

      {upload.processing_batch && <p className="muted small">Batch: {upload.processing_batch.name}</p>}
      <Summary upload={upload} />

      <p className="muted small">
        Next step for approved students: open each one in Applications and press <strong>Verify enrollment</strong>,
        then tag them in Scholar Records. A student who is not currently enrolled stays approved but is not tagged and
        gets no payroll. Unmatched rows stay open until you link them to a student or set them aside.
      </p>

      <Details upload={upload} onChanged={onChanged} />
    </section>
  );
}

function HistoryTable({ history, onChanged }) {
  const toast = useToast();
  const [open, setOpen] = useState(null);

  async function reopen(fresh) {
    setOpen(fresh);
    onChanged();
  }

  async function show(item) {
    try {
      const { data } = await api.get(`/staff/agency-lists/${item.id}`);
      setOpen(data);
    } catch (err) {
      toast.error(errMsg(err, "Unable to open this list."));
    }
  }

  return (
    <>
      <div className="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Uploaded</th>
              <th>Program</th>
              <th>Batch</th>
              <th>Period</th>
              <th>Agency</th>
              <th>File</th>
              <th>By</th>
              <th className="numeric">Rows</th>
              <th className="numeric">Matched</th>
              <th className="numeric">Unmatched</th>
              <th className="numeric">Errors</th>
              <th className="numeric">To resolve</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {history.map((item) => (
              <tr key={item.id}>
                <td>{formatDateTime(item.created_at)}</td>
                <td>{item.scholarship?.name}</td>
                <td>{item.processing_batch?.name || "—"}</td>
                <td>{[item.semester, item.school_year && `AY ${item.school_year}`, item.batch].filter(Boolean).join(" ") || "—"}</td>
                <td>{item.agency_name}</td>
                <td>{item.file_name}</td>
                <td>{item.uploader?.name || "—"}</td>
                <td className="numeric">{item.total_rows}</td>
                <td className="numeric">{item.approved_count + item.created_count + item.already_count}</td>
                <td className="numeric">{item.unmatched_count}</td>
                <td className="numeric">{item.error_count}</td>
                <td className="numeric">
                  {item.open_rows ? <span className="text-warning">{item.open_rows}</span> : 0}
                </td>
                <td>
                  <button className="button button-small button-secondary" onClick={() => show(item)}>
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
            {open.processing_batch ? ` · Batch: ${open.processing_batch.name}` : ""}
          </p>
          {open.processing_batch?.status === "completed" && (
            <p className="muted small">This batch is completed, so its rows can no longer be changed. Reopen it in Processing Batches first.</p>
          )}
          <Summary upload={open} />
          <Details upload={open} onChanged={reopen} />
        </Modal>
      )}
    </>
  );
}
