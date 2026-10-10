import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { CATEGORY_LABELS, availabilityInfo, categoryLabel, deadlineText, formatMoney, isAgencyDirect } from "../../lib/format";
import { startApplication } from "../../lib/applications";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const SORTS = {
  deadline: "Closing soonest",
  amount: "Highest amount",
  name: "Name (A–Z)",
};

export default function ScholarshipsPage() {
  const navigate = useNavigate();
  const toast = useToast();

  const [scholarships, setScholarships] = useState([]);
  const [myApplications, setMyApplications] = useState([]);
  const [grant, setGrant] = useState(null);
  const [loading, setLoading] = useState(true);
  const [applyingId, setApplyingId] = useState(null);
  const [category, setCategory] = useState("all");
  const [sort, setSort] = useState("deadline");
  const [openOnly, setOpenOnly] = useState(false);

  useEffect(() => {
    Promise.all([api.get("/scholarships"), api.get("/my-applications"), api.get("/profile")])
      .then(([scholarshipsResponse, applicationsResponse, profileResponse]) => {
        setScholarships(scholarshipsResponse.data);
        setMyApplications(applicationsResponse.data);
        setGrant(profileResponse.data?.active_scholar_record || null);
      })
      .catch((err) => toast.error(errMsg(err, "Unable to load scholarships.")))
      .finally(() => setLoading(false));
  }, [toast]);

  async function apply(id) {
    setApplyingId(id);
    await startApplication(id, navigate, toast);
    setApplyingId(null);
  }

  if (loading) return <Loading />;

  const applicationFor = (scholarshipId) => myApplications.find((a) => a.scholarship_id === scholarshipId);

  const inCategory = scholarships.filter((s) => category === "all" || s.category === category);
  // Agency-direct programs are listed once, compactly, under the cards.
  const direct = inCategory.filter(isAgencyDirect).sort((a, b) => a.name.localeCompare(b.name));
  const shown = inCategory
    .filter((s) => !isAgencyDirect(s))
    .filter((s) => !openOnly || s.availability === "open")
    .sort((a, b) => {
      if (sort === "amount") return Number(b.amount || 0) - Number(a.amount || 0);
      if (sort === "name") return a.name.localeCompare(b.name);
      return String(a.application_end || "9999").localeCompare(String(b.application_end || "9999"));
    });

  const countIn = (key) => scholarships.filter((s) => key === "all" || s.category === key).length;

  return (
    <div>
      <PageHeader title="Scholarships" subtitle="Programs that are open now or opening soon" />

      {grant && (
        <div className="alert alert-info">
          You are currently a grantee of <strong>{grant.scholarship?.name}</strong>. You cannot apply for a new
          scholarship at this time, but you can still view the available scholarships.
        </div>
      )}

      <div className="card filter-card">
        <div className="filter-row" role="group" aria-label="Program type">
          {[["all", "All"], ...Object.entries(CATEGORY_LABELS)].filter(([key]) => countIn(key) > 0).map(([key, label]) => (
            <button
              key={key}
              className={category === key ? "button button-small button-primary" : "button button-small button-secondary"}
              aria-pressed={category === key}
              onClick={() => setCategory(key)}
            >
              {label} ({countIn(key)})
            </button>
          ))}
        </div>

        <div className="inline-form">
          <label htmlFor="sort">Sort by</label>
          <select id="sort" value={sort} onChange={(event) => setSort(event.target.value)}>
            {Object.entries(SORTS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>

          <label className="choice">
            <input type="checkbox" checked={openOnly} onChange={(event) => setOpenOnly(event.target.checked)} />
            Open now only
          </label>
        </div>
      </div>

      {shown.length === 0 && direct.length === 0 && (
        <div className="card">
          <EmptyState message="No scholarships match these filters." />
        </div>
      )}

      <div className="card-grid">
        {shown.map((scholarship) => {
          const info = availabilityInfo(scholarship);
          const mine = applicationFor(scholarship.id);

          return (
            <div className="card scholarship-card" key={scholarship.id}>
              <div className="scholarship-card-top">
                <span className="badge">{categoryLabel(scholarship.category)}</span>
                <span className={`status status-${info.tone}`}>{info.label}</span>
              </div>

              <h2>{scholarship.name}</h2>

              <p className="muted">{scholarship.description || "Scholarship assistance program"}</p>

              <div className="amount">{formatMoney(scholarship.amount)}</div>

              <p>
                Provider: <strong>{scholarship.provider || "—"}</strong>
              </p>

              <p className="deadline">
                {deadlineText(scholarship)} · {scholarship.requirements?.length || 0} documents required
              </p>

              <div className="button-row">
                <Link className="button button-secondary" to={`/student/scholarships/${scholarship.id}`}>
                  Details
                </Link>

                {mine ? (
                  <Link className="button button-secondary" to={`/student/applications/${mine.id}`}>
                    View my application
                  </Link>
                ) : grant ? (
                  <button className="button button-primary" disabled title="You already hold an active scholarship">
                    Already a grantee
                  </button>
                ) : scholarship.availability === "open" ? (
                  <button
                    className="button button-primary"
                    onClick={() => apply(scholarship.id)}
                    disabled={applyingId === scholarship.id}
                  >
                    {applyingId === scholarship.id ? "Starting..." : "Apply"}
                  </button>
                ) : (
                  <button className="button button-primary" disabled>
                    Not open yet
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {direct.length > 0 && (
        <section className="card direct-list">
          <div className="card-header">
            <h2>Apply directly at the agency</h2>
          </div>
          <p className="muted small">
            These scholarships are agency-direct: submit your application to the scholarship-giving agency and transact
            requirements and allowances with its office. The OAS posts announcements and updates when the agency requests it.
          </p>
          <ul>
            {direct.map((scholarship) => (
              <li key={scholarship.id}>
                <div>
                  <strong>{scholarship.name}</strong>
                  <small className="muted">
                    {scholarship.provider} · {categoryLabel(scholarship.category)}
                  </small>
                </div>
                <Link className="button button-small button-secondary" to={`/student/scholarships/${scholarship.id}`}>
                  Details
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
