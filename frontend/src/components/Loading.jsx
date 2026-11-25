import { MOTTO } from "../lib/brand";

export default function Loading({ message = "Loading...", compact = false }) {
  if (compact) {
    return (
      <p className="loading-label" role="status">
        <span className="spinner spinner-small" aria-hidden="true" /> {message}
      </p>
    );
  }

  return (
    <div className="loading skeleton" role="status" aria-label={message}>
      <div className="skeleton-block skeleton-title" />
      <div className="skeleton-block skeleton-text" />
      <div className="skeleton-row">
        <div className="skeleton-block skeleton-card" />
        <div className="skeleton-block skeleton-card" />
        <div className="skeleton-block skeleton-card" />
        <div className="skeleton-block skeleton-card" />
      </div>
      <div className="skeleton-block skeleton-panel" />
      <span className="loading-label">
        <span className="spinner spinner-small" aria-hidden="true" /> {message}{" "}
        <small className="loading-motto">{MOTTO}</small>
      </span>
    </div>
  );
}
