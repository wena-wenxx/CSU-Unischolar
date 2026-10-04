import { useEffect, useState } from "react";
import api, { errMsg } from "../../services/api";
import { downloadCSV, formatDate, fullName, missingRequirements } from "../../lib/format";
import { useToast } from "../../components/Toast";
import PageHeader from "../../components/PageHeader";
import Loading from "../../components/Loading";

/* CSV exports that open in Excel (OAS's current workflow uses Excel). */
export default function StaffReportsPage() {
  const toast = useToast();

  const [data, setData] = useState(null);

  useEffect(() => {
    Promise.all([
      api.get("/applications"),
      api.get("/scholar-records"),
      api.get("/payroll"),
      api.get("/scholarships"),
    ])
      .then(([applications, scholars, payroll, scholarships]) =>
        setData({
          applications: applications.data,
          scholars: scholars.data,
          payroll: payroll.data,
          scholarships: scholarships.data,
        })
      )
      .catch((err) => {
        toast.error(errMsg(err, "Unable to load report data."));
        setData({ applications: [], scholars: [], payroll: [], scholarships: [] });
      });
  }, [toast]);

  if (!data) return <Loading />;

  function exportFile(filename, rows) {
    if (downloadCSV(filename, rows)) toast.success(`Downloaded ${filename}`);
    else toast.info("There is no data to export yet.");
  }

  const reports = [
    {
      title: "Application Report",
      count: data.applications.length,
      file: "scholarship-applications.csv",
      rows: () =>
        data.applications.map((item) => ({
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
          Status: item.status || "",
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
      title: "Scholar Report",
      count: data.scholars.length,
      file: "scholar-records.csv",
      rows: () =>
        data.scholars.map((item) => ({
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
      count: data.payroll.length,
      file: "payroll-report.csv",
      rows: () =>
        data.payroll.map((item) => ({
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
      count: data.scholarships.length,
      file: "scholarship-programs.csv",
      rows: () =>
        data.scholarships.map((item) => ({
          ID: item.id,
          Name: item.name,
          Provider: item.provider || "",
          Amount: item.amount || "",
          Requirements: (item.requirements || []).map((r) => r.name).join("; "),
          Status: item.status || "",
        })),
    },
  ];

  return (
    <div>
      <PageHeader title="Reports" subtitle="Export scholarship management data to Excel (CSV)" />

      <div className="card-grid">
        {reports.map((report) => (
          <div className="card" key={report.file}>
            <h2>{report.title}</h2>

            <div className="report-count">{report.count}</div>

            <button className="button button-primary" onClick={() => exportFile(report.file, report.rows())}>
              Export CSV
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}
