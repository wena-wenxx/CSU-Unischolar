import { Link } from "react-router-dom";

/* Big shortcut buttons on the dashboards: [{ to, label, text, icon }] */
export default function QuickActions({ actions }) {
  return (
    <nav className="quick-tiles" aria-label="Quick actions">
      {actions.map((action) => (
        <Link key={action.label} className="quick-tile" to={action.to}>
          <span className="quick-icon" aria-hidden="true">
            {action.icon}
          </span>
          <span>
            <strong>{action.label}</strong>
            <small>{action.text}</small>
          </span>
        </Link>
      ))}
    </nav>
  );
}
