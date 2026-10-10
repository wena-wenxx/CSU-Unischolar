import { useState } from "react";

/*
  Choose a scholarship's required documents from the standard list
  (the same nine types students keep in My Documents), with a search box,
  plus custom requirements typed by hand.

  value:    [{ name, is_required, description }]
  onChange: called with the new array
  types:    [{ name, validity_months }] from GET /requirement-types
  exclude:  names already on the program (hidden from the list)

  Why the standard list matters: a requirement with a standard name can be
  filled with a file the student already saved, and it gets an expiry date.
  A custom name cannot.
*/
export default function RequirementPicker({ value, onChange, types, exclude = [], idPrefix = "req" }) {
  const [query, setQuery] = useState("");
  const [custom, setCustom] = useState("");

  const chosen = (name) => value.find((item) => item.name.toLowerCase() === name.toLowerCase());
  const standardNames = types.map((type) => type.name.toLowerCase());
  const available = types.filter((type) => !exclude.some((name) => name.toLowerCase() === type.name.toLowerCase()));
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  const shown = available.filter((type) => words.every((word) => type.name.toLowerCase().includes(word)));
  const customItems = value.filter((item) => !standardNames.includes(item.name.toLowerCase()));

  function toggle(name) {
    if (chosen(name)) onChange(value.filter((item) => item.name.toLowerCase() !== name.toLowerCase()));
    else onChange([...value, { name, is_required: true, description: "" }]);
  }

  function update(name, changes) {
    onChange(value.map((item) => (item.name === name ? { ...item, ...changes } : item)));
  }

  function addCustom() {
    const name = custom.trim().replace(/\s+/g, " ");
    if (!name) return;

    // Typed a standard name? Tick it instead of making a custom copy.
    const standard = types.find((type) => type.name.toLowerCase() === name.toLowerCase());
    const finalName = standard ? standard.name : name;

    const taken = chosen(finalName) || exclude.some((existing) => existing.toLowerCase() === finalName.toLowerCase());
    if (!taken) onChange([...value, { name: finalName, is_required: true, description: "" }]);
    setCustom("");
  }

  const validity = (months) => (months ? `valid ${months === 12 ? "1 year" : `${months} months`}` : "no expiry");

  return (
    <div className="req-picker">
      <div className="req-picker-top">
        <label htmlFor={`${idPrefix}-search`} className="sr-only">
          Search document types
        </label>
        <input
          id={`${idPrefix}-search`}
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search documents (e.g. grades, ID)"
        />
        <button
          type="button"
          className="button button-small button-secondary"
          onClick={() =>
            onChange([
              ...value,
              ...shown.filter((type) => !chosen(type.name)).map((type) => ({ name: type.name, is_required: true, description: "" })),
            ])
          }
          disabled={shown.every((type) => chosen(type.name))}
        >
          Select all shown
        </button>
        <span className="muted small">{value.length} selected</span>
      </div>

      {available.length === 0 ? (
        <p className="muted small">Every standard document is already on this program.</p>
      ) : shown.length === 0 ? (
        <p className="muted small">No standard document matches “{query}”. You can add it as a custom requirement below.</p>
      ) : (
        <ul className="req-list">
          {shown.map((type) => {
            const item = chosen(type.name);
            const id = `${idPrefix}-${type.name.replace(/[^a-z0-9]+/gi, "-")}`;
            return (
              <li key={type.name} className={item ? "req-row selected" : "req-row"}>
                <label className="req-check" htmlFor={id}>
                  <input id={id} type="checkbox" checked={Boolean(item)} onChange={() => toggle(type.name)} />
                  <span>
                    {type.name}
                    <small className="muted"> · {validity(type.validity_months)}</small>
                  </span>
                </label>

                {item && (
                  <RowOptions item={item} onUpdate={(changes) => update(item.name, changes)} idPrefix={id} />
                )}
              </li>
            );
          })}
        </ul>
      )}

      <div className="req-custom">
        <label htmlFor={`${idPrefix}-custom`}>Other requirement (not on the list)</label>
        <div className="inline-form">
          <input
            id={`${idPrefix}-custom`}
            value={custom}
            onChange={(event) => setCustom(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                addCustom();
              }
            }}
            placeholder="e.g. Essay: Why I deserve this scholarship"
            maxLength={255}
          />
          <button type="button" className="button button-small button-secondary" onClick={addCustom} disabled={!custom.trim()}>
            Add
          </button>
        </div>
      </div>

      {customItems.length > 0 && (
        <ul className="req-list">
          {customItems.map((item) => (
            <li key={item.name} className="req-row selected custom">
              <span className="req-check">
                <span>
                  {item.name} <span className="status status-neutral">Custom</span>
                  <small className="muted"> · students upload it each time; no expiry</small>
                </span>
              </span>
              <RowOptions
                item={item}
                onUpdate={(changes) => update(item.name, changes)}
                idPrefix={`${idPrefix}-custom-${item.name.replace(/[^a-z0-9]+/gi, "-")}`}
                onRemove={() => onChange(value.filter((other) => other.name !== item.name))}
              />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function RowOptions({ item, onUpdate, onRemove, idPrefix }) {
  return (
    <div className="req-options">
      <label htmlFor={`${idPrefix}-required`} className="sr-only">
        Required or optional
      </label>
      <select
        id={`${idPrefix}-required`}
        value={item.is_required ? "required" : "optional"}
        onChange={(event) => onUpdate({ is_required: event.target.value === "required" })}
      >
        <option value="required">Required</option>
        <option value="optional">Optional</option>
      </select>

      <label htmlFor={`${idPrefix}-note`} className="sr-only">
        Note for students
      </label>
      <input
        id={`${idPrefix}-note`}
        value={item.description || ""}
        onChange={(event) => onUpdate({ description: event.target.value })}
        placeholder="Note for students (optional)"
        maxLength={1000}
      />

      {onRemove && (
        <button type="button" className="link-button small" onClick={onRemove}>
          Remove
        </button>
      )}
    </div>
  );
}
