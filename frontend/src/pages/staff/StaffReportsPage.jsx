import { useEffect, useState } from "react";
import api, { errMsg } from "../../services/api";
import { downloadCSV, formatDate, formatDateTime, fullName, missingRequirements, statusLabel } from "../../lib/format";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import Loading from "../../components/Loading";

const STATUSES = ["submitted", "under_review", "needs_action", "complete", "approved", "rejected", "draft"];

// "CHED Merit Scholarship Program (CMSP)" -> "CMSP"; otherwise a short slug of the name.
function programCode(name) {
  if (!name) return "all-programs";
  const inBrackets = name.match(/\(([^)]+)\)/);
  const text = inBrackets ? inBrackets[1] : name;

  return text
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^A-Za-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 40);
}

const stamp = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" }); // 2026-10-06

/* CSV exports that open in Excel (OAS's current workflow uses Excel). */
export default function StaffReportsPage() {
  const toast = useToast();

  const [data, setData] = useState(null);
  const [programId, setProgramId] = useState("all");
  const [status, setStatus] = useState("all");

  useEffect(() => {
    Promise.all([
      api.get("/applications"),
      api.get("/scholar-records"),
      api.get("/payroll"),
      api.get("/scholarships"),
      api.get("/staff/email-logs").catch(() => ({ data: [] })),
    ])
      .then(([applications, scholars, payroll, scholarships, emails]) =>
        setData({
          applications: applications.data,
          scholars: scholars.data,
          payroll: payroll.data,
          scholarships: scholarships.data,
          emails: emails.data,
        })
      )
      .catch((err) => {
        toast.error(errMsg(err, "Unable to load report data."));
        setData({ applications: [], scholars: [], payroll: [], scholarships: [], emails: [] });
      });
  }, [toast]);

  if (!data) return <Loading />;

  const program = data.scholarships.find((s) => String(s.id) === programId) || null;
  const code = programCode(program?.name);
  const inProgram = (scholarshipId) => programId === "all" || String(scholarshipId) === programId;

  const applications = data.applications
    .filter((a) => inProgram(a.scholarship_id))
    .filter((a) => status === "all" || a.status === status);
  const scholars = data.scholars.filter((r) => inProgram(r.scholarship_id));
  const payroll = data.payroll.filter((p) => inProgram(p.scholar_record?.scholarship_id));

  // Applications already forwarded to the agency (or decided by it), for the agency list.
  const forAgency = data.applications.filter(
    (a) => inProgram(a.scholarship_id) && ["complete", "approved"].includes(a.status)
  );

  function exportFile(filename, rows) {
    if (downloadCSV(filename, rows)) toast.success(`Downloaded ${filename}`);
    else toast.info("There is no data to export for this selection.");
  }

  const statusPart = status === "all" ? "" : `-${status.replaceAll("_", "-")}`;

  const reports = [
    {
      title: "Application Report",
      note: "Every application in the selection, with missing documents and AI flags.",
      count: applications.length,
      file: `applications-${code}${statusPart}-${stamp()}.csv`,
      rows: () =>
        applications.map((item) => ({
          ID: item.id,
          Student_ID: item.student?.student_id || "",
          Last_Name: item.student?.last_name || "",
          First_Name: item.student?.first_name || "",
          Middle_Name: item.student?.middle_name || "",
          Course: item.student?.course || "",
          Year_Level: item.student?.year_level || "",
          College: item.student?.college || "",
          Contact_Number: item.student?.contact_number || "",
          Scholarship: item.scholarship?.name || "",
          Status: statusLabel(item.status),
          Missing_Documents: missingRequirements(item)
            .map((requirement) => requirement.name)
            .join("; "),
          AI_Flagged_Documents: (item.documents || []).filter((d) => d.status === "flagged").length,
          Submitted: item.submitted_at ? formatDate(item.submitted_at) : "",
          Enrollment_Verified: item.enrollment_verified ? "Yes" : "No",
          Remarks: item.remarks || "",
        })),
    },
    {
      title: "List for the Agency",
      note:
        "Students whose documents are complete or who were approved, in a simple numbered list to send to the agency. Generic format; use the agency's own template if it has one.",
      count: forAgency.length,
      file: `agency-list-${code}-${stamp()}.csv`,
      needsProgram: true,
      rows: () =>
        [...forAgency]
          .sort((a, b) =>
            `${a.student?.last_name} ${a.student?.first_name}`.localeCompare(`${b.student?.last_name} ${b.student?.first_name}`)
          )
          .map((item, index) => ({
            No: index + 1,
            Student_ID: item.student?.student_id || "",
            Last_Name: item.student?.last_name || "",
            First_Name: item.student?.first_name || "",
            Middle_Name: item.student?.middle_name || "",
            Course: item.student?.course || "",
            Year_Level: item.student?.year_level || "",
            College: item.student?.college || "",
            Contact_Number: item.student?.contact_number || "",
            Scholarship: item.scholarship?.name || "",
            Status_at_OAS: statusLabel(item.status),
            Date_Submitted: item.submitted_at ? formatDate(item.submitted_at) : "",
          })),
    },
    {
      title: "Scholar Report",
      note: "Grantees (active, completed and inactive) in the selected program.",
      count: scholars.length,
      file: `scholars-${code}-${stamp()}.csv`,
      rows: () =>
        scholars.map((item) => ({
          ID: item.id,
          Student_ID: item.student?.student_id || "",
          Student: fullName(item.student),
          Course: item.student?.course || "",
          Scholarship: item.scholarship?.name || "",
          Status: item.status || "",
          Enrolled: item.currently_enrolled ? "Yes" : "No",
          ATM: item.has_atm ? "Yes" : "No",
          Tagged: item.grantee_tagged_at ? formatDate(item.grantee_tagged_at) : "",
        })),
    },
    {
      title: "Payroll Report",
      note: "Payroll entries in the selected program (all periods).",
      count: payroll.length,
      file: `payroll-${code}-${stamp()}.csv`,
      rows: () =>
        payroll.map((item) => ({
          ID: item.id,
          Student_ID: item.scholar_record?.student?.student_id || "",
          Student: fullName(item.scholar_record?.student),
          Course: item.scholar_record?.student?.course || "",
          Scholarship: item.scholar_record?.scholarship?.name || "",
          Amount: item.amount,
          Period: item.period,
          ATM_Status: item.bank_atm_status || "",
          Status: item.status || "",
          Signature: item.signature || "",
        })),
    },
    {
      title: "Scholarship Programs",
      note: "All programs with their requirements (not filtered).",
      count: data.scholarships.length,
      file: `scholarship-programs-${stamp()}.csv`,
      rows: () =>
        data.scholarships.map((item) => ({
          ID: item.id,
          Name: item.name,
          Type: item.category || "",
          Provider: item.provider || "",
          Amount: item.amount || "",
          Applications_Open: item.application_start || "",
          Deadline: item.application_end || "",
          Requirements: (item.requirements || []).map((r) => r.name).join("; "),
          Status: item.status || "",
        })),
    },
  ];

  return (
    <div>
      <PageHeader title="Reports" subtitle="Export scholarship data to Excel (CSV), for all programs or one program" />

      <div className="card filter-card">
        <div className="form-grid">
          <div>
            <label htmlFor="report-program">Scholarship program</label>
            <select id="report-program" value={programId} onChange={(event) => setProgramId(event.target.value)}>
              <option value="all">All programs</option>
              {data.scholarships.map((s) => (
                <option key={s.id} value={String(s.id)}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="report-status">Application status (Application Report)</label>
            <select id="report-status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="all">All statuses</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {statusLabel(s)}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      <div className="card-grid">
        {reports.map((report) => {
          const blocked = report.needsProgram && programId === "all";

          return (
            <div className="card report-card" key={report.title}>
              <h2>{report.title}</h2>
              <p className="muted small">{report.note}</p>

              <div className="report-count">{blocked ? "—" : report.count}</div>

              <button
                className="button button-primary"
                onClick={() => exportFile(report.file, report.rows())}
                disabled={blocked}
              >
                Export CSV
              </button>

              <small className="muted report-file">{blocked ? "Choose one program first" : report.file}</small>
            </div>
          );
        })}
      </div>

      <section className="card" id="emails">
        <div className="card-header">
          <h2>E-mails sent</h2>
          <span className="muted">{data.emails.length} most recent</span>
        </div>

        <p className="muted small">
          Students get an e-mail when their application is approved (by review or by an agency list). Demo setting:
          e-mails are written to the server log or caught by a Mailtrap test inbox, never delivered to real addresses.
        </p>

        {data.emails.length === 0 ? (
          <p className="muted">No e-mails yet.</p>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>When</th>
                  <th>Student</th>
                  <th>To</th>
                  <th>Subject</th>
                  <th>From</th>
                  <th>Result</th>
                </tr>
              </thead>
              <tbody>
                {data.emails.map((item) => (
                  <tr key={item.id}>
                    <td>{formatDateTime(item.created_at)}</td>
                    <td>
                      {fullName(item.student)} ({item.student?.student_id})
                    </td>
                    <td>{item.to_email}</td>
                    <td>{item.subject}</td>
                    <td>{item.trigger === "agency_list" ? "Agency list" : "Review"}</td>
                    <td>
                      <span
                        className={`status status-${item.status === "sent" ? "success" : item.status === "failed" ? "danger" : "warning"}`}
                        title={item.error || ""}
                      >
                        {item.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
