import { useEffect, useState } from "react";
import { useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { downloadCSV, enrollmentText, formatDate, formatMoney, fullName, statusClass, statusLabel } from "../../lib/format";
import { gwaText, peso } from "../../lib/recommend";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";
import EmptyState from "../../components/EmptyState";
import Modal from "../../components/Modal";

/*
  Scholarship Data Bank (staff): one place to search any student and see
  every application, scholarship and payroll entry they have ever had.
  Alphabetical by last name, 25 at a time ("Show more"), with filters and
  a CSV export of every match.
*/
const STANDING = {
  "": "Any scholarship standing",
  active: "Holds a scholarship now",
  former: "Held one before (not now)",
  applied: "Applied, never a grantee",
  none: "Never applied",
  external: "Holds an outside scholarship",
};

const EMPTY_FILTERS = { college: "", year_level: "", sex: "", standing: "", scholarship_id: "", external_program: "" };
const stamp = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });

export default function DataBankPage() {
  const toast = useToast();

  const [query, setQuery] = useState("");
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const [results, setResults] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [options, setOptions] = useState({ colleges: [], year_levels: [], external_programs: [] });
  const [programs, setPrograms] = useState([]);
  const [searched, setSearched] = useState(false);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [student, setStudent] = useState(null);

  const params = (q, f, extra = {}) => {
    const out = { q, ...extra };
    Object.entries(f).forEach(([key, value]) => {
      if (value) out[key] = value;
    });
    return out;
  };

  // Asks the server for one page of students. Page 1 replaces the list;
  // later pages are added under it ("Show more").
  function fetchStudents(q, f, nextPage = 1) {
    return api
      .get("/staff/data-bank", { params: params(q, f, { page: nextPage }) })
      .then(({ data }) => {
        setResults((current) => (nextPage === 1 ? data.data : [...current, ...data.data]));
        setTotal(data.total);
        setPage(data.page);
        setHasMore(data.has_more);
        if (data.options) setOptions(data.options);
        setSearched(true);
      })
      .catch((err) => toast.error(errMsg(err, "Search failed.")));
  }

  async function search(e) {
    e?.preventDefault();
    setLoading(true);
    await fetchStudents(query, filters, 1);
    setLoading(false);
  }

  async function changeFilter(key, value) {
    // One "program" dropdown: ScholarGuide programs and outside scholarships ("ext:<name>").
    const next =
      key === "program"
        ? {
            ...filters,
            scholarship_id: value.startsWith("ext:") ? "" : value,
            external_program: value.startsWith("ext:") ? value.slice(4) : "",
          }
        : { ...filters, [key]: value };
    setFilters(next);
    setLoading(true);
    await fetchStudents(query, next, 1);
    setLoading(false);
  }

  async function clearAll() {
    setQuery("");
    setFilters(EMPTY_FILTERS);
    setLoading(true);
    await fetchStudents("", EMPTY_FILTERS, 1);
    setLoading(false);
  }

  async function showMore() {
    setLoading(true);
    await fetchStudents(query, filters, page + 1);
    setLoading(false);
  }

  async function exportCSV() {
    setExporting(true);
    try {
      const { data } = await api.get("/staff/data-bank", { params: params(query, filters, { export: 1 }) });
      const ok = downloadCSV(
        `data-bank-${stamp()}.csv`,
        data.data.map((s) => ({
          "Student ID": s.student_id,
          "Last name": s.last_name,
          "First name": s.first_name,
          "Middle name": s.middle_name || "",
          Sex: s.sex || "",
          Course: s.course || "",
          "Year level": s.year_level || "",
          College: s.college || "",
          "Contact number": s.contact_number || "",
          Email: s.user?.email || "",
          Applications: s.applications?.length || 0,
          "Active scholarship": (s.scholar_records || [])
            .filter((r) => r.status === "active")
            .map((r) => r.scholarship?.name)
            .join("; "),
          "Past scholarships": (s.scholar_records || [])
            .filter((r) => r.status !== "active")
            .map((r) => `${r.scholarship?.name} (${r.status})`)
            .join("; "),
          "Outside scholarship": (s.external_grantees || []).map((g) => g.program_name).join("; "),
          GWA: s.gwa ?? "",
          "Family income": s.family_income ?? "",
          "GWA / income checked by OAS": s.eligibility_verified_at ? "Yes" : "No",
        }))
      );
      if (ok) toast.success(`Exported ${data.data.length} students.`);
      else toast.info("Nothing to export.");
    } catch (err) {
      toast.error(errMsg(err, "Export failed."));
    } finally {
      setExporting(false);
    }
  }

  async function verifyData(verified) {
    try {
      const { data } = await api.post(`/staff/students/${student.id}/eligibility`, { verified });
      toast.success(data.message);
      await openStudent(student.id);
    } catch (err) {
      toast.error(errMsg(err, "Unable to change the verification."));
    }
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
    fetchStudents("", EMPTY_FILTERS, 1);
    api
      .get("/scholarships")
      .then((response) => setPrograms(response.data))
      .catch(() => setPrograms([]));
    const wanted = new URLSearchParams(location.search).get("student");
    if (wanted) openStudent(wanted);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const filtered = query.trim() !== "" || Object.values(filters).some(Boolean);

  return (
    <div>
      <PageHeader
        title="Data Bank"
        subtitle="Every student, A to Z by last name, with their complete scholarship history"
        actions={
          <button className="button button-secondary" onClick={exportCSV} disabled={exporting || total === 0}>
            {exporting ? "Exporting..." : `Export CSV (${total})`}
          </button>
        }
      />

      <section className="card">
        <form className="inline-form table-search" onSubmit={search}>
          <label htmlFor="bank-search" className="sr-only">
            Search students
          </label>
          <input
            id="bank-search"
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Student ID, name or course (e.g. 221-00462 or Contiga)"
          />

          <button className="button button-primary" disabled={loading}>
            {loading ? "Searching..." : "Search"}
          </button>
        </form>

        <div className="inline-form bank-filters">
          <label htmlFor="bank-college" className="sr-only">
            College
          </label>
          <select id="bank-college" value={filters.college} onChange={(e) => changeFilter("college", e.target.value)}>
            <option value="">All colleges</option>
            {options.colleges.map((college) => (
              <option key={college} value={college}>
                {college}
              </option>
            ))}
          </select>

          <label htmlFor="bank-year" className="sr-only">
            Year level
          </label>
          <select id="bank-year" value={filters.year_level} onChange={(e) => changeFilter("year_level", e.target.value)}>
            <option value="">All year levels</option>
            {options.year_levels.map((year) => (
              <option key={year} value={year}>
                {year}
              </option>
            ))}
          </select>

          <label htmlFor="bank-sex" className="sr-only">
            Sex
          </label>
          <select id="bank-sex" value={filters.sex} onChange={(e) => changeFilter("sex", e.target.value)}>
            <option value="">Female and male</option>
            <option value="Female">Female</option>
            <option value="Male">Male</option>
          </select>

          <label htmlFor="bank-standing" className="sr-only">
            Scholarship standing
          </label>
          <select id="bank-standing" value={filters.standing} onChange={(e) => changeFilter("standing", e.target.value)}>
            {Object.entries(STANDING).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>

          <label htmlFor="bank-program" className="sr-only">
            Scholarship program
          </label>
          <select
            id="bank-program"
            value={filters.external_program ? `ext:${filters.external_program}` : filters.scholarship_id}
            onChange={(e) => changeFilter("program", e.target.value)}
          >
            <option value="">Any program</option>
            <optgroup label="ScholarGuide programs">
              {programs.map((program) => (
                <option key={program.id} value={String(program.id)}>
                  {program.name}
                </option>
              ))}
            </optgroup>
            {options.external_programs?.length > 0 && (
              <optgroup label="Outside scholarships (External Grantees)">
                {options.external_programs.map((name) => (
                  <option key={name} value={`ext:${name}`}>
                    {name}
                  </option>
                ))}
              </optgroup>
            )}
          </select>

          {filtered && (
            <button type="button" className="link-button" onClick={clearAll}>
              Clear all
            </button>
          )}
        </div>

        <p className="muted small">
          Showing {results.length} of {total} student{total === 1 ? "" : "s"}
          {filtered ? " matching" : ""}, A to Z by last name.
        </p>

        {searched && results.length === 0 ? (
          <EmptyState message="No students matched your search." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Name (last, first)</th>
                  <th>Student ID</th>
                  <th>Course</th>
                  <th>Year</th>
                  <th>Applications</th>
                  <th>Scholarship history</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {results.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <strong>{item.last_name}</strong>, {item.first_name}
                      {item.middle_name ? ` ${item.middle_name}` : ""}
                    </td>
                    <td>{item.student_id}</td>
                    <td>{item.course || "—"}</td>
                    <td>{item.year_level || "—"}</td>
                    <td>{item.applications?.length || 0}</td>
                    <td>
                      {item.scholar_records?.length || item.external_grantees?.length ? (
                        <>
                          {(item.scholar_records || []).map((record) => (
                            <div key={record.id}>
                              {record.scholarship?.name}{" "}
                              <span className={statusClass(record.status)}>{record.status}</span>
                            </div>
                          ))}
                          {(item.external_grantees || []).map((g) => (
                            <div key={`ext-${g.id}`}>
                              {g.program_name} <span className="status status-neutral">outside</span>
                            </div>
                          ))}
                        </>
                      ) : (
                        <span className="muted">None</span>
                      )}
                    </td>
                    <td>
                      <button className="button button-small button-secondary" onClick={() => openStudent(item.id)}>
                        Full history
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {hasMore && (
          <div className="show-more">
            <button className="button button-secondary" onClick={showMore} disabled={loading}>
              {loading ? "Loading..." : `Show more (${total - results.length} left)`}
            </button>
          </div>
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
              <ProfileItem label="Sex" value={student.sex} />
              <ProfileItem label="College" value={student.college} />
              <ProfileItem label="Contact" value={student.contact_number} />
              <ProfileItem label="Email" value={student.user?.email} />
              <ProfileItem label="GWA" value={gwaText(student.gwa)} />
              <ProfileItem label="Annual family income" value={peso(student.family_income)} />
            </div>

            {student.gwa !== null && student.family_income !== null && (
              <div className="data-strip">
                {student.eligibility_verified ? (
                  <span className="status status-success">
                    ✓ GWA and income checked by {student.eligibility_verifier?.name || "OAS"}
                    {student.eligibility_verified_at ? ` on ${formatDate(student.eligibility_verified_at)}` : ""}
                  </span>
                ) : (
                  <span className="status status-warning">GWA and income typed by the student, not checked yet</span>
                )}
                <button className="link-button small" onClick={() => verifyData(!student.eligibility_verified)}>
                  {student.eligibility_verified ? "Undo check" : "Mark as checked (Grades and ITR seen)"}
                </button>
              </div>
            )}

            {student.external_grantees?.length > 0 && (
              <>
                <h3>Outside scholarships (External Grantees)</h3>
                <div className="table-wrapper">
                  <table>
                    <thead>
                      <tr>
                        <th>Scholarship</th>
                        <th>Given by</th>
                        <th>School year</th>
                        <th>From the list</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {student.external_grantees.map((g) => (
                        <tr key={g.id}>
                          <td>{g.program_name}</td>
                          <td>{g.agency || "—"}</td>
                          <td>{g.school_year || "—"}</td>
                          <td>
                            {g.upload?.file_name || "—"}
                            <small className="muted account-note">saved {formatDate(g.created_at)}</small>
                          </td>
                          <td>
                            <span className={`status status-${g.status === "active" ? "success" : "neutral"}`}>{g.status}</span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}

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
                      <th>ATM</th>
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
                        <td>{record.atm_label}</td>
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
