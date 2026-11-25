import { Link } from "react-router-dom";
import Icon from "./Icon";
import { iconFor } from "../lib/icons";

export default function StatCard({ title, value, to, hint, icon, attention = false }) {
  const name = icon || iconFor(to || title.toLowerCase());
  const busy = attention && Number(value) > 0;
  const body = (
    <>
      <span>{title}</span>
      <strong>{value}</strong>
      <i className="stat-icon" aria-hidden="true">
        <Icon name={name} />
      </i>
      {to && <small className="stat-link">{hint || "View"} →</small>}
    </>
  );

  if (!to) return <div className={`stat-card metric-card${busy ? " has-value" : ""}`}>{body}</div>;

  return (
    <Link
      className={`stat-card metric-card stat-card-link${busy ? " has-value" : ""}`}
      to={to}
      aria-label={`${title}: ${value}. ${hint || "View"}`}
    >
      {body}
    </Link>
  );
}
