import { Link } from "react-router-dom";
import Icon from "./Icon";
import { iconFor } from "../lib/icons";

export default function QuickActions({ actions }) {
  return (
    <nav className="quick-tiles" aria-label="Quick actions">
      {actions.map((action) => (
        <Link key={action.label} className="quick-tile" to={action.to}>
          <span className="quick-icon" aria-hidden="true">
            <Icon name={/^[a-z]+$/.test(action.icon || "") ? action.icon : iconFor(action.to)} />
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
