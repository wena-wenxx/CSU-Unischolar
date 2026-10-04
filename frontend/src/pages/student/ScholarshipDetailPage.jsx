import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { formatDate, formatMoney } from "../../lib/format";
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
  const [error, setError] = useState("");
  const [applying, setApplying] = useState(false);

  useEffect(() => {
    api
      .get(`/scholarships/${id}`)
      .then((response) => setScholarship(response.data))
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

  return (
    <div>
      <PageHeader
        title={scholarship.name}
        subtitle={scholarship.provider}
        actions={
          <Link className="button button-secondary" to="/student/scholarships">
            ← All scholarships
          </Link>
        }
      />

      <section className="card">
        <p>{scholarship.description || "No description available."}</p>

        <div className="detail-grid">
          <ProfileItem label="Amount" value={formatMoney(scholarship.amount)} />
          <ProfileItem label="Status" value={scholarship.status} />
          <ProfileItem label="Applications open" value={formatDate(scholarship.application_start)} />
          <ProfileItem label="Applications close" value={formatDate(scholarship.application_end)} />
        </div>
      </section>

      <section className="card">
        <h2>Requirements</h2>

        {scholarship.requirements?.length ? (
          <ul className="requirements-list">
            {scholarship.requirements.map((requirement) => (
              <li key={requirement.id}>
                {requirement.name}

                {requirement.is_required && <span className="required">Required</span>}
              </li>
            ))}
          </ul>
        ) : (
          <p className="muted">No requirements listed.</p>
        )}

        <button className="button button-primary" onClick={apply} disabled={applying}>
          {applying ? "Starting..." : "Apply for this scholarship"}
        </button>
      </section>
    </div>
  );
}
