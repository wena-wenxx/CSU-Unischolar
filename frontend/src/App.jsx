import { useEffect, useState } from "react";
import api from "./services/api";

/* =========================================================
   HELPERS
========================================================= */

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem("user"));
  } catch {
    return null;
  }
}

function formatDate(date) {
  if (!date) return "—";

  return new Date(date).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

function formatMoney(value) {
  return `₱${Number(value || 0).toLocaleString("en-PH", {
    minimumFractionDigits: 2,
  })}`;
}

function statusClass(status) {
  const value = String(status || "").toLowerCase();

  if (
    ["approved", "complete", "active", "ready", "validated"].includes(value)
  ) {
    return "status status-success";
  }

  if (
    ["needs_action", "flagged", "needs_review", "under_review"].includes(value)
  ) {
    return "status status-warning";
  }

  if (["rejected", "inactive"].includes(value)) {
    return "status status-danger";
  }

  return "status status-neutral";
}

/* =========================================================
   APP
========================================================= */

export default function App() {
  const [user, setUser] = useState(getStoredUser());

  function loginSuccess(data) {
    localStorage.setItem("token", data.token);
    localStorage.setItem("user", JSON.stringify(data.user));

    setUser(data.user);
  }

  function logout() {
    api.post("/logout").catch(() => {});

    localStorage.removeItem("token");
    localStorage.removeItem("user");

    setUser(null);
  }

  if (!user) {
    return <LoginPage onLogin={loginSuccess} />;
  }

  if (user.role === "staff") {
    return <StaffApplication logout={logout} user={user} />;
  }

  return <StudentApplication logout={logout} user={user} />;
}

/* =========================================================
   LOGIN
========================================================= */

function LoginPage({ onLogin }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleLogin(e) {
    e.preventDefault();

    setError("");
    setLoading(true);

    try {
      const response = await api.post("/login", {
        email,
        password,
      });

      onLogin(response.data);
    } catch (err) {
      setError(
        err.response?.data?.message ||
          err.response?.data?.errors?.email?.[0] ||
          "Login failed. Please check your email and password."
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="login-page">
      <div className="login-card">
        <div className="brand-mark">CSU</div>

        <h1>CSU UniScholar</h1>

        <p className="muted">
          Unified Scholarship Management System
        </p>

        {error && <div className="alert alert-danger">{error}</div>}

        <form onSubmit={handleLogin}>
          <label>Email</label>

          <input
            type="email"
            placeholder="Enter your email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />

          <label>Password</label>

          <input
            type="password"
            placeholder="Enter your password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />

          <button
            className="button button-primary full-width"
            disabled={loading}
          >
            {loading ? "Signing in..." : "Sign in"}
          </button>
        </form>

        <div className="demo-box">
          <strong>Development accounts</strong>

          <p>Student: student1@csu.local</p>
          <p>Staff: staff@csu.local</p>

          <small>
            These are fictional local development accounts, not CSU SSO
            accounts.
          </small>
        </div>
      </div>
    </div>
  );
}

/* =========================================================
   STUDENT APPLICATION
========================================================= */

function StudentApplication({ logout, user }) {
  const [page, setPage] = useState("dashboard");

  return (
    <AppShell
      user={user}
      logout={logout}
      page={page}
      setPage={setPage}
      role="student"
    >
      {page === "dashboard" && (
        <StudentDashboard setPage={setPage} />
      )}

      {page === "scholarships" && (
        <StudentScholarships />
      )}

      {page === "applications" && (
        <StudentApplications />
      )}

      {page === "profile" && (
        <StudentProfile />
      )}

      {page === "history" && (
        <StudentHistory />
      )}
    </AppShell>
  );
}

/* =========================================================
   STUDENT DASHBOARD
========================================================= */

function StudentDashboard({ setPage }) {
  const [applications, setApplications] = useState([]);
  const [scholarships, setScholarships] = useState([]);
  const [profile, setProfile] = useState(null);

  useEffect(() => {
    Promise.all([
      api.get("/my-applications"),
      api.get("/scholarships"),
      api.get("/profile"),
    ])
      .then(([apps, scholarshipsResponse, profileResponse]) => {
        setApplications(apps.data);
        setScholarships(scholarshipsResponse.data);
        setProfile(profileResponse.data);
      })
      .catch(() => {});
  }, []);

  return (
    <div>
      <PageHeader
        title="Student Dashboard"
        subtitle={
          profile?.student
            ? `Welcome, ${profile.student.first_name}`
            : "Manage your scholarship applications"
        }
      />

      <div className="stats-grid">
        <StatCard
          title="Available Scholarships"
          value={scholarships.length}
        />

        <StatCard
          title="My Applications"
          value={applications.length}
        />

        <StatCard
          title="Under Review"
          value={
            applications.filter(
              (a) => a.status === "under_review"
            ).length
          }
        />

        <StatCard
          title="Approved"
          value={
            applications.filter(
              (a) => a.status === "approved"
            ).length
          }
        />
      </div>

      <div className="dashboard-grid">
        <section className="card">
          <div className="card-header">
            <h2>Available Scholarships</h2>

            <button
              className="button button-secondary"
              onClick={() => setPage("scholarships")}
            >
              View all
            </button>
          </div>

          {scholarships.length === 0 ? (
            <EmptyState message="No scholarships available." />
          ) : (
            scholarships.slice(0, 5).map((scholarship) => (
              <div className="list-item" key={scholarship.id}>
                <div>
                  <strong>{scholarship.name}</strong>

                  <p className="muted">
                    {scholarship.provider || "CSU"}
                  </p>
                </div>

                <strong>
                  {formatMoney(scholarship.amount)}
                </strong>
              </div>
            ))
          )}
        </section>

        <section className="card">
          <div className="card-header">
            <h2>Recent Applications</h2>

            <button
              className="button button-secondary"
              onClick={() => setPage("applications")}
            >
              View all
            </button>
          </div>

          {applications.length === 0 ? (
            <EmptyState message="You have no applications yet." />
          ) : (
            applications.slice(0, 5).map((application) => (
              <div
                className="list-item"
                key={application.id}
              >
                <div>
                  <strong>
                    {application.scholarship?.name ||
                      "Scholarship"}
                  </strong>

                  <p className="muted">
                    {formatDate(application.submitted_at)}
                  </p>
                </div>

                <span className={statusClass(application.status)}>
                  {application.status}
                </span>
              </div>
            ))
          )}
        </section>
      </div>
    </div>
  );
}

/* =========================================================
   STUDENT SCHOLARSHIPS
========================================================= */

function StudentScholarships() {
  const [scholarships, setScholarships] = useState([]);
  const [selected, setSelected] = useState(null);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");

  async function loadScholarships() {
    try {
      const response = await api.get("/scholarships");
      setScholarships(response.data);
    } catch (err) {
      setMessage("Unable to load scholarships.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadScholarships();
  }, []);

  async function viewScholarship(id) {
    setMessage("");

    try {
      const response = await api.get(`/scholarships/${id}`);
      setSelected(response.data);
    } catch {
      setMessage("Unable to load scholarship details.");
    }
  }

  async function apply(id) {
    try {
      await api.post("/applications", {
        scholarship_id: id,
      });

      setMessage("Application submitted successfully.");
    } catch (err) {
      setMessage(
        err.response?.data?.message ||
          "Unable to submit application."
      );
    }
  }

  if (loading) {
    return <Loading />;
  }

  return (
    <div>
      <PageHeader
        title="Scholarships"
        subtitle="View available scholarship programs"
      />

      {message && (
        <div className="alert alert-info">{message}</div>
      )}

      <div className="card-grid">
        {scholarships.map((scholarship) => (
          <div className="card scholarship-card" key={scholarship.id}>
            <span className="badge">
              {scholarship.status}
            </span>

            <h2>{scholarship.name}</h2>

            <p className="muted">
              {scholarship.description ||
                "Scholarship assistance program"}
            </p>

            <div className="amount">
              {formatMoney(scholarship.amount)}
            </div>

            <p>
              Provider:{" "}
              <strong>
                {scholarship.provider || "—"}
              </strong>
            </p>

            <div className="button-row">
              <button
                className="button button-secondary"
                onClick={() => viewScholarship(scholarship.id)}
              >
                Details
              </button>

              <button
                className="button button-primary"
                onClick={() => apply(scholarship.id)}
              >
                Apply
              </button>
            </div>
          </div>
        ))}
      </div>

      {selected && (
        <div className="modal-backdrop">
          <div className="modal">
            <div className="card-header">
              <h2>{selected.name}</h2>

              <button
                className="close-button"
                onClick={() => setSelected(null)}
              >
                ×
              </button>
            </div>

            <p>
              {selected.description ||
                "No description available."}
            </p>

            <h3>Requirements</h3>

            {selected.requirements?.length ? (
              <ul className="requirements-list">
                {selected.requirements.map((requirement) => (
                  <li key={requirement.id}>
                    {requirement.name}

                    {requirement.is_required && (
                      <span className="required">
                        Required
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="muted">
                No requirements listed.
              </p>
            )}

            <button
              className="button button-primary full-width"
              onClick={() => {
                apply(selected.id);
                setSelected(null);
              }}
            >
              Apply for this scholarship
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

/* =========================================================
   STUDENT APPLICATIONS
========================================================= */

function StudentApplications() {
  const [applications, setApplications] = useState([]);
  const [selected, setSelected] = useState(null);
  const [loading, setLoading] = useState(true);

  async function load() {
    try {
      const response = await api.get("/my-applications");
      setApplications(response.data);
    } catch {
      setApplications([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function viewApplication(id) {
    try {
      const response = await api.get(`/applications/${id}`);
      setSelected(response.data);
    } catch {
      alert("Unable to load application.");
    }
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="My Applications"
        subtitle="Track your scholarship applications"
      />

      <div className="card">
        {applications.length === 0 ? (
          <EmptyState message="No applications found." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Scholarship</th>
                  <th>Submitted</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {applications.map((application) => (
                  <tr key={application.id}>
                    <td>
                      {application.scholarship?.name ||
                        "Scholarship"}
                    </td>

                    <td>
                      {formatDate(application.submitted_at)}
                    </td>

                    <td>
                      <span
                        className={statusClass(
                          application.status
                        )}
                      >
                        {application.status}
                      </span>
                    </td>

                    <td>
                      <button
                        className="button button-small"
                        onClick={() =>
                          viewApplication(application.id)
                        }
                      >
                        View
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {selected && (
        <ApplicationModal
          application={selected}
          close={() => setSelected(null)}
          refresh={load}
        />
      )}
    </div>
  );
}

/* =========================================================
   APPLICATION MODAL
========================================================= */

function ApplicationModal({ application, close, refresh }) {
  const [file, setFile] = useState(null);
  const [requirementId, setRequirementId] = useState("");
  const [uploading, setUploading] = useState(false);
  const [message, setMessage] = useState("");

  async function uploadDocument() {
    if (!file || !requirementId) {
      setMessage("Select a requirement and a file.");
      return;
    }

    const formData = new FormData();

    formData.append("document", file);
    formData.append(
      "scholarship_requirement_id",
      requirementId
    );

    setUploading(true);
    setMessage("");

    try {
      await api.post(
        `/applications/${application.id}/documents`,
        formData,
        {
          headers: {
            "Content-Type": "multipart/form-data",
          },
        }
      );

      setMessage("Document uploaded successfully.");

      setFile(null);

      await refresh();
    } catch (err) {
      setMessage(
        err.response?.data?.message ||
          "Document upload failed."
      );
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="modal-backdrop">
      <div className="modal modal-large">
        <div className="card-header">
          <div>
            <h2>
              {application.scholarship?.name ||
                "Application"}
            </h2>

            <span
              className={statusClass(application.status)}
            >
              {application.status}
            </span>
          </div>

          <button className="close-button" onClick={close}>
            ×
          </button>
        </div>

        {message && (
          <div className="alert alert-info">{message}</div>
        )}

        <h3>Documents</h3>

        {application.documents?.length ? (
          application.documents.map((document) => (
            <div className="document-row" key={document.id}>
              <div>
                <strong>
                  {document.requirement?.name ||
                    document.document_type ||
                    document.original_filename}
                </strong>

                <p className="muted">
                  {document.original_filename}
                </p>
              </div>

              <span
                className={statusClass(document.status)}
              >
                {document.status}
              </span>
            </div>
          ))
        ) : (
          <EmptyState message="No documents uploaded yet." />
        )}

        <hr />

        <h3>Upload Requirement</h3>

        <select
          value={requirementId}
          onChange={(e) =>
            setRequirementId(e.target.value)
          }
        >
          <option value="">
            Select requirement
          </option>

          {application.scholarship?.requirements?.map(
            (requirement) => (
              <option
                key={requirement.id}
                value={requirement.id}
              >
                {requirement.name}
              </option>
            )
          )}
        </select>

        <input
          type="file"
          accept=".pdf,.jpg,.jpeg,.png"
          onChange={(e) =>
            setFile(e.target.files?.[0] || null)
          }
        />

        <button
          className="button button-primary"
          onClick={uploadDocument}
          disabled={uploading}
        >
          {uploading ? "Uploading..." : "Upload document"}
        </button>
      </div>
    </div>
  );
}

/* =========================================================
   STUDENT PROFILE
========================================================= */

function StudentProfile() {
  const [profile, setProfile] = useState(null);

  useEffect(() => {
    api
      .get("/profile")
      .then((response) => setProfile(response.data))
      .catch(() => {});
  }, []);

  const student = profile?.student;

  return (
    <div>
      <PageHeader
        title="My Profile"
        subtitle="Your student information"
      />

      <div className="card">
        {!student ? (
          <Loading />
        ) : (
          <div className="profile-grid">
            <ProfileItem
              label="Student ID"
              value={student.student_id}
            />

            <ProfileItem
              label="First Name"
              value={student.first_name}
            />

            <ProfileItem
              label="Middle Name"
              value={student.middle_name}
            />

            <ProfileItem
              label="Last Name"
              value={student.last_name}
            />

            <ProfileItem
              label="Course"
              value={student.course}
            />

            <ProfileItem
              label="Year Level"
              value={student.year_level}
            />

            <ProfileItem
              label="College"
              value={student.college}
            />

            <ProfileItem
              label="Enrollment Status"
              value={student.enrollment_status}
            />

            <ProfileItem
              label="Contact Number"
              value={student.contact_number}
            />

            <ProfileItem
              label="Email"
              value={profile?.email}
            />
          </div>
        )}
      </div>
    </div>
  );
}

/* =========================================================
   STUDENT HISTORY
========================================================= */

function StudentHistory() {
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get("/student/history")
      .then((response) => setHistory(response.data))
      .catch(() => setHistory([]))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Scholarship History"
        subtitle="Your scholarship records"
      />

      <div className="card">
        {history.length === 0 ? (
          <EmptyState message="No scholarship history found." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Scholarship</th>
                  <th>Status</th>
                  <th>Enrollment</th>
                  <th>Tagged</th>
                </tr>
              </thead>

              <tbody>
                {history.map((record) => (
                  <tr key={record.id}>
                    <td>{record.scholarship?.name}</td>

                    <td>
                      <span
                        className={statusClass(record.status)}
                      >
                        {record.status}
                      </span>
                    </td>

                    <td>
                      {record.currently_enrolled
                        ? "Currently enrolled"
                        : "Not enrolled"}
                    </td>

                    <td>
                      {formatDate(record.grantee_tagged_at)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

/* =========================================================
   STAFF APPLICATION
========================================================= */

function StaffApplication({ logout, user }) {
  const [page, setPage] = useState("dashboard");

  return (
    <AppShell
      user={user}
      logout={logout}
      page={page}
      setPage={setPage}
      role="staff"
    >
      {page === "dashboard" && (
        <StaffDashboard setPage={setPage} />
      )}

      {page === "applications" && (
        <StaffApplications />
      )}

      {page === "scholars" && (
        <StaffScholars />
      )}

      {page === "payroll" && (
        <StaffPayroll />
      )}

      {page === "scholarships" && (
        <StaffScholarships />
      )}

      {page === "reports" && (
        <Reports />
      )}
    </AppShell>
  );
}

/* =========================================================
   STAFF DASHBOARD
========================================================= */

function StaffDashboard({ setPage }) {
  const [stats, setStats] = useState(null);

  useEffect(() => {
    api
      .get("/staff/dashboard")
      .then((response) => setStats(response.data))
      .catch(() => {});
  }, []);

  return (
    <div>
      <PageHeader
        title="OAS Staff Dashboard"
        subtitle="Scholarship management overview"
      />

      <div className="stats-grid">
        <StatCard
          title="Total Applicants"
          value={stats?.total_applicants ?? 0}
        />

        <StatCard
          title="Applications"
          value={stats?.total_applications ?? 0}
        />

        <StatCard
          title="Needs Action"
          value={stats?.needs_action ?? 0}
        />

        <StatCard
          title="Approved"
          value={stats?.approved ?? 0}
        />

        <StatCard
          title="Active Scholars"
          value={stats?.active_scholars ?? 0}
        />

        <StatCard
          title="Payroll Ready"
          value={stats?.payroll_ready ?? 0}
        />

        <StatCard
          title="AI Flags"
          value={stats?.ai_flags ?? 0}
        />

        <StatCard
          title="Scholarship Programs"
          value={stats?.scholarships ?? 0}
        />
      </div>

      <div className="quick-actions">
        <button
          className="button button-primary"
          onClick={() => setPage("applications")}
        >
          Review Applications
        </button>

        <button
          className="button button-secondary"
          onClick={() => setPage("scholars")}
        >
          Scholar Records
        </button>

        <button
          className="button button-secondary"
          onClick={() => setPage("payroll")}
        >
          Payroll
        </button>

        <button
          className="button button-secondary"
          onClick={() => setPage("reports")}
        >
          Reports
        </button>
      </div>
    </div>
  );
}

/* =========================================================
   STAFF APPLICATIONS
========================================================= */

function StaffApplications() {
  const [applications, setApplications] = useState([]);
  const [selected, setSelected] = useState(null);
  const [loading, setLoading] = useState(true);

  async function load() {
    try {
      const response = await api.get("/applications");
      setApplications(response.data);
    } catch {
      setApplications([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function review(id, status) {
    try {
      await api.patch(`/applications/${id}/review`, {
        status,
      });

      await load();

      if (selected) {
        const response = await api.get(
          `/applications/${id}`
        );

        setSelected(response.data);
      }
    } catch (err) {
      alert(
        err.response?.data?.message ||
          "Unable to update application."
      );
    }
  }

  async function verifyEnrollment(id) {
    try {
      await api.post(
        `/applications/${id}/verify-enrollment`,
        {
          enrollment_status: "active",
        }
      );

      await load();

      alert("Enrollment verified.");
    } catch (err) {
      alert(
        err.response?.data?.message ||
          "Unable to verify enrollment."
      );
    }
  }

  async function validateDocument(documentId) {
    try {
      const response = await api.post(
        `/documents/${documentId}/validate`
      );

      alert(
        response.data?.message ||
          "AI validation completed."
      );

      await load();
    } catch (err) {
      alert(
        err.response?.data?.message ||
          "AI validation failed."
      );
    }
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Application Review"
        subtitle="Review and verify student scholarship applications"
      />

      <div className="card">
        {applications.length === 0 ? (
          <EmptyState message="No applications found." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Student ID</th>
                  <th>Scholarship</th>
                  <th>Status</th>
                  <th>Enrollment</th>
                  <th>Actions</th>
                </tr>
              </thead>

              <tbody>
                {applications.map((application) => (
                  <tr key={application.id}>
                    <td>
                      {application.student?.first_name}{" "}
                      {application.student?.last_name}
                    </td>

                    <td>
                      {application.student?.student_id}
                    </td>

                    <td>
                      {application.scholarship?.name}
                    </td>

                    <td>
                      <span
                        className={statusClass(
                          application.status
                        )}
                      >
                        {application.status}
                      </span>
                    </td>

                    <td>
                      {application.student
                        ?.enrollment_status || "—"}
                    </td>

                    <td>
                      <div className="button-row">
                        <button
                          className="button button-small"
                          onClick={async () => {
                            const response = await api.get(
                              `/applications/${application.id}`
                            );

                            setSelected(response.data);
                          }}
                        >
                          Review
                        </button>

                        <button
                          className="button button-small button-success"
                          onClick={() =>
                            verifyEnrollment(
                              application.id
                            )
                          }
                        >
                          Verify
                        </button>

                        {application.status ===
                          "under_review" && (
                          <button
                            className="button button-small button-primary"
                            onClick={() =>
                              review(
                                application.id,
                                "approved"
                              )
                            }
                          >
                            Approve
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {selected && (
        <div className="modal-backdrop">
          <div className="modal modal-large">
            <div className="card-header">
              <div>
                <h2>Application Review</h2>

                <span
                  className={statusClass(
                    selected.status
                  )}
                >
                  {selected.status}
                </span>
              </div>

              <button
                className="close-button"
                onClick={() => setSelected(null)}
              >
                ×
              </button>
            </div>

            <div className="detail-grid">
              <ProfileItem
                label="Student"
                value={`${selected.student?.first_name || ""} ${
                  selected.student?.last_name || ""
                }`}
              />

              <ProfileItem
                label="Student ID"
                value={selected.student?.student_id}
              />

              <ProfileItem
                label="Course"
                value={selected.student?.course}
              />

              <ProfileItem
                label="Year Level"
                value={selected.student?.year_level}
              />

              <ProfileItem
                label="Enrollment"
                value={
                  selected.student?.enrollment_status
                }
              />

              <ProfileItem
                label="Scholarship"
                value={selected.scholarship?.name}
              />
            </div>

            <h3>Submitted Documents</h3>

            {selected.documents?.length ? (
              selected.documents.map((document) => (
                <div
                  className="document-row"
                  key={document.id}
                >
                  <div>
                    <strong>
                      {document.requirement?.name ||
                        document.document_type ||
                        "Document"}
                    </strong>

                    <p className="muted">
                      {document.original_filename}
                    </p>
                  </div>

                  <div className="button-row">
                    <span
                      className={statusClass(
                        document.status
                      )}
                    >
                      {document.status}
                    </span>

                    <button
                      className="button button-small"
                      onClick={() =>
                        validateDocument(document.id)
                      }
                    >
                      Run AI
                    </button>
                  </div>
                </div>
              ))
            ) : (
              <EmptyState message="No documents submitted." />
            )}

            <div className="button-row">
              <button
                className="button button-warning"
                onClick={() =>
                  review(selected.id, "needs_action")
                }
              >
                Needs Action
              </button>

              <button
                className="button button-secondary"
                onClick={() =>
                  review(selected.id, "complete")
                }
              >
                Mark Complete
              </button>

              <button
                className="button button-primary"
                onClick={() =>
                  review(selected.id, "approved")
                }
              >
                Approve
              </button>

              <button
                className="button button-danger"
                onClick={() =>
                  review(selected.id, "rejected")
                }
              >
                Reject
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/* =========================================================
   STAFF SCHOLARS
========================================================= */

function StaffScholars() {
  const [records, setRecords] = useState([]);
  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);

  async function load() {
    try {
      const [recordsResponse, applicationsResponse] =
        await Promise.all([
          api.get("/scholar-records"),
          api.get("/applications"),
        ]);

      setRecords(recordsResponse.data);

      setApplications(
        applicationsResponse.data.filter(
          (application) =>
            application.status === "approved"
        )
      );
    } catch {
      setRecords([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function createScholar(applicationId) {
    const hasAtm = window.confirm(
      "Does this student have an ATM?"
    );

    try {
      await api.post(
        `/applications/${applicationId}/scholar-record`,
        {
          has_atm: hasAtm,
        }
      );

      alert("Scholar record created.");

      await load();
    } catch (err) {
      alert(
        err.response?.data?.message ||
          "Unable to create scholar record."
      );
    }
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Scholar Records"
        subtitle="Manage confirmed scholarship grantees"
      />

      <section className="card">
        <h2>Approved Applications</h2>

        {applications.length === 0 ? (
          <EmptyState message="No approved applications waiting for tagging." />
        ) : (
          applications.map((application) => (
            <div
              className="list-item"
              key={application.id}
            >
              <div>
                <strong>
                  {application.student?.first_name}{" "}
                  {application.student?.last_name}
                </strong>

                <p className="muted">
                  {application.student?.student_id} ·{" "}
                  {application.scholarship?.name}
                </p>
              </div>

              <button
                className="button button-primary"
                onClick={() =>
                  createScholar(application.id)
                }
              >
                Tag as Grantee
              </button>
            </div>
          ))
        )}
      </section>

      <section className="card">
        <h2>Current Scholar Records</h2>

        {records.length === 0 ? (
          <EmptyState message="No scholar records yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Scholarship</th>
                  <th>Status</th>
                  <th>Enrolled</th>
                  <th>ATM</th>
                </tr>
              </thead>

              <tbody>
                {records.map((record) => (
                  <tr key={record.id}>
                    <td>
                      {record.student?.first_name}{" "}
                      {record.student?.last_name}
                    </td>

                    <td>{record.scholarship?.name}</td>

                    <td>
                      <span
                        className={statusClass(
                          record.status
                        )}
                      >
                        {record.status}
                      </span>
                    </td>

                    <td>
                      {record.currently_enrolled
                        ? "Yes"
                        : "No"}
                    </td>

                    <td>
                      {record.has_atm ? "Yes" : "No"}
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

/* =========================================================
   STAFF PAYROLL
========================================================= */

function StaffPayroll() {
  const [records, setRecords] = useState([]);
  const [payroll, setPayroll] = useState([]);
  const [loading, setLoading] = useState(true);

  async function load() {
    try {
      const [scholarsResponse, payrollResponse] =
        await Promise.all([
          api.get("/scholar-records"),
          api.get("/payroll"),
        ]);

      setRecords(scholarsResponse.data);
      setPayroll(payrollResponse.data);
    } catch {
      setRecords([]);
      setPayroll([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function createPayroll(id, amount) {
    const period = window.prompt(
      "Enter payroll period:",
      "Semester 1"
    );

    if (!period) return;

    try {
      await api.post(
        `/scholar-records/${id}/payroll`,
        {
          amount,
          period,
        }
      );

      alert("Payroll record created.");

      await load();
    } catch (err) {
      alert(
        err.response?.data?.message ||
          "Unable to create payroll record."
      );
    }
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Payroll Preparation"
        subtitle="Prepare payroll-ready scholarship records"
      />

      <section className="card">
        <h2>Active Scholars</h2>

        {records.length === 0 ? (
          <EmptyState message="No scholar records available." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Scholarship</th>
                  <th>Amount</th>
                  <th>ATM</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {records
                  .filter(
                    (record) =>
                      record.status === "active"
                  )
                  .map((record) => (
                    <tr key={record.id}>
                      <td>
                        {record.student?.first_name}{" "}
                        {record.student?.last_name}
                      </td>

                      <td>
                        {record.scholarship?.name}
                      </td>

                      <td>
                        {formatMoney(
                          record.scholarship?.amount
                        )}
                      </td>

                      <td>
                        {record.has_atm ? "Yes" : "No"}
                      </td>

                      <td>
                        <button
                          className="button button-small button-primary"
                          onClick={() =>
                            createPayroll(
                              record.id,
                              record.scholarship?.amount
                            )
                          }
                        >
                          Prepare Payroll
                        </button>
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="card">
        <h2>Payroll Records</h2>

        {payroll.length === 0 ? (
          <EmptyState message="No payroll records yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Amount</th>
                  <th>Period</th>
                  <th>ATM</th>
                  <th>Status</th>
                </tr>
              </thead>

              <tbody>
                {payroll.map((record) => (
                  <tr key={record.id}>
                    <td>
                      {record.scholar_record?.student
                        ?.first_name}{" "}
                      {record.scholar_record?.student
                        ?.last_name}
                    </td>

                    <td>{formatMoney(record.amount)}</td>

                    <td>{record.period}</td>

                    <td>{record.bank_atm_status}</td>

                    <td>
                      <span
                        className={statusClass(
                          record.status
                        )}
                      >
                        {record.status}
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

/* =========================================================
   STAFF SCHOLARSHIPS
========================================================= */

function StaffScholarships() {
  const [scholarships, setScholarships] = useState([]);
  const [loading, setLoading] = useState(true);

  const [form, setForm] = useState({
    name: "",
    description: "",
    provider: "",
    amount: "",
  });

  async function load() {
    try {
      const response = await api.get("/scholarships");
      setScholarships(response.data);
    } catch {
      setScholarships([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function createScholarship(e) {
    e.preventDefault();

    try {
      await api.post("/scholarships", {
        ...form,
        amount: Number(form.amount),
        status: "active",
      });

      setForm({
        name: "",
        description: "",
        provider: "",
        amount: "",
      });

      await load();

      alert("Scholarship created.");
    } catch (err) {
      alert(
        err.response?.data?.message ||
          "Unable to create scholarship."
      );
    }
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Scholarship Programs"
        subtitle="Manage scholarship programs"
      />

      <section className="card">
        <h2>Create Scholarship</h2>

        <form
          className="form-grid"
          onSubmit={createScholarship}
        >
          <div>
            <label>Name</label>

            <input
              value={form.name}
              onChange={(e) =>
                setForm({
                  ...form,
                  name: e.target.value,
                })
              }
              required
            />
          </div>

          <div>
            <label>Provider</label>

            <input
              value={form.provider}
              onChange={(e) =>
                setForm({
                  ...form,
                  provider: e.target.value,
                })
              }
            />
          </div>

          <div>
            <label>Amount</label>

            <input
              type="number"
              value={form.amount}
              onChange={(e) =>
                setForm({
                  ...form,
                  amount: e.target.value,
                })
              }
              required
            />
          </div>

          <div className="full-column">
            <label>Description</label>

            <textarea
              value={form.description}
              onChange={(e) =>
                setForm({
                  ...form,
                  description: e.target.value,
                })
              }
            />
          </div>

          <button className="button button-primary">
            Create Scholarship
          </button>
        </form>
      </section>

      <section className="card">
        <h2>Existing Scholarships</h2>

        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Provider</th>
                <th>Amount</th>
                <th>Status</th>
              </tr>
            </thead>

            <tbody>
              {scholarships.map((scholarship) => (
                <tr key={scholarship.id}>
                  <td>{scholarship.name}</td>

                  <td>{scholarship.provider}</td>

                  <td>
                    {formatMoney(scholarship.amount)}
                  </td>

                  <td>
                    <span
                      className={statusClass(
                        scholarship.status
                      )}
                    >
                      {scholarship.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

/* =========================================================
   REPORTS
========================================================= */

function Reports() {
  const [applications, setApplications] = useState([]);
  const [scholars, setScholars] = useState([]);
  const [payroll, setPayroll] = useState([]);
  const [scholarships, setScholarships] = useState([]);

  useEffect(() => {
    Promise.all([
      api.get("/applications"),
      api.get("/scholar-records"),
      api.get("/payroll"),
      api.get("/scholarships"),
    ])
      .then(
        ([
          applicationsResponse,
          scholarsResponse,
          payrollResponse,
          scholarshipsResponse,
        ]) => {
          setApplications(applicationsResponse.data);
          setScholars(scholarsResponse.data);
          setPayroll(payrollResponse.data);
          setScholarships(
            scholarshipsResponse.data
          );
        }
      )
      .catch(() => {});
  }, []);

  function downloadCSV(filename, rows) {
    if (!rows.length) {
      alert("There is no data to export.");
      return;
    }

    const headers = Object.keys(rows[0]);

    const csv = [
      headers.join(","),
      ...rows.map((row) =>
        headers
          .map((header) =>
            `"${String(row[header] ?? "").replaceAll(
              '"',
              '""'
            )}"`
          )
          .join(",")
      ),
    ].join("\n");

    const blob = new Blob([csv], {
      type: "text/csv;charset=utf-8;",
    });

    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");

    link.href = url;
    link.download = filename;

    link.click();

    URL.revokeObjectURL(url);
  }

  return (
    <div>
      <PageHeader
        title="Reports"
        subtitle="Export scholarship management data"
      />

      <div className="card-grid">
        <ReportCard
          title="Application Report"
          count={applications.length}
          onClick={() =>
            downloadCSV(
              "scholarship-applications.csv",
              applications.map((item) => ({
                ID: item.id,
                Student:
                  `${item.student?.first_name || ""} ${
                    item.student?.last_name || ""
                  }`.trim(),
                Student_ID:
                  item.student?.student_id || "",
                Scholarship:
                  item.scholarship?.name || "",
                Status: item.status || "",
                Submitted:
                  item.submitted_at || "",
              }))
            )
          }
        />

        <ReportCard
          title="Scholar Report"
          count={scholars.length}
          onClick={() =>
            downloadCSV(
              "scholar-records.csv",
              scholars.map((item) => ({
                ID: item.id,
                Student:
                  `${item.student?.first_name || ""} ${
                    item.student?.last_name || ""
                  }`.trim(),
                Student_ID:
                  item.student?.student_id || "",
                Scholarship:
                  item.scholarship?.name || "",
                Status: item.status || "",
                Enrolled:
                  item.currently_enrolled
                    ? "Yes"
                    : "No",
                ATM: item.has_atm ? "Yes" : "No",
              }))
            )
          }
        />

        <ReportCard
          title="Payroll Report"
          count={payroll.length}
          onClick={() =>
            downloadCSV(
              "payroll-report.csv",
              payroll.map((item) => ({
                ID: item.id,
                Amount: item.amount,
                Period: item.period,
                ATM_Status:
                  item.bank_atm_status || "",
                Status: item.status || "",
              }))
            )
          }
        />

        <ReportCard
          title="Scholarship Programs"
          count={scholarships.length}
          onClick={() =>
            downloadCSV(
              "scholarship-programs.csv",
              scholarships.map((item) => ({
                ID: item.id,
                Name: item.name,
                Provider: item.provider || "",
                Amount: item.amount || "",
                Status: item.status || "",
              }))
            )
          }
        />
      </div>
    </div>
  );
}

/* =========================================================
   APP SHELL
========================================================= */

function AppShell({
  children,
  user,
  logout,
  page,
  setPage,
  role,
}) {
  const studentNavigation = [
    ["dashboard", "Dashboard"],
    ["scholarships", "Scholarships"],
    ["applications", "My Applications"],
    ["history", "Scholarship History"],
    ["profile", "My Profile"],
  ];

  const staffNavigation = [
    ["dashboard", "Dashboard"],
    ["applications", "Applications"],
    ["scholars", "Scholar Records"],
    ["payroll", "Payroll"],
    ["scholarships", "Scholarships"],
    ["reports", "Reports"],
  ];

  const navigation =
    role === "staff"
      ? staffNavigation
      : studentNavigation;

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="sidebar-brand">
          <div className="brand-mark small">CSU</div>

          <div>
            <strong>UniScholar</strong>

            <small>
              {role === "staff"
                ? "OAS Staff"
                : "Student Portal"}
            </small>
          </div>
        </div>

        <nav>
          {navigation.map(([key, label]) => (
            <button
              key={key}
              className={
                page === key
                  ? "nav-item active"
                  : "nav-item"
              }
              onClick={() => setPage(key)}
            >
              {label}
            </button>
          ))}
        </nav>

        <button
          className="logout-button"
          onClick={logout}
        >
          Sign out
        </button>
      </aside>

      <main className="main-content">
        <header className="topbar">
          <div>
            <strong>CSU UniScholar</strong>
          </div>

          <div className="user-menu">
            <span>{user.name}</span>

            <span className="role-label">
              {user.role}
            </span>
          </div>
        </header>

        <div className="content">{children}</div>
      </main>
    </div>
  );
}

/* =========================================================
   COMPONENTS
========================================================= */

function PageHeader({ title, subtitle }) {
  return (
    <div className="page-header">
      <div>
        <h1>{title}</h1>

        <p className="muted">{subtitle}</p>
      </div>
    </div>
  );
}

function StatCard({ title, value }) {
  return (
    <div className="stat-card">
      <span>{title}</span>

      <strong>{value}</strong>
    </div>
  );
}

function ProfileItem({ label, value }) {
  return (
    <div className="profile-item">
      <span>{label}</span>

      <strong>{value || "—"}</strong>
    </div>
  );
}

function ReportCard({ title, count, onClick }) {
  return (
    <div className="card">
      <h2>{title}</h2>

      <div className="report-count">{count}</div>

      <button
        className="button button-primary"
        onClick={onClick}
      >
        Export CSV
      </button>
    </div>
  );
}

function EmptyState({ message }) {
  return (
    <div className="empty-state">
      <p>{message}</p>
    </div>
  );
}

function Loading() {
  return (
    <div className="loading">
      <div className="spinner"></div>

      <p>Loading...</p>
    </div>
  );
}