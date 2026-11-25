import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg, isCancel } from "../../services/api";
import { LIST_ACCEPT, readListFile } from "../../lib/listFile";
import { downloadCSV, formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import { SEMESTERS, yearChoices } from "../../lib/terms";
import Modal from "../../components/Modal";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import ListPreview from "../../components/ListPreview";
import FileDrop from "../../components/FileDrop";

const RESULT_LABELS = {
  approved: ["Approved", "success"],
  created: ["New approved application", "success"],
  already: ["Already approved", "neutral"],
  unmatched: ["Unmatched", "warning"],
  check: ["Check: name differs", "warning"],
  error: ["Error", "danger"],
};

async function fileFingerprint(file) {
  try {
    if (!window.crypto?.subtle) return null;
    const digest = await window.crypto.subtle.digest("SHA-256", await file.arrayBuffer());
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, "0")).join("");
  } catch {
    return null;
  }
}

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

function programMismatch(detected, program) {
  if (!detected || !program) return false;
  const want = String(detected).toUpperCase();
  const have = `${program.short_name || ""} ${program.name || ""}`.toUpperCase();
  return !have.includes(want);
}

function guessColumn(headers, patterns) {
  const index = headers.findIndex((h) => patterns.some((p) => p.test(h.toLowerCase())));
  return index >= 0 ? String(index) : "";
}

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
  const [readingName, setReadingName] = useState("");
  const readControl = useRef(null);
  const [pendingFile, setPendingFile] = useState(null);
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

  function chooseFile(event) {
    setPendingFile(event.target.files?.[0] || null);
  }

  async function readFile() {
    const file = pendingFile;
    setResult(null);

    if (!file) return;
    setReadingName(file.name);
    setReading(true);

    try {
      const controller = new AbortController();
      readControl.current = controller;
      const { rows: parsed, cells: cellInfo, warnings, metadata: detected, method: how } = await readListFile(file, { signal: controller.signal });
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
        expected_count: detected?.expected_count ? String(detected.expected_count) : "",
        expected_total: detected?.printed_total != null ? String(detected.printed_total) : "",
      }));
      const head = parsed[0].map((h) => String(h).trim());
      setIdColumn(guessColumn(head, [/student.?(id|no|num)/, /id.?(no|num)/, /^stud/, /^id$/]));
      setDateColumn(guessColumn(head, [/approv.*date/, /date/]));
      setNameColumn(guessColumn(head, [/^(full\s*)?name$/, /^name of/, /^(student|grantee|scholar|awardee)('?s)?\s*name$/]));
    } catch (err) {
      if (isCancel(err)) {
        toast.info("Reading cancelled. Nothing was saved. The file is still chosen: press Read file to try again.");
        return;
      }
      toast.error(errMsg(err, err.message || "The file could not be read."));
      setFileKey((k) => k + 1);
    } finally {
      readControl.current = null;
      setReading(false);
    }
  }

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
    setPendingFile(null);
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
      const money = (text) => {
        const plain = String(text ?? "").replaceAll(",", "").trim();
        return /^\d+(\.\d+)?$/.test(plain) ? plain : null;
      };
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
        processing_batch_id: batchId === "" || batchId === "new" ? null : Number(batchId),
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

  const hasNames = nameColumn !== "" || headers.some((h) => /^(last\s*name|surname)$/i.test(h.trim()));
  const ready = scholarshipId && agency.trim() && fileName && (idColumn !== "" || hasNames) && dataRows.length > 0 && batchId !== "new";
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
              <option value="">Use this program's open batch (or make a new one)</option>
              {programBatches.map((b) => (
                <option key={b.id} value={String(b.id)}>
                  {b.name}
                </option>
              ))}
              <option value="new">+ New batch for this list…</option>
            </select>
            {batchId === "new" && (
              <NewBatchForm
                program={scholarships.find((s) => String(s.id) === scholarshipId)}
                schoolYear={details.school_year || defaults.current_school_year || ""}
                semester={details.semester || defaults.current_semester || ""}
                agencyBatch={details.batch}
                onCancel={() => setBatchId("")}
                onCreated={(batch) => {
                  setOpenBatches((old) => [...old, batch]);
                  setBatchId(String(batch.id));
                }}
              />
            )}
            <span className="muted small">
              Students added from this list go into this batch. Manage batches in{" "}
              <Link to="/staff/batches">Processing Batches</Link>.
            </span>
          </div>

          <div className="full-column">
            <label htmlFor="agency-file">Approved list (Excel, CSV, Word or PDF)</label>
            <div className="button-row">
              <FileDrop hint="or drag the list file here">
                <input id="agency-file" key={fileKey} type="file" accept={LIST_ACCEPT} onChange={chooseFile} disabled={reading} />
              </FileDrop>
              <button aria-busy={reading}
                type="button"
                className="button button-primary"
                onClick={readFile}
                disabled={!pendingFile || reading}
              >
                {reading ? "Reading..." : "Read file"}
              </button>
              {reading && (
                <button type="button" className="button button-danger-outline" onClick={() => readControl.current?.abort()}>
                  Cancel reading
                </button>
              )}
              {pendingFile && !reading && (
                <button
                  type="button"
                  className="button button-secondary"
                  onClick={() => {
                    setPendingFile(null);
                    setFileKey((k) => k + 1);
                  }}
                >
                  Remove chosen file
                </button>
              )}
            </div>
            {pendingFile && !reading && !rows.length && (
              <p className="small">
                Chosen: <strong>{pendingFile.name}</strong> ({Math.max(1, Math.round(pendingFile.size / 1024))} KB). Press{" "}
                <strong>Read file</strong> to read it. Nothing is saved until you check the rows and press Process.
              </p>
            )}
            <p className="muted small">
              Excel (.xlsx), CSV, Word (.docx), PDF or a photo. PDFs and photos are read by the AI service, so keep it
              running for those. The list needs a column with the Student ID; an approval date column is optional. Old
              .xls/.doc files: open them and Save As .xlsx/.docx first.
            </p>
            {reading && <ReadingProgress fileName={readingName} />}
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
              ["batch", "Agency batch number", "select"],
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
                    {[...new Set([...yearChoices(defaults.current_school_year), details.school_year].filter(Boolean))].map((year) =>
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
                    {SEMESTERS.map((term) => <option key={term}>{term}</option>)}
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
            <button aria-busy={processing} className="button button-primary" onClick={() => process()} disabled={!ready || processing || ((countMismatch || amountMismatch) && !confirmedMismatch)}>
              {processing ? "Processing..." : `4. Process ${dataRows.length} rows`}
            </button>
          </div>
        </section>
      )}

      {result && (
        <p className="button-row">
          <button
            className="button button-secondary"
            onClick={() => {
              setResult(null);
              window.scrollTo({ top: 0, behavior: "smooth" });
            }}
          >
            Upload another list
          </button>
          <span className="muted small">Each list keeps its own batch; another program or batch can be processed while this one is still open.</span>
        </p>
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
  const toast = useToast();
  const [linking, setLinking] = useState(null);
  const [settingAside, setSettingAside] = useState(null);
  const [chosen, setChosen] = useState([]);

  async function resume(line) {
    try {
      const { data } = await api.post(`/staff/agency-lists/${upload.id}/rows/${line.row}/resume`);
      toast.success(data.message);
      onChanged(data.upload);
    } catch (err) {
      toast.error(errMsg(err, "Unable to open this row again."));
    }
  }

  const order = { error: 0, check: 1, unmatched: 2, created: 3, approved: 4, already: 5 };
  const sorted = [...(upload.details || [])].sort(
    (a, b) => Number(openRow(b)) - Number(openRow(a)) || order[a.result] - order[b.result] || a.row - b.row
  );
  const canAct = Boolean(onChanged) && upload.processing_batch?.status !== "completed";
  const openLines = sorted.filter(openRow);
  const chosenLines = openLines.filter((l) => chosen.includes(l.row));

  return (
    <>
      {canAct && openLines.length > 1 && (
        <div className="button-row selection-bar">
          <span className="muted small">{chosenLines.length} open row(s) selected</span>
          <button className="button button-small button-secondary" disabled={!chosenLines.length} onClick={() => setSettingAside(chosenLines)}>
            Set aside selected (same reason)
          </button>
          <span className="muted small">Rows with different reasons: set them aside one by one.</span>
        </div>
      )}
      <div className="table-wrapper">
        <table className="numbered">
          <thead>
            <tr>
              {canAct && openLines.length > 1 && <th></th>}
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
                {canAct && openLines.length > 1 && (
                  <td>
                    {openRow(line) && (
                      <input
                        type="checkbox"
                        aria-label={`Select row ${line.row}`}
                        checked={chosen.includes(line.row)}
                        onChange={() => setChosen((old) => (old.includes(line.row) ? old.filter((r) => r !== line.row) : [...old, line.row]))}
                      />
                    )}
                  </td>
                )}
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
                        <button className="button button-small button-secondary" onClick={() => setSettingAside([line])}>
                          Set aside
                        </button>
                      </div>
                    )}
                    {line.set_aside && (
                      <button className="link-button small" onClick={() => resume(line)}>
                        Open again
                      </button>
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
          lines={settingAside}
          onClose={() => setSettingAside(null)}
          onDone={(fresh) => {
            setSettingAside(null);
            setChosen([]);
            onChanged(fresh);
          }}
        />
      )}
    </>
  );
}

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
          <table className="numbered">
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

const ASIDE_REASONS = [
  "Not a CSU student: the Student ID is not in the system.",
  "The Student ID on the list looks wrong; asked the agency for a correction.",
  "Duplicate row on the agency's list.",
  "The name on the list does not match the student with this ID; checking with the agency.",
  "The student already holds another active scholarship; referred to the OAS head for a decision.",
  "Waiting for the agency's corrected list.",
];

function suggestReason(line) {
  const text = `${line?.note || ""} ${line?.message || ""}`.toLowerCase();
  if (/twice|duplicate|more than once/.test(text)) return ASIDE_REASONS[2];
  if (/name differs|does not match|different name|name on the list/.test(text)) return ASIDE_REASONS[3];
  if (/scholarship|grantee/.test(text) && line?.result === "error") return ASIDE_REASONS[4];
  if (line?.result === "unmatched") return ASIDE_REASONS[0];
  return "";
}

function SetAsideModal({ upload, lines, onClose, onDone }) {
  const toast = useToast();
  const [note, setNote] = useState(() => suggestReason(lines[0]));
  const [saving, setSaving] = useState(false);
  const several = lines.length > 1;

  async function save() {
    setSaving(true);
    try {
      const { data } = several
        ? await api.post(`/staff/agency-lists/${upload.id}/set-aside`, { rows: lines.map((l) => l.row), note: note.trim() })
        : await api.post(`/staff/agency-lists/${upload.id}/rows/${lines[0].row}/set-aside`, { note: note.trim() });
      toast.success(data.message);
      onDone(data.upload);
    } catch (err) {
      toast.error(errMsg(err, "Unable to set aside."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={several ? `Set aside ${lines.length} rows (same reason)` : `Set aside row ${lines[0].row}`}
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
        The row{several ? "s stay" : " stays"} on the list as {several ? "they are" : "it is"}, with your reason. Nothing is
        matched or approved. You can open {several ? "them" : "it"} again later when the problem is solved.
      </p>
      {several && <p className="small">Rows: {lines.map((l) => l.row).join(", ")}</p>}
      <label htmlFor="aside-template">Common reasons</label>
      <select id="aside-template" value="" onChange={(event) => event.target.value && setNote(event.target.value)}>
        <option value="">Choose a common reason…</option>
        {ASIDE_REASONS.map((reason) => (
          <option key={reason} value={reason}>
            {reason}
          </option>
        ))}
      </select>
      <label htmlFor="aside-note">Reason {note && suggestReason(lines[0]) === note ? "(suggested from what the system found; edit if needed)" : ""}</label>
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

      <DoneControl upload={upload} onChanged={onChanged} />
      <Details upload={upload} onChanged={onChanged} />
    </section>
  );
}

function DoneControl({ upload, onChanged }) {
  const toast = useToast();
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  if (!onChanged) return null;

  async function mark(done) {
    setBusy(true);
    try {
      const { data } = await api.post(`/staff/agency-lists/${upload.id}/done`, { done, note: note.trim() || null });
      toast.success(data.message);
      setNote("");
      onChanged(data.upload);
    } catch (err) {
      toast.error(errMsg(err, "Unable to change the status of this list."));
    } finally {
      setBusy(false);
    }
  }

  if (upload.done_at) {
    return (
      <div className="ai-note ok small">
        ✓ Done processing on {formatDateTime(upload.done_at)}{upload.done_note ? ` (${upload.done_note})` : ""}.{" "}
        <button className="link-button small" onClick={() => mark(false)} disabled={busy}>Mark as not done</button>
      </div>
    );
  }

  return (
    <div className="button-row done-row">
      {upload.open_rows > 0 && (
        <input
          aria-label="Note (needed while rows are open)"
          placeholder={`${upload.open_rows} row(s) still open: write why the list is done anyway`}
          value={note}
          maxLength={500}
          onChange={(event) => setNote(event.target.value)}
        />
      )}
      <button className="button button-small button-primary" onClick={() => mark(true)} disabled={busy || (upload.open_rows > 0 && !note.trim())}>
        {busy ? "Saving..." : "Done processing"}
      </button>
      <span className="muted small">Marks this list finished. No application changes; you can still open it later.</span>
    </div>
  );
}

function listState(item) {
  if (item.done_at) return ["Done", "success"];
  if (item.open_rows > 0) return ["Needs attention", "warning"];
  return ["Processed, not marked done", "neutral"];
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
              <th>Status</th>
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
                  <span className={`status status-${listState(item)[1]}`}>{listState(item)[0]}</span>
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
          <DoneControl upload={open} onChanged={reopen} />
          <Details upload={open} onChanged={reopen} />
        </Modal>
      )}
    </>
  );
}

function ReadingProgress({ fileName }) {
  const [seconds, setSeconds] = useState(0);
  useEffect(() => {
    const timer = window.setInterval(() => setSeconds((s) => s + 1), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const scanned = /\.(pdf|png|jpe?g)$/i.test(fileName || "");
  return (
    <div className="reading-panel" role="status" aria-live="polite">
      <span className="spinner" aria-hidden="true" />
      <div>
        <strong>Reading and organizing “{fileName}”…</strong> <span className="muted small">{seconds} s</span>
        <ol className="small">
          <li>Sending the file to be read</li>
          <li>{scanned ? "Reading each page (scans and photos are read with OCR: about a minute per page)" : "Reading the rows and columns"}</li>
          <li>Putting the values into rows and columns, and marking cells to check</li>
        </ol>
        <span className="muted small">
          Nothing is saved yet: you will see every row and choose which column is which before you press Process.
          {seconds > 90 && scanned ? " Long scanned files take a few minutes; keep this page open." : ""}
        </span>
      </div>
    </div>
  );
}

function NewBatchForm({ program, schoolYear, semester, agencyBatch, onCancel, onCreated }) {
  const toast = useToast();
  const [year, setYear] = useState(schoolYear);
  const [term, setTerm] = useState(semester);
  const [number, setNumber] = useState(String(agencyBatch || "").replace(/\D/g, ""));
  const [busy, setBusy] = useState(false);
  const yearOk = /^\d{4}-\d{4}$/.test(year) && Number(year.slice(5)) === Number(year.slice(0, 4)) + 1;
  const name = [program?.short_name || program?.name, term, yearOk ? `AY ${year}` : "", number ? `Batch ${number}` : ""]
    .filter(Boolean)
    .join(" - ");

  async function create() {
    setBusy(true);
    try {
      const { data } = await api.post("/staff/processing-batches", {
        scholarship_id: program.id,
        school_year: year,
        semester: term || null,
        name,
      });
      toast.success(data.message);
      onCreated(data.batch);
    } catch (err) {
      toast.error(errMsg(err, "Unable to create the batch."));
    } finally {
      setBusy(false);
    }
  }

  if (!program) return <p className="small warning">Choose the program first.</p>;

  return (
    <div className="new-batch">
      <div className="form-grid">
        <div>
          <label htmlFor="nb-year">Academic year</label>
          <select id="nb-year" value={year} onChange={(event) => setYear(event.target.value)}>
            <option value="">Choose</option>
            {yearChoices(schoolYear).map((y) => (
              <option key={y} value={y}>
                AY {y}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="nb-term">Semester</label>
          <select id="nb-term" value={term} onChange={(event) => setTerm(event.target.value)}>
            <option value="">Whole year</option>
            {SEMESTERS.map((t) => (
              <option key={t}>{t}</option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="nb-number">Agency batch number (optional)</label>
          <input id="nb-number" inputMode="numeric" value={number} maxLength={3} onChange={(event) => setNumber(event.target.value.replace(/\D/g, ""))} placeholder="e.g. 2" />
        </div>
      </div>
      <p className="small">
        New batch: <strong>{name || "—"}</strong>
      </p>
      <div className="button-row">
        <button type="button" className="button button-small button-primary" onClick={create} disabled={busy || !yearOk}>
          {busy ? "Creating..." : "Create batch"}
        </button>
        <button type="button" className="button button-small button-secondary" onClick={onCancel} disabled={busy}>
          Cancel
        </button>
      </div>
    </div>
  );
}
