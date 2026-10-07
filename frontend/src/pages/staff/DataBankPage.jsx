import { useEffect, useState } from "react";
import { useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { enrollmentText, formatDate, formatMoney, fullName, statusClass, statusLabel } from "../../lib/format";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";
import EmptyState from "../../components/EmptyState";
import Modal from "../../components/Modal";

/*
  Scholarship Data Bank (staff): one place to search any student and see
  every application, scholarship and payroll entry they have ever had.
*/
export default function DataBankPage() {
  const toast = useToast();

  const [query, setQuery] = useState("");
  const [results, setResults] = useState([]);
  const [searched, setSearched] = useState(false);
  const [loading, setLoading] = useState(false);
  const [student, setStudent] = useState(null);

  // Asks the server for students matching the search box (empty = everyone).
  function fetchStudents(q) {
    return api
      .get("/staff/data-bank", { params: { q } })
      .then((response) => {
        setResults(response.data);
        setSearched(true);
      })
      .catch((err) => toast.error(errMsg(err, "Search failed.")));
  }

  async function search(e) {
    e?.preventDefault();
    setLoading(true);
    await fetchStudents(query);
    setLoading(false);
  }

  // .then() (not await): this also runs from the page-load effect below.
  function openStudent(id) {
    return api
      .get(`/staff/data-bank/${id}`)
      .then((response) => setStudent(response.data))
      .catch((err) => toast.error(errMsg(err, "Unable to load student history.")));
  }

  const location = useLocation();

  // Show the first students as soon as the page opens. A link with
  // ?student=<id> (from the search box) also opens that student's history.
  useEffect(() => {
    fetchStudents("");
    const wanted = new URLSearchParams(location.search).get("student");
    if (wanted) openStudent(wanted);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div>
      <PageHeader
        title="Scholarship Data Bank"
        subtitle="Search any student and see their complete scholarship history"
      />

      <section className="card">
        <form className="button-row" onSubmit={search}>
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Student ID, name or course (e.g. 2026-00001 or Juan)"
          />

          <button className="button button-primary" disabled={loading}>
            {loading ? "Searching..." : "Search"}
          </button>
        </form>

        
        {searched && results.length === 0 ? (
          <EmptyState message="No students matched your search." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student ID</th>
                  <th>Name</th>
                  <th>Course</th>
                  <th>Applications</th>
                  <th>Scholarship history</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {results.map((item) => (
                  <tr key={item.id}>
                    <td>{item.student_id}</td>

                    <td>{fullName(item)}</td>

                    <td>{item.course || "—"}</td>

                    <td>{item.applications?.length || 0}</td>

                    <td>
                      {item.scholar_records?.length ? (
                        item.scholar_records.map((record) => (
                          <div key={record.id}>
                            {record.scholarship?.name}{" "}
                            <span className={statusClass(record.status)}>
                              {record.status}
                            </span>
                          </div>
                        ))
                      ) : (
                        <span className="muted">None</span>
                      )}
                    </td>

                    <td>
                      <button
                        className="button button-small"
                        onClick={() => openStudent(item.id)}
                      >
                        Full history
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {results.length === 50 && (
          <p className="muted">Showing the first 50 matches. Type more to narrow the search.</p>
        )}
      </section>

      {student && (
        <Modal
          size="large"
          title={fullName(student)}
          subtitle={
            student.has_active_scholarship ? (
              <span className="status status-success">Has an active scholarship</span>
            ) : (
              <span className="status status-neutral">No active scholarship</span>
            )
          }
          onClose={() => setStudent(null)}
        >
            <div className="detail-grid">
              <ProfileItem label="Student ID" value={student.student_id} />
              <ProfileItem label="Course" value={student.course} />
              <ProfileItem label="Year Level" value={student.year_level} />
              <ProfileItem label="College" value={student.college} />
              <ProfileItem label="Contact" value={student.contact_number} />
              <ProfileItem label="Email" value={student.user?.email} />
            </div>

            <h3>Scholarships (grantee records)</h3>

            {student.scholar_records?.length ? (
              <div className="table-wrapper">
                <table>
                  <thead>
                    <tr>
                      <th>Scholarship</th>
                      <th>Status</th>
                      <th>Tagged</th>
                      <th>Enrolled</th>
                      <th>Payroll entries</th>
                    </tr>
                  </thead>

                  <tbody>
                    {student.scholar_records.map((record) => (
                      <tr key={record.id}>
                        <td>{record.scholarship?.name}</td>
                        <td>
                          <span className={statusClass(record.status)}>
                            {record.status}
                          </span>
                        </td>
                        <td>{formatDate(record.grantee_tagged_at)}</td>
                        <td>{record.currently_enrolled ? "Yes" : "No"}</td>
                        <td>
                          {record.payroll_records?.length
                            ? record.payroll_records
                                .map(
                                  (item) =>
                                    `${item.period}: ${formatMoney(item.amount)} (${item.status})`
                                )
                                .join("; ")
                            : "None"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <EmptyState message="Never tagged as a grantee." />
            )}

            <h3>Applications</h3>

            {student.applications?.length ? (
              <div className="table-wrapper">
                <table>
                  <thead>
                    <tr>
                      <th>Scholarship</th>
                      <th>Status</th>
                      <th>Submitted</th>
                      <th>Enrollment</th>
                      <th>Documents</th>
                    </tr>
                  </thead>

                  <tbody>
                    {student.applications.map((application) => (
                      <tr key={application.id}>
                        <td>{application.scholarship?.name}</td>
                        <td>
                          <span className={statusClass(application.status)}>
                            {statusLabel(application.status)}
                          </span>
                        </td>
                        <td>{formatDate(application.submitted_at)}</td>
                        <td>{enrollmentText(application)}</td>
                        <td>
                          {application.documents?.length || 0} uploaded
                          {application.documents?.some(
                            (document) => document.status === "flagged"
                          )
                            ? " · AI flagged"
                            : ""}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <EmptyState message="No applications." />
            )}
        </Modal>
      )}
    </div>
  );
}
