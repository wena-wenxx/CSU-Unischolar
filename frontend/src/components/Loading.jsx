import { MOTTO } from "../lib/brand";

/*
  Shown while a page gets its data: a grey outline of the page (title, number cards, a panel) that
  shimmers, so the page does not jump when the content arrives. compact = only the small label
  (inside a card or a window).
*/
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
