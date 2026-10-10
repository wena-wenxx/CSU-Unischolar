import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { parseCSV } from "../../lib/csv";
import { downloadCSV, formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import Modal from "../../components/Modal";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const RESULT_LABELS = {
  approved: ["Approved", "success"],
  created: ["New approved application", "success"],
  already: ["Already approved", "neutral"],
  unmatched: ["Unmatched", "warning"],
  error: ["Error", "danger"],
};

// Guess which column holds what, from the header names.
function guessColumn(headers, patterns) {
  const index = headers.findIndex((h) => patterns.some((p) => p.test(h.toLowerCase())));
  return index >= 0 ? String(index) : "";
}

/*
  Upload the approved list an agency sends back (CHED, DOST, LGU, foundation).
  1. Choose the program and the CSV file.
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
  const [hasHeader, setHasHeader] = useState(true);
  const [idColumn, setIdColumn] = useState("");
  const [dateColumn, setDateColumn] = useState("");
  const [fileKey, setFileKey] = useState(0);
  const [processing, setProcessing] = useState(false);
  const [result, setResult] = useState(null);

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      Promise.all([api.get("/scholarships"), api.get("/staff/agency-lists")])
        .then(([programs, uploads]) => {
          setScholarships(programs.data);
          setHistory(uploads.data);
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

  function readFile(event) {
    const file = event.target.files?.[0];
    setResult(null);

    if (!file) return;

    if (!/\.csv$/i.test(file.name)) {
      toast.error("Please choose a .csv file. In Excel: File → Save As → CSV (Comma delimited).");
      setFileKey((k) => k + 1);
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      const parsed = parseCSV(String(reader.result || ""));

      if (parsed.length === 0) {
        toast.error("The file is empty.");
        return;
      }

      setRows(parsed);
      setFileName(file.name);
      const head = parsed[0].map((h) => h.trim());
      setIdColumn(guessColumn(head, [/student.?(id|no|num)/, /id.?(no|num)/, /^stud/, /^id$/]));
      setDateColumn(guessColumn(head, [/approv.*date/, /date/]));
    };
    reader.readAsText(file);
  }

  function reset() {
    setRows([]);
    setFileName("");
    setIdColumn("");
    setDateColumn("");
    setFileKey((k) => k + 1);
  }

  async function process() {
    const program = scholarships.find((s) => String(s.id) === scholarshipId);

    const ok = await confirm({
      title: `Process ${dataRows.length} rows?`,
      message: `Students on this list will be marked approved for "${program?.name}". Applications that are rejected, drafts, or students who already hold another active scholarship are NOT changed; they are listed as errors for you to check.`,
      confirmLabel: "Process list",
    });

    if (!ok) return;

    setProcessing(true);

    try {
      const payload = {
        scholarship_id: Number(scholarshipId),
        agency_name: agency.trim(),
        file_name: fileName,
        rows: dataRows.map((row, i) => ({
          row: firstDataLine + i,
          student_id: (row[Number(idColumn)] || "").trim(),
          approval_date: dateColumn === "" ? null : (row[Number(dateColumn)] || "").trim() || null,
        })),
      };

      const { data } = await api.post("/staff/agency-lists", payload);
      toast.success(data.message);
      setResult(data.upload);
      reset();
      load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to process the list."));
    } finally {
      setProcessing(false);
    }
  }

  function downloadTemplate() {
    downloadCSV("agency-approved-list-template.csv", [
      { Student_ID: "2026-00001", Last_Name: "Student", First_Name: "Juan", Approval_Date: "2026-10-15" },
    ]);
  }

  if (!scholarships) return <Loading />;

  const ready = scholarshipId && agency.trim() && fileName && idColumn !== "" && dataRows.length > 0;

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
            <label htmlFor="agency-file">Approved list (CSV file)</label>
            <input id="agency-file" key={fileKey} type="file" accept=".csv,text/csv" onChange={readFile} />
            <p className="muted small">
              Got an Excel file? Open it in Excel → File → Save As → <strong>CSV (Comma delimited)</strong>. The file
              needs at least one column with the Student ID. An approval date column is optional.
            </p>
          </div>
        </div>
      </section>

      {rows.length > 0 && (
        <section className="card">
          <h2>2. Which column is which?</h2>

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
            {fileName}: {dataRows.length} student row{dataRows.length === 1 ? "" : "s"}. First rows:
          </p>

          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Row</th>
                  {headers.map((h, i) => (
                    <th key={i} className={String(i) === idColumn ? "col-picked" : ""}>
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {dataRows.slice(0, 5).map((row, r) => (
                  <tr key={r}>
                    <td>{firstDataLine + r}</td>
                    {headers.map((_, i) => (
                      <td key={i} className={String(i) === idColumn ? "col-picked" : ""}>
                        {row[i]}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="button-row">
            <button className="button button-secondary" onClick={reset}>
              Choose another file
            </button>
            <button className="button button-primary" onClick={process} disabled={!ready || processing}>
              {processing ? "Processing..." : `3. Process ${dataRows.length} rows`}
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
  const order = { error: 0, unmatched: 1, created: 2, approved: 3, already: 4 };
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
            {open.scholarship?.name} · {open.agency_name} · {formatDateTime(open.created_at)} by {open.uploader?.name}
          </p>
          <Summary upload={open} />
          <Details details={open.details} />
        </Modal>
      )}
    </>
  );
}
