/*
  One search box for every list: magnifying glass, the text, a Search button and a Clear button.
  - Lists filtered as you type: leave out onSearch (the button just confirms).
  - Lists searched on the server: pass onSearch; Enter and the button both run it.
*/
export default function SearchField({ id, label, value, onChange, onSearch, placeholder, busy = false }) {
  function run(event) {
    event?.preventDefault();
    onSearch?.(value);
  }

  return (
    <div className="search-field" role="search">
      <label htmlFor={id} className="sr-only">
        {label}
      </label>
      <span className="search-field-box">
        <svg className="search-field-icon" viewBox="0 0 24 24" aria-hidden="true">
          <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" strokeWidth="2" />
          <path d="m20 20-3.5-3.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        </svg>
        <input
          id={id}
          type="search"
          value={value}
          placeholder={placeholder}
          onChange={(event) => onChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter") run(event);
          }}
        />
      </span>
      <button type="button" className="button button-small button-primary" onClick={run} disabled={busy}>
        {busy ? "Searching…" : "Search"}
      </button>
      {value && (
        <button
          type="button"
          className="button button-small button-secondary"
          onClick={() => {
            onChange("");
            onSearch?.("");
          }}
        >
          Clear
        </button>
      )}
    </div>
  );
}
