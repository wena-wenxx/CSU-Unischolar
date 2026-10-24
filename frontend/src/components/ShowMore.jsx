/* "Showing 25 of 310 · Show 25 more · Show all" under a long table (see lib/paging.js). */
export default function ShowMore({ paged }) {
  if (!paged.remaining) return null;

  return (
    <div className="show-more">
      <span className="muted small">
        Showing {paged.total - paged.remaining} of {paged.total}
      </span>
      <button type="button" className="button button-secondary button-small" onClick={paged.showMore}>
        Show {Math.min(paged.size, paged.remaining)} more
      </button>
      <button type="button" className="link-button small" onClick={paged.showAll}>
        Show all
      </button>
    </div>
  );
}
