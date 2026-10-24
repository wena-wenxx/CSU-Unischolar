import { Link } from "react-router-dom";

/* Shortcut tiles on the dashboards: [{ to, label, text }] (round 12: no emoji icons, less noise). */
export default function QuickActions({ actions }) {
  return (
    <nav className="quick-tiles" aria-label="Quick actions">
      {actions.map((action) => (
        <Link key={action.label} className="quick-tile" to={action.to}>
          {action.icon && (
            <span className="quick-icon" aria-hidden="true">
              {action.icon}
            </span>
          )}
          <span>
            <strong>{action.label}</strong>
            <small>{action.text}</small>
          </span>
        </Link>
      ))}
    </nav>
  );
}
