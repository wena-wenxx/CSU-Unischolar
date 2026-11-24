import { Link } from "react-router-dom";
import Icon from "./Icon";
import { iconFor } from "../lib/icons";

/*
  Shortcut tiles on the dashboards: [{ to, label, text, icon? }].
  The icon is a line icon from components/Icon.jsx: by default the one of the page the tile opens
  (an older emoji icon value is ignored, so every tile matches the menu).
*/
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
