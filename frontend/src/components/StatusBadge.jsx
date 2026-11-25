import { statusClass, statusLabel } from "../lib/format";

export default function StatusBadge({ status, label }) {
  return <span className={statusClass(status)}>{label || statusLabel(status)}</span>;
}
