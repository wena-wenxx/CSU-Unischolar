import { statusClass, statusLabel } from "../lib/format";

// Coloured pill for any status: "approved", "needs_action", "flagged", ...
export default function StatusBadge({ status, label }) {
  return <span className={statusClass(status)}>{label || statusLabel(status)}</span>;
}
