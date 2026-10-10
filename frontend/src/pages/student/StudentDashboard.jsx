import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../../services/api";
import { availabilityInfo, deadlineText, formatDate, formatMoney, preview, timeAgo } from "../../lib/format";
import DashboardHero from "../../components/DashboardHero";
import QuickActions from "../../components/QuickActions";
import StatCard from "../../components/StatCard";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import AnnouncementImage from "../../components/AnnouncementImage";
import Loading from "../../components/Loading";

export default function StudentDashboard() {
  const [applications, setApplications] = useState([]);
  const [scholarships, setScholarships] = useState([]);
  const [announcements, setAnnouncements] = useState([]);
  const [profile, setProfile] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api.get("/my-applications"),
      api.get("/scholarships"),
      api.get("/profile"),
      api.get("/announcements", { params: { limit: 3 } }),
    ])
      .then(([apps, scholarshipsResponse, profileResponse, announcementsResponse]) => {
        setApplications(apps.data);
        setScholarships(scholarshipsResponse.data);
        setProfile(profileResponse.data);
        setAnnouncements(announcementsResponse.data);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loading />;

  const count = (...statuses) => applications.filter((a) => statuses.includes(a.status)).length;
  const grant = profile?.active_scholar_record;
  const openCount = scholarships.filter((s) => s.availability === "open").length;
  const needsAction = applications.filter((a) => a.status === "needs_action");

  return (
    <div>
      <DashboardHero
        title={profile?.student ? `Welcome, ${profile.student.first_name}!` : "Student Dashboard"}
        subtitle="Apply for scholarships, keep your documents in one place, and follow every step online."
      />

      {grant && (
        <div className="grant-banner">
          <div>
            <span className="grant-label">Current scholarship</span>
            <strong>{grant.scholarship?.name}</strong>
            <span>
              Grantee since {formatDate(grant.grantee_tagged_at)} · {formatMoney(grant.scholarship?.amount)} per
              semester
            </span>
          </div>

          <div className="grant-payroll">
            <span className="grant-label">Latest payroll</span>
            {grant.payroll_records?.length ? (
              <>
                <strong>{grant.payroll_records[grant.payroll_records.length - 1].period}</strong>
                <StatusBadge status={grant.payroll_records[grant.payroll_records.length - 1].status} />
              </>
            ) : (
              <span>Not prepared yet</span>
            )}
          </div>
        </div>
      )}

      {needsAction.map((application) => (
        <div className="alert alert-warning" key={application.id}>
          <strong>OAS needs something from you</strong> for {application.scholarship?.name}.{" "}
          <Link to={`/student/applications/${application.id}`}>Open the application</Link> to see what to fix.
        </div>
      ))}

      <div className="stats-grid">
        <StatCard title="Open Scholarships" value={openCount} to="/student/scholarships" hint="Browse" />
        <StatCard title="My Applications" value={applications.length} to="/student/applications" />
        <StatCard
          title="Being Processed"
          value={count("submitted", "under_review", "complete")}
          to="/student/applications?status=processing"
        />
        <StatCard title="Approved" value={count("approved")} to="/student/applications?status=approved" />
      </div>

      <QuickActions
        actions={[
          { to: "/student/scholarships", icon: "🎓", label: "Browse Scholarships", text: "See open programs and deadlines" },
          { to: "/student/applications", icon: "📋", label: "My Applications", text: "Follow each step" },
          { to: "/student/documents", icon: "📁", label: "My Documents", text: "Upload once, reuse later" },
          { to: "/student/help", icon: "❓", label: "Help", text: "How to apply" },
        ]}
      />

      {applications.length === 0 && !grant && (
        <section className="card how-to-apply">
          <h2>How to apply</h2>
          <ol>
            <li>Open <Link to="/student/scholarships">Scholarships</Link> and choose an open program.</li>
            <li>Press <strong>Apply</strong>. A checklist of the required documents appears.</li>
            <li>Upload one file for each document (PDF, JPG or PNG).</li>
            <li>When all are uploaded, <strong>Submit application</strong> turns green. Press it.</li>
            <li>Follow your status in My Applications. The 🔔 bell tells you when it changes.</li>
          </ol>
        </section>
      )}

      <section className="card">
        <div className="card-header">
          <h2>Latest Announcements</h2>
        </div>

        {announcements.length === 0 ? (
          <EmptyState message="No announcements right now." />
        ) : (
          <div className="announcement-cards">
            {announcements.map((item) => (
              <Link className="announcement-card" key={item.id} to={`/student/announcements/${item.id}`}>
                <AnnouncementImage item={item} />
                <div className="announcement-card-text">
                  <small className="muted">{formatDate(item.posted_at)}</small>
                  <strong>{item.title}</strong>
                  <p>{preview(item.body, 120)}</p>
                  <span className="read-more">Read more →</span>
                </div>
              </Link>
            ))}
          </div>
        )}
      </section>

      <div className="dashboard-grid">
        <section className="card">
          <div className="card-header">
            <h2>Open Scholarships</h2>

            <Link className="button button-secondary" to="/student/scholarships">
              View all
            </Link>
          </div>

          {scholarships.length === 0 ? (
            <EmptyState message="No scholarships are open right now." />
          ) : (
            scholarships.slice(0, 5).map((scholarship) => {
              const info = availabilityInfo(scholarship);

              return (
                <div className="list-item" key={scholarship.id}>
                  <div>
                    <Link to={`/student/scholarships/${scholarship.id}`}>
                      <strong>{scholarship.name}</strong>
                    </Link>

                    <p className="muted">
                      {scholarship.provider || "CSU"} · {deadlineText(scholarship)}
                    </p>
                  </div>

                  <span className={`status status-${info.tone}`}>{info.label}</span>
                </div>
              );
            })
          )}
        </section>

        <section className="card">
          <div className="card-header">
            <h2>My Recent Applications</h2>

            <Link className="button button-secondary" to="/student/applications">
              View all
            </Link>
          </div>

          {applications.length === 0 ? (
            <EmptyState message="You have no applications yet." />
          ) : (
            applications.slice(0, 5).map((application) => (
              <div className="list-item" key={application.id}>
                <div>
                  <Link to={`/student/applications/${application.id}`}>
                    <strong>{application.scholarship?.name || "Scholarship"}</strong>
                  </Link>

                  <p className="muted">Updated {timeAgo(application.updated_at)}</p>
                </div>

                <StatusBadge status={application.status} />
              </div>
            ))
          )}
        </section>
      </div>
    </div>
  );
}
