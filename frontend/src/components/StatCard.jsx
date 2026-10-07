import { Link } from "react-router-dom";

/*
  Dashboard number. With `to`, the whole card is a link to the matching list,
  e.g. <StatCard title="Needs Action" value={49} to="/staff/applications?filter=needs_action" />
*/
export default function StatCard({ title, value, to, hint }) {
  const body = (
    <>
      <span>{title}</span>
      <strong>{value}</strong>
      {to && <small className="stat-link">{hint || "View"} →</small>}
    </>
  );

  if (!to) return <div className="stat-card metric-card">{body}</div>;

  return (
    <Link className="stat-card metric-card stat-card-link" to={to} aria-label={`${title}: ${value}. ${hint || "View"}`}>
      {body}
    </Link>
  );
}
