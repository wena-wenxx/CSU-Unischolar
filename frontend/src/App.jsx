import { useEffect, useState } from "react";
import api, { errMsg, FILES_URL } from "./services/api";

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
  // Amounts can be empty until OAS enters the official figure.
  if (value === null || value === undefined || value === "") {
    return "Amount not set";
  }

  return `₱${Number(value).toLocaleString("en-PH", {
    minimumFractionDigits: 2,
  })}`;
}

// "needs_action" -> "needs action"
function statusLabel(status) {
  return String(status || "—").replaceAll("_", " ");
}

function fullName(student) {
  if (!student) return "—";

  return [student.first_name, student.middle_name, student.last_name]
    .filter(Boolean)
    .join(" ");
}

function fileUrl(document) {
  return `${FILES_URL}/${document.file_path}`;
}

// Required requirements that have no uploaded document yet.
function missingRequirements(application) {
  const uploaded = new Set(
    (application.documents || []).map(
      (document) => document.scholarship_requirement_id
    )
  );

  return (application.scholarship?.requirements || []).filter(
    (requirement) =>
      requirement.is_required && !uploaded.has(requirement.id)
  );
}

function enrollmentText(application) {
  if (application.status !== "approved") return "—";

  return application.enrollment_verified
    ? `Verified ${formatDate(application.enrollment_verified_at)}`
    : "Not yet verified";
}

