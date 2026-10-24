import { useEffect, useRef } from "react";

/*
  One "Export" button that opens a short list of formats, instead of a row
  of export buttons. options: [{ label, hint, onClick }]
*/
export default function ExportMenu({ label = "Export", count, options, disabled }) {
  const ref = useRef(null);

  // Close when clicking elsewhere or pressing Escape.
  useEffect(() => {
    function close(event) {
      if (ref.current?.open && (event.key === "Escape" || (event.type === "mousedown" && !ref.current.contains(event.target)))) {
        ref.current.open = false;
      }
    }
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", close);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", close);
    };
  }, []);

  if (disabled) {
    return (
      <button className="button button-secondary" disabled>
        {label}
      </button>
    );
  }

  return (
    <details className="export-menu" ref={ref}>
      <summary className="button button-secondary">
        {label}
        {count !== undefined ? ` (${count})` : ""} <span aria-hidden="true">▾</span>
      </summary>
      <div className="export-menu-list" role="menu">
        {options.map((option) => (
          <button
            key={option.label}
            role="menuitem"
            onClick={() => {
              ref.current.open = false;
              option.onClick();
            }}
          >
            <strong>{option.label}</strong>
            {option.hint && <small>{option.hint}</small>}
          </button>
        ))}
      </div>
    </details>
  );
}
