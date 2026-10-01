export default function StatusBadge({ status }) {
    const label = status
        ? status.replaceAll("_", " ")
        : "Unknown";

    return (
        <span
            className={`status-badge status-${status || "unknown"}`}
        >
            {label}
        </span>
    );
}