function statusClass(status) {
  const value = String(status || "").toLowerCase();

  if (
    [
      "approved",
      "complete",
      "active",
      "ready",
      "validated",
      "processed",
    ].includes(value)
  ) {
    return "status status-success";
  }

  if (
    [
      "needs_action",
      "flagged",
      "needs_review",
      "under_review",
      "submitted",
      "processing",
    ].includes(value)
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

  async function logout() {
    // Tell the server to revoke the token BEFORE forgetting it locally.
    try {
      await api.post("/logout");
    } catch {
      // Already expired or server offline: still log out locally.
    }

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
  const [openApplicationId, setOpenApplicationId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [applyingId, setApplyingId] = useState(null);
  const [message, setMessage] = useState("");

  async function loadScholarships() {
    try {
      const response = await api.get("/scholarships");
      setScholarships(response.data);
    } catch (err) {
      setMessage(errMsg(err, "Unable to load scholarships."));
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
    } catch (err) {
      setMessage(errMsg(err, "Unable to load scholarship details."));
    }
  }

  // Step 1 of applying: create a DRAFT application, then open it so the
  // student can upload documents and press "Submit application".
  async function apply(id) {
    setMessage("");
    setApplyingId(id);

    try {
      const response = await api.post("/applications", {
        scholarship_id: id,
      });

      setSelected(null);
      setOpenApplicationId(response.data.id);
    } catch (err) {
      setMessage(errMsg(err, "Unable to start your application."));
    } finally {
      setApplyingId(null);
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

      {scholarships.length === 0 && (
        <div className="card">
          <EmptyState message="No scholarships are open right now." />
        </div>
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
                disabled={applyingId === scholarship.id}
              >
                {applyingId === scholarship.id
                  ? "Starting..."
                  : "Apply"}
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
              onClick={() => apply(selected.id)}
              disabled={applyingId === selected.id}
            >
              Apply for this scholarship
            </button>
          </div>
        </div>
      )}

      {openApplicationId && (
        <ApplicationModal
          applicationId={openApplicationId}
          intro="Your application was saved as a draft. Upload each required document below, then press Submit application."
          close={() => setOpenApplicationId(null)}
        />
      )}
    </div>
  );
}

/* =========================================================
   STUDENT APPLICATIONS
========================================================= */

function StudentApplications() {
  const [applications, setApplications] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
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

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="My Applications"
        subtitle="Track your scholarship applications"
      />

      <div className="card">
        {applications.length === 0 ? (
          <EmptyState message="You have not applied yet. Go to Scholarships and press Apply." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Scholarship</th>
                  <th>Submitted</th>
                  <th>Status</th>
                  <th>Enrollment</th>
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
                      {application.submitted_at
                        ? formatDate(application.submitted_at)
                        : "Not submitted yet"}
                    </td>

                    <td>
                      <span
                        className={statusClass(
                          application.status
                        )}
                      >
                        {statusLabel(application.status)}
                      </span>
                    </td>

                    <td>{enrollmentText(application)}</td>

                    <td>
                      <button
                        className="button button-small"
                        onClick={() =>
                          setSelectedId(application.id)
                        }
                      >
                        {["draft", "needs_action"].includes(
                          application.status
                        )
                          ? "Continue"
                          : "View"}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {selectedId && (
        <ApplicationModal
          applicationId={selectedId}
          close={() => {
            setSelectedId(null);
            load();
          }}
        />
      )}
    </div>
  );
}

/* =========================================================
   APPLICATION MODAL (STUDENT)
   Upload documents -> Submit application.
========================================================= */

function ApplicationModal({ applicationId, intro, close }) {
  const [application, setApplication] = useState(null);
  const [file, setFile] = useState(null);
  const [fileInputKey, setFileInputKey] = useState(0);
  const [requirementId, setRequirementId] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState(intro || "");

  async function load() {
    try {
      const response = await api.get(`/applications/${applicationId}`);
      setApplication(response.data);
    } catch (err) {
      setMessage(errMsg(err, "Unable to load application."));
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [applicationId]);

  async function uploadDocument() {
    if (!file || !requirementId) {
      setMessage("Select a requirement and a file.");
      return;
    }

    const formData = new FormData();

    formData.append("file", file);
    formData.append(
      "scholarship_requirement_id",
      requirementId
    );

    setBusy(true);
    setMessage("");

    try {
      await api.post(
        `/applications/${applicationId}/documents`,
        formData,
        {
          headers: {
            "Content-Type": "multipart/form-data",
          },
        }
      );

      setFile(null);
      setRequirementId("");
      setFileInputKey((key) => key + 1);

      await load();
      setMessage("Document uploaded.");
    } catch (err) {
      setMessage(errMsg(err, "Document upload failed."));
    } finally {
      setBusy(false);
    }
  }

  async function submitApplication() {
    if (
      !window.confirm(
        "Submit this application to OAS? You cannot upload more documents unless OAS asks you to."
      )
    ) {
      return;
    }

    setBusy(true);
    setMessage("");

    try {
      await api.post(`/applications/${applicationId}/submit`);
      await load();
      setMessage("Application submitted to OAS.");
    } catch (err) {
      setMessage(errMsg(err, "Unable to submit application."));
    } finally {
      setBusy(false);
    }
  }

  if (!application) {
    return (
      <div className="modal-backdrop">
        <div className="modal">
          {message ? (
            <div className="alert alert-danger">{message}</div>
          ) : (
            <Loading />
          )}

          <button className="button button-secondary" onClick={close}>
            Close
          </button>
        </div>
      </div>
    );
  }

  const editable = ["draft", "needs_action"].includes(
    application.status
  );
  const missing = missingRequirements(application);

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
              {statusLabel(application.status)}
            </span>
          </div>

          <button className="close-button" onClick={close}>
            ×
          </button>
        </div>

        {message && (
          <div className="alert alert-info">{message}</div>
        )}

        {application.status === "needs_action" &&
          application.remarks && (
            <div className="alert alert-danger">
              <strong>OAS says:</strong> {application.remarks}
            </div>
          )}

        {application.status === "approved" && (
          <p>
            Enrollment: <strong>{enrollmentText(application)}</strong>
          </p>
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
                {statusLabel(document.status)}
              </span>
            </div>
          ))
        ) : (
          <EmptyState message="No documents uploaded yet." />
        )}

        {editable && (
          <>
            <hr />

            <h3>Upload Requirement</h3>

            <div className="form-grid">
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
                      {requirement.is_required ? " (required)" : ""}
                    </option>
                  )
                )}
              </select>

              <input
                key={fileInputKey}
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                onChange={(e) =>
                  setFile(e.target.files?.[0] || null)
                }
              />
            </div>

            <p className="muted">PDF, JPG or PNG, up to 10 MB.</p>

            <div className="button-row">
              <button
                className="button button-secondary"
                onClick={uploadDocument}
                disabled={busy}
              >
                {busy ? "Please wait..." : "Upload document"}
              </button>

              <button
                className="button button-primary"
                onClick={submitApplication}
                disabled={busy || missing.length > 0}
              >
                Submit application
              </button>
            </div>

            {missing.length > 0 && (
              <p className="muted">
                Still needed before you can submit:{" "}
                {missing.map((requirement) => requirement.name).join(", ")}
              </p>
            )}
          </>
        )}
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

      {page === "databank" && (
        <DataBank />
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
          onClick={() => setPage("databank")}
        >
          Data Bank
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
  const [remarks, setRemarks] = useState("");
  const [filter, setFilter] = useState("all");
  const [busyDocumentId, setBusyDocumentId] = useState(null);
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

  async function openApplication(id) {
    try {
      const response = await api.get(`/applications/${id}`);
      setSelected(response.data);
      setRemarks(response.data.remarks || "");
    } catch (err) {
      alert(errMsg(err, "Unable to load application."));
    }
  }

  async function review(id, status) {
    if (status === "needs_action" && !remarks.trim()) {
      alert("Type in Remarks what the student needs to fix, then press Needs Action again.");
      return;
    }

    try {
      await api.patch(`/applications/${id}/review`, {
        status,
        remarks: remarks.trim() || null,
      });

      await load();

      if (selected) {
        await openApplication(id);
      }
    } catch (err) {
      alert(errMsg(err, "Unable to update application."));
    }
  }

  // OAS confirms the approved student is currently enrolled.
  async function verifyEnrollment(application) {
    const enrolled = window.confirm(
      `Is ${fullName(application.student)} CURRENTLY ENROLLED this semester?\n\nOK = Yes, enrolled\nCancel = No, not enrolled`
    );

    try {
      const response = await api.post(
        `/applications/${application.id}/verify-enrollment`,
        {
          currently_enrolled: enrolled,
        }
      );

      alert(response.data?.message || "Enrollment recorded.");

      await load();

      if (selected?.id === application.id) {
        await openApplication(application.id);
      }
    } catch (err) {
      alert(errMsg(err, "Unable to verify enrollment."));
    }
  }

  async function validateDocument(documentId) {
    setBusyDocumentId(documentId);

    try {
      const response = await api.post(
        `/documents/${documentId}/validate`
      );

      alert(
        response.data?.message ||
          "AI validation completed."
      );
    } catch (err) {
      alert(errMsg(err, "AI validation failed."));
    } finally {
      setBusyDocumentId(null);
    }

    await load();

    if (selected) {
      await openApplication(selected.id);
    }
  }

  const shown = applications.filter((application) => {
    if (filter === "all") return true;
    if (filter === "to_verify") {
      return (
        application.status === "approved" &&
        !application.enrollment_verified
      );
    }
    return application.status === filter;
  });

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Application Review"
        subtitle="Check documents, record the agency's decision, and verify enrollment"
      />

      <div className="card">
        <div className="button-row">
          {[
            ["all", "All"],
            ["submitted", "Submitted"],
            ["under_review", "Under review"],
            ["needs_action", "Needs action"],
            ["complete", "Complete"],
            ["approved", "Approved"],
            ["to_verify", "Approved, enrollment not verified"],
            ["draft", "Drafts"],
          ].map(([key, label]) => (
            <button
              key={key}
              className={
                filter === key
                  ? "button button-small button-primary"
                  : "button button-small button-secondary"
              }
              onClick={() => setFilter(key)}
            >
              {label}
            </button>
          ))}
        </div>

        {shown.length === 0 ? (
          <EmptyState message="No applications in this list." />
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
                {shown.map((application) => (
                  <tr key={application.id}>
                    <td>{fullName(application.student)}</td>

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
                        {statusLabel(application.status)}
                      </span>
                    </td>

                    <td>{enrollmentText(application)}</td>

                    <td>
                      <div className="button-row">
                        <button
                          className="button button-small"
                          onClick={() =>
                            openApplication(application.id)
                          }
                        >
                          Review
                        </button>

                        {application.status === "approved" && (
                          <button
                            className="button button-small button-success"
                            onClick={() =>
                              verifyEnrollment(application)
                            }
                          >
                            {application.enrollment_verified
                              ? "Re-verify"
                              : "Verify enrollment"}
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
                  {statusLabel(selected.status)}
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
                value={fullName(selected.student)}
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
                value={enrollmentText(selected)}
              />

              <ProfileItem
                label="Scholarship"
                value={selected.scholarship?.name}
              />
            </div>

            {missingRequirements(selected).length > 0 && (
              <div className="alert alert-danger">
                Missing required documents:{" "}
                {missingRequirements(selected)
                  .map((requirement) => requirement.name)
                  .join(", ")}
              </div>
            )}

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
                      <a
                        href={fileUrl(document)}
                        target="_blank"
                        rel="noreferrer"
                      >
                        {document.original_filename}
                      </a>
                    </p>

                    <AiResult result={document.validation_result} />
                  </div>

                  <div className="button-row">
                    <span
                      className={statusClass(
                        document.status
                      )}
                    >
                      {statusLabel(document.status)}
                    </span>

                    <button
                      className="button button-small"
                      onClick={() =>
                        validateDocument(document.id)
                      }
                      disabled={busyDocumentId === document.id}
                    >
                      {busyDocumentId === document.id
                        ? "Checking..."
                        : document.validation_result
                          ? "Re-run AI check"
                          : "Run AI check"}
                    </button>
                  </div>
                </div>
              ))
            ) : (
              <EmptyState message="No documents submitted." />
            )}

            <label>Remarks (shown to the student)</label>

            <textarea
              value={remarks}
              onChange={(e) => setRemarks(e.target.value)}
              placeholder="Example: Your Certificate of Grades is blurry. Please upload a clearer copy."
            />

            <p className="muted">
              OAS checks the documents. The external agency makes the
              final decision; use Approved / Rejected to record it.
            </p>

            <div className="button-row">
              <button
                className="button button-secondary"
                onClick={() =>
                  review(selected.id, "under_review")
                }
              >
                Under Review
              </button>

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
                Complete (forward to agency)
              </button>

              <button
                className="button button-primary"
                onClick={() =>
                  review(selected.id, "approved")
                }
              >
                Approved by agency
              </button>

              <button
                className="button button-danger"
                onClick={() =>
                  review(selected.id, "rejected")
                }
              >
                Rejected by agency
              </button>

              {selected.status === "approved" && (
                <button
                  className="button button-success"
                  onClick={() => verifyEnrollment(selected)}
                >
                  Verify enrollment
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/* The AI only FLAGS possible problems. Staff always decide. */
function AiResult({ result }) {
  if (!result) {
    return <p className="muted">AI check: not run yet</p>;
  }

  const flags = String(result.flags || "")
    .split(/\n|;\s*/)
    .map((flag) => flag.trim())
    .filter(Boolean);

  return (
    <div className="ai-result">
      <p className="muted">
        AI check
        {result.confidence_score !== null &&
        result.confidence_score !== undefined
          ? ` · name match score ${Number(result.confidence_score)}`
          : ""}
        {result.extracted_data?.detected_name
          ? ` · name found: ${result.extracted_data.detected_name}`
          : ""}
      </p>

      {flags.length ? (
        <ul>
          {flags.map((flag) => (
            <li key={flag}>{flag}</li>
          ))}
        </ul>
      ) : (
        <p className="muted">No issues flagged.</p>
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

  // Approved applications not yet tagged as a grantee.
  const untagged = applications.filter(
    (application) =>
      !records.some(
        (record) =>
          record.student_id === application.student_id &&
          record.scholarship_id === application.scholarship_id
      )
  );
  const readyToTag = untagged.filter(
    (application) => application.enrollment_verified
  );
  const waitingForVerification = untagged.filter(
    (application) => !application.enrollment_verified
  );

  async function tagGrantee(application) {
    const hasAtm = window.confirm(
      `Does ${fullName(application.student)} already have an ATM card for the stipend?\n\nOK = Yes\nCancel = No`
    );

    try {
      await api.post(
        `/applications/${application.id}/scholar-record`,
        {
          application_id: application.id,
          currently_enrolled: true,
          has_atm: hasAtm,
        }
      );

      alert(`${fullName(application.student)} is now tagged as a grantee.`);

      await load();
    } catch (err) {
      alert(errMsg(err, "Unable to tag grantee."));
    }
  }

  async function updateRecord(record, changes, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return;

    try {
      await api.patch(`/scholar-records/${record.id}`, changes);
      await load();
    } catch (err) {
      alert(errMsg(err, "Unable to update scholar record."));
    }
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Scholar Records"
        subtitle="Tag verified students as grantees and manage active scholars"
      />

      <section className="card">
        <h2>Ready to Tag as Grantee</h2>

        <p className="muted">
          Approved by the agency and enrollment verified by OAS.
        </p>

        {readyToTag.length === 0 ? (
          <EmptyState message="No students are ready for tagging." />
        ) : (
          readyToTag.map((application) => (
            <div
              className="list-item"
              key={application.id}
            >
              <div>
                <strong>{fullName(application.student)}</strong>

                <p className="muted">
                  {application.student?.student_id} ·{" "}
                  {application.scholarship?.name} ·{" "}
                  {enrollmentText(application)}
                </p>
              </div>

              <button
                className="button button-primary"
                onClick={() => tagGrantee(application)}
              >
                Tag as Grantee
              </button>
            </div>
          ))
        )}

        {waitingForVerification.length > 0 && (
          <p className="muted">
            {waitingForVerification.length} approved application(s)
            still need enrollment verification. Go to Applications and
            press "Verify enrollment".
          </p>
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
                  <th>Tagged</th>
                  <th>Actions</th>
                </tr>
              </thead>

              <tbody>
                {records.map((record) => (
                  <tr key={record.id}>
                    <td>{fullName(record.student)}</td>

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

                    <td>{formatDate(record.grantee_tagged_at)}</td>

                    <td>
                      <div className="button-row">
                        <button
                          className="button button-small"
                          onClick={() =>
                            updateRecord(record, {
                              has_atm: !record.has_atm,
                            })
                          }
                        >
                          {record.has_atm
                            ? "Mark no ATM"
                            : "Mark has ATM"}
                        </button>

                        {record.status === "active" && (
                          <>
                            <button
                              className="button button-small button-success"
                              onClick={() =>
                                updateRecord(
                                  record,
                                  { status: "completed" },
                                  `Mark ${fullName(record.student)}'s scholarship as COMPLETED?`
                                )
                              }
                            >
                              Completed
                            </button>

                            <button
                              className="button button-small button-danger"
                              onClick={() =>
                                updateRecord(
                                  record,
                                  { status: "inactive" },
                                  `Set ${fullName(record.student)}'s scholarship to INACTIVE?`
                                )
                              }
                            >
                              Inactive
                            </button>
                          </>
                        )}
                      </div>
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

  async function createPayroll(record) {
    const amountText = window.prompt(
      `Amount for ${fullName(record.student)} (pesos):`,
      record.scholarship?.amount ?? ""
    );

    if (amountText === null) return;

    const amount = Number(String(amountText).replaceAll(",", ""));

    if (!amountText.trim() || Number.isNaN(amount) || amount < 0) {
      alert("Please type a valid amount, for example 5000.");
      return;
    }

    const period = window.prompt(
      "Payroll period:",
      "1st Semester AY 2026-2027"
    );

    if (!period) return;

    try {
      await api.post(
        `/scholar-records/${record.id}/payroll`,
        {
          scholar_record_id: record.id,
          amount,
          period,
          bank_atm_status: record.has_atm ? "Yes" : "No",
        }
      );

      await load();
    } catch (err) {
      alert(errMsg(err, "Unable to create payroll record."));
    }
  }

  async function setPayrollStatus(item, status) {
    try {
      await api.patch(`/payroll/${item.id}`, { status });
      await load();
    } catch (err) {
      alert(errMsg(err, "Unable to update payroll record."));
    }
  }

  const activeRecords = records.filter(
    (record) => record.status === "active"
  );

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Payroll Preparation"
        subtitle="Prepare payroll entries for active, enrolled grantees"
      />

      <section className="card">
        <h2>Active Scholars</h2>

        {activeRecords.length === 0 ? (
          <EmptyState message="No active scholars yet. Tag grantees on the Scholar Records page first." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Scholarship</th>
                  <th>Program amount</th>
                  <th>Enrolled</th>
                  <th>ATM</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {activeRecords.map((record) => (
                  <tr key={record.id}>
                    <td>{fullName(record.student)}</td>

                    <td>
                      {record.scholarship?.name}
                    </td>

                    <td>
                      {formatMoney(
                        record.scholarship?.amount
                      )}
                    </td>

                    <td>
                      {record.currently_enrolled ? "Yes" : "No"}
                    </td>

                    <td>
                      {record.has_atm ? "Yes" : "No"}
                    </td>

                    <td>
                      <button
                        className="button button-small button-primary"
                        onClick={() => createPayroll(record)}
                        disabled={!record.currently_enrolled}
                        title={
                          record.currently_enrolled
                            ? ""
                            : "Student must be currently enrolled"
                        }
                      >
                        Add to Payroll
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

        <p className="muted">
          Draft → Ready (checked and ready to send) → Processed (released).
        </p>

        {payroll.length === 0 ? (
          <EmptyState message="No payroll records yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Scholarship</th>
                  <th>Amount</th>
                  <th>Period</th>
                  <th>ATM</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {payroll.map((item) => (
                  <tr key={item.id}>
                    <td>{fullName(item.scholar_record?.student)}</td>

                    <td>{item.scholar_record?.scholarship?.name}</td>

                    <td>{formatMoney(item.amount)}</td>

                    <td>{item.period}</td>

                    <td>{item.bank_atm_status}</td>

                    <td>
                      <span
                        className={statusClass(
                          item.status
                        )}
                      >
                        {item.status}
                      </span>
                    </td>

                    <td>
                      {item.status === "draft" && (
                        <button
                          className="button button-small button-primary"
                          onClick={() =>
                            setPayrollStatus(item, "ready")
                          }
                        >
                          Mark Ready
                        </button>
                      )}

                      {item.status === "ready" && (
                        <div className="button-row">
                          <button
                            className="button button-small button-success"
                            onClick={() =>
                              setPayrollStatus(item, "processed")
                            }
                          >
                            Mark Processed
                          </button>

                          <button
                            className="button button-small button-secondary"
                            onClick={() =>
                              setPayrollStatus(item, "draft")
                            }
                          >
                            Back to Draft
                          </button>
                        </div>
                      )}

                      {item.status === "processed" && "—"}
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
  const [managing, setManaging] = useState(null);
  const [loading, setLoading] = useState(true);

  const emptyForm = {
    name: "",
    description: "",
    provider: "",
    amount: "",
  };

  const [form, setForm] = useState(emptyForm);

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
        amount: form.amount === "" ? null : Number(form.amount),
        status: "active",
      });

      setForm(emptyForm);

      await load();

      alert("Scholarship created. Press Manage to add its requirements.");
    } catch (err) {
      alert(errMsg(err, "Unable to create scholarship."));
    }
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Scholarship Programs"
        subtitle="Manage scholarship programs and their requirements"
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
            <label>Amount (optional)</label>

            <input
              type="number"
              min="0"
              step="0.01"
              value={form.amount}
              onChange={(e) =>
                setForm({
                  ...form,
                  amount: e.target.value,
                })
              }
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

        {scholarships.length === 0 ? (
          <EmptyState message="No scholarship programs yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Provider</th>
                  <th>Amount</th>
                  <th>Requirements</th>
                  <th>Status</th>
                  <th>Action</th>
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

                    <td>{scholarship.requirements?.length || 0}</td>

                    <td>
                      <span
                        className={statusClass(
                          scholarship.status
                        )}
                      >
                        {scholarship.status}
                      </span>
                    </td>

                    <td>
                      <button
                        className="button button-small"
                        onClick={() => setManaging(scholarship)}
                      >
                        Manage
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {managing && (
        <ManageScholarshipModal
          scholarshipId={managing.id}
          close={() => {
            setManaging(null);
            load();
          }}
        />
      )}
    </div>
  );
}

function ManageScholarshipModal({ scholarshipId, close }) {
  const [form, setForm] = useState(null);
  const [requirements, setRequirements] = useState([]);
  const [newRequirement, setNewRequirement] = useState("");
  const [message, setMessage] = useState("");

  async function load() {
    try {
      const response = await api.get(`/scholarships/${scholarshipId}`);
      const data = response.data;

      setForm({
        name: data.name || "",
        provider: data.provider || "",
        description: data.description || "",
        amount: data.amount ?? "",
        application_start: data.application_start || "",
        application_end: data.application_end || "",
        status: data.status || "active",
      });
      setRequirements(data.requirements || []);
    } catch (err) {
      setMessage(errMsg(err, "Unable to load scholarship."));
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scholarshipId]);

  async function save(e) {
    e.preventDefault();
    setMessage("");

    try {
      await api.put(`/scholarships/${scholarshipId}`, {
        ...form,
        amount: form.amount === "" ? null : Number(form.amount),
        application_start: form.application_start || null,
        application_end: form.application_end || null,
      });

      setMessage("Saved.");
    } catch (err) {
      setMessage(errMsg(err, "Unable to save scholarship."));
    }
  }

  async function addRequirement(e) {
    e.preventDefault();

    if (!newRequirement.trim()) return;

    try {
      await api.post(`/scholarships/${scholarshipId}/requirements`, {
        name: newRequirement.trim(),
        is_required: true,
      });

      setNewRequirement("");
      await load();
    } catch (err) {
      setMessage(errMsg(err, "Unable to add requirement."));
    }
  }

  async function removeRequirement(requirement) {
    if (!window.confirm(`Remove "${requirement.name}" from this scholarship?`)) {
      return;
    }

    try {
      await api.delete(`/requirements/${requirement.id}`);
      await load();
    } catch (err) {
      setMessage(errMsg(err, "Unable to remove requirement."));
    }
  }

  function field(key, value) {
    setForm({ ...form, [key]: value });
  }

  return (
    <div className="modal-backdrop">
      <div className="modal modal-large">
        <div className="card-header">
          <h2>Manage Scholarship</h2>

          <button className="close-button" onClick={close}>
            ×
          </button>
        </div>

        {message && <div className="alert alert-info">{message}</div>}

        {!form ? (
          <Loading />
        ) : (
          <>
            <form className="form-grid" onSubmit={save}>
              <div className="full-column">
                <label>Name</label>
                <input
                  value={form.name}
                  onChange={(e) => field("name", e.target.value)}
                  required
                />
              </div>

              <div>
                <label>Provider</label>
                <input
                  value={form.provider}
                  onChange={(e) => field("provider", e.target.value)}
                />
              </div>

              <div>
                <label>Amount</label>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={form.amount}
                  onChange={(e) => field("amount", e.target.value)}
                />
              </div>

              <div>
                <label>Application start</label>
                <input
                  type="date"
                  value={String(form.application_start).slice(0, 10)}
                  onChange={(e) =>
                    field("application_start", e.target.value)
                  }
                />
              </div>

              <div>
                <label>Application end</label>
                <input
                  type="date"
                  value={String(form.application_end).slice(0, 10)}
                  onChange={(e) =>
                    field("application_end", e.target.value)
                  }
                />
              </div>

              <div>
                <label>Status</label>
                <select
                  value={form.status}
                  onChange={(e) => field("status", e.target.value)}
                >
                  <option value="active">active (students can apply)</option>
                  <option value="inactive">inactive</option>
                  <option value="closed">closed</option>
                </select>
              </div>

              <div className="full-column">
                <label>Description</label>
                <textarea
                  value={form.description}
                  onChange={(e) => field("description", e.target.value)}
                />
              </div>

              <button className="button button-primary">
                Save changes
              </button>
            </form>

            <hr />

            <h3>Requirements</h3>

            {requirements.length === 0 ? (
              <EmptyState message="No requirements yet." />
            ) : (
              requirements.map((requirement) => (
                <div className="list-item" key={requirement.id}>
                  <span>
                    {requirement.name}
                    {requirement.is_required && (
                      <span className="required">Required</span>
                    )}
                  </span>

                  <button
                    className="button button-small button-danger"
                    onClick={() => removeRequirement(requirement)}
                  >
                    Remove
                  </button>
                </div>
              ))
            )}

            <form className="button-row" onSubmit={addRequirement}>
              <input
                value={newRequirement}
                onChange={(e) => setNewRequirement(e.target.value)}
                placeholder="e.g. Certificate of Registration (COR)"
              />

              <button className="button button-secondary">
                Add requirement
              </button>
            </form>
          </>
        )}
      </div>
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

    // "\uFEFF" makes Excel read the file as UTF-8 (keeps ñ and Ñ intact).
    const blob = new Blob(["\uFEFF" + csv], {
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
                Student_ID:
                  item.student?.student_id || "",
                Last_Name: item.student?.last_name || "",
                First_Name: item.student?.first_name || "",
                Middle_Name: item.student?.middle_name || "",
                Course: item.student?.course || "",
                Year_Level: item.student?.year_level || "",
                College: item.student?.college || "",
                Contact_Number: item.student?.contact_number || "",
                Scholarship:
                  item.scholarship?.name || "",
                Status: item.status || "",
                Missing_Documents: missingRequirements(item)
                  .map((requirement) => requirement.name)
                  .join("; "),
                AI_Flagged_Documents: (item.documents || []).filter(
                  (document) => document.status === "flagged"
                ).length,
                Submitted: item.submitted_at
                  ? formatDate(item.submitted_at)
                  : "",
                Enrollment_Verified: item.enrollment_verified
                  ? "Yes"
                  : "No",
                Remarks: item.remarks || "",
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
                Student_ID:
                  item.student?.student_id || "",
                Student: fullName(item.student),
                Course: item.student?.course || "",
                Scholarship:
                  item.scholarship?.name || "",
                Status: item.status || "",
                Enrolled:
                  item.currently_enrolled
                    ? "Yes"
                    : "No",
                ATM: item.has_atm ? "Yes" : "No",
                Tagged: item.grantee_tagged_at
                  ? formatDate(item.grantee_tagged_at)
                  : "",
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
                Student_ID:
                  item.scholar_record?.student?.student_id || "",
                Student: fullName(item.scholar_record?.student),
                Course: item.scholar_record?.student?.course || "",
                Scholarship:
                  item.scholar_record?.scholarship?.name || "",
                Amount: item.amount,
                Period: item.period,
                ATM_Status:
                  item.bank_atm_status || "",
                Status: item.status || "",
                Signature: item.signature || "",
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
   DATA BANK (STAFF)
   One place to search any student and see every application,
   scholarship and payroll entry they have ever had.
========================================================= */

function DataBank() {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState([]);
  const [searched, setSearched] = useState(false);
  const [loading, setLoading] = useState(false);
  const [student, setStudent] = useState(null);
  const [message, setMessage] = useState("");

  async function search(e) {
    e?.preventDefault();
    setLoading(true);
    setMessage("");

    try {
      const response = await api.get("/staff/data-bank", {
        params: { q: query },
      });

      setResults(response.data);
      setSearched(true);
    } catch (err) {
      setMessage(errMsg(err, "Search failed."));
    } finally {
      setLoading(false);
    }
  }

  async function openStudent(id) {
    try {
      const response = await api.get(`/staff/data-bank/${id}`);
      setStudent(response.data);
    } catch (err) {
      setMessage(errMsg(err, "Unable to load student history."));
    }
  }

  useEffect(() => {
    search();
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

        {message && <div className="alert alert-danger">{message}</div>}

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
        <div className="modal-backdrop">
          <div className="modal modal-large">
            <div className="card-header">
              <div>
                <h2>{fullName(student)}</h2>

                {student.has_active_scholarship ? (
                  <span className="status status-success">
                    Has an active scholarship
                  </span>
                ) : (
                  <span className="status status-neutral">
                    No active scholarship
                  </span>
                )}
              </div>

              <button
                className="close-button"
                onClick={() => setStudent(null)}
              >
                ×
              </button>
            </div>

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
          </div>
        </div>
      )}
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
    ["databank", "Data Bank"],
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