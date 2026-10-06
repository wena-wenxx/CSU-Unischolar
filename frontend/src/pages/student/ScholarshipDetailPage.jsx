import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { availabilityInfo, categoryLabel, formatDate, formatMoney } from "../../lib/format";
import { useToast } from "../../components/Toast";
import { startApplication } from "../../lib/applications";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";
import Loading from "../../components/Loading";
import EmptyState from "../../components/EmptyState";

export default function ScholarshipDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();

  const [scholarship, setScholarship] = useState(null);
  const [mine, setMine] = useState(null);
  const [grant, setGrant] = useState(null);
  const [error, setError] = useState("");
  const [applying, setApplying] = useState(false);

  useEffect(() => {
    Promise.all([api.get(`/scholarships/${id}`), api.get("/my-applications"), api.get("/profile")])
      .then(([scholarshipResponse, applicationsResponse, profileResponse]) => {
        setScholarship(scholarshipResponse.data);
        setMine(applicationsResponse.data.find((a) => String(a.scholarship_id) === String(id)) || null);
        setGrant(profileResponse.data?.active_scholar_record || null);
      })
      .catch((err) => setError(errMsg(err, "Unable to load scholarship details.")));
  }, [id]);

  async function apply() {
    setApplying(true);
    await startApplication(scholarship.id, navigate, toast);
    setApplying(false);
  }

  if (error) {
    return (
      <div className="card">
        <EmptyState
          message={error}
          action={<Link className="button button-secondary" to="/student/scholarships">Back to scholarships</Link>}
        />
      </div>
    );
  }

  if (!scholarship) return <Loading />;

  const info = availabilityInfo(scholarship);
  const open = scholarship.availability === "open";

  let action;
  if (mine) {
    action = (
      <Link className="button button-primary" to={`/student/applications/${mine.id}`}>
        View my application
      </Link>
    );
  } else if (grant) {
    action = (
      <p className="muted">
        You are currently a grantee of <strong>{grant.scholarship?.name}</strong>, so you cannot apply for another
        scholarship at this time.
      </p>
    );
  } else if (open) {
    action = (
      <button className="button button-primary" onClick={apply} disabled={applying}>
        {applying ? "Starting..." : "Apply for this scholarship"}
      </button>
    );
  } else {
    action = <p className="muted">This program is not accepting applications right now ({info.label.toLowerCase()}).</p>;
  }

  return (
    <div>
      <PageHeader
        back={{ to: "/student/scholarships", label: "All scholarships" }}
        title={scholarship.name}
        subtitle={scholarship.provider}
      />

      <section className="card">
        <div className="scholarship-card-top">
          <span className="badge">{categoryLabel(scholarship.category)}</span>
          <span className={`status status-${info.tone}`}>{info.label}</span>
        </div>

        <p>{scholarship.description || "No description available."}</p>

        <div className="detail-grid">
          <ProfileItem label="Amount (per semester)" value={formatMoney(scholarship.amount)} />
          <ProfileItem label="Applications open" value={formatDate(scholarship.application_start)} />
          <ProfileItem label="Deadline" value={formatDate(scholarship.application_end)} />
          <ProfileItem label="Provider" value={scholarship.provider || "—"} />
        </div>
      </section>

      <section className="card">
        <h2>Documents you will need</h2>

        {scholarship.requirements?.length ? (
          <ol className="requirements-list">
            {scholarship.requirements.map((requirement) => (
              <li key={requirement.id}>
                <div>
                  {requirement.name}
                  {requirement.is_required && <span className="required">Required</span>}
                  {requirement.description && <p className="muted small">{requirement.description}</p>}
                </div>
              </li>
            ))}
          </ol>
        ) : (
          <p className="muted">No requirements listed.</p>
        )}

        <p className="muted small">Accepted files: PDF, JPG or PNG, up to 10 MB each.</p>

        {action}
      </section>
    </div>
  );
}
