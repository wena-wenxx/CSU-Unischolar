import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { formatMoney } from "../../lib/format";
import { startApplication } from "../../lib/applications";
import { useToast } from "../../components/Toast";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

export default function ScholarshipsPage() {
  const navigate = useNavigate();
  const toast = useToast();

  const [scholarships, setScholarships] = useState([]);
  const [loading, setLoading] = useState(true);
  const [applyingId, setApplyingId] = useState(null);

  useEffect(() => {
    api
      .get("/scholarships")
      .then((response) => setScholarships(response.data))
      .catch((err) => toast.error(errMsg(err, "Unable to load scholarships.")))
      .finally(() => setLoading(false));
  }, [toast]);

  async function apply(id) {
    setApplyingId(id);
    await startApplication(id, navigate, toast);
    setApplyingId(null);
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader title="Scholarships" subtitle="View available scholarship programs" />

      {scholarships.length === 0 && (
        <div className="card">
          <EmptyState message="No scholarships are open right now." />
        </div>
      )}

      <div className="card-grid">
        {scholarships.map((scholarship) => (
          <div className="card scholarship-card" key={scholarship.id}>
            <span className="badge">{scholarship.status}</span>

            <h2>{scholarship.name}</h2>

            <p className="muted">{scholarship.description || "Scholarship assistance program"}</p>

            <div className="amount">{formatMoney(scholarship.amount)}</div>

            <p>
              Provider: <strong>{scholarship.provider || "—"}</strong>
            </p>

            <div className="button-row">
              <Link className="button button-secondary" to={`/student/scholarships/${scholarship.id}`}>
                Details
              </Link>

              <button
                className="button button-primary"
                onClick={() => apply(scholarship.id)}
                disabled={applyingId === scholarship.id}
              >
                {applyingId === scholarship.id ? "Starting..." : "Apply"}
              </button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
