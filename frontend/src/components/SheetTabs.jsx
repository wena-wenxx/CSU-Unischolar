/*
  Excel-style sheet tabs above a table: one tab per scholarship program.
  tabs: [{ key, label, count, title }]   value: the active key
  The row scrolls sideways when there are many programs.
*/
export default function SheetTabs({ tabs, value, onChange, label = "Programs" }) {
  return (
    <div className="sheet-tabs" role="tablist" aria-label={label}>
      {tabs.map((tab) => (
        <button
          key={tab.key}
          type="button"
          role="tab"
          aria-selected={value === tab.key}
          className={`sheet-tab${value === tab.key ? " active" : ""}${tab.key === "all" ? " pinned" : ""}`}
          title={tab.title || tab.label}
          onClick={() => onChange(tab.key)}
        >
          <span>{tab.label}</span>
          {tab.count !== undefined && <small>{tab.count}</small>}
        </button>
      ))}
    </div>
  );
}